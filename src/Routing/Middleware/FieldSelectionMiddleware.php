<?php

declare(strict_types=1);

namespace Glueful\Routing\Middleware;

use Glueful\Routing\RouteMiddleware;
use Glueful\Support\FieldSelection\Parsers\GraphQLProjectionParser;
use Glueful\Support\FieldSelection\Parsers\RestProjectionParser;
use Glueful\Support\FieldSelection\{FieldSelector, FieldTree, Projector, FieldNode};
use Glueful\Support\FieldSelection\Exceptions\InvalidFieldSelectionException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class FieldSelectionMiddleware implements RouteMiddleware
{
    public function __construct(
        private readonly Projector $projector
    ) {
    }

    /**
     * Options supported via route metadata or config:
     * - strict (bool)
     * - whitelistKey (string) or allowed (string[])
     * - maxDepth, maxFields, maxItems (ints)
     */
    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        try {
            // Fast path: no params?bypass
            $fields = $request->query->get('fields');
            $expand = $request->query->get('expand');
            if ($fields === null && $expand === null) {
                return $next($request);
            }

            // Parse both syntaxes:
            $tree = $this->parseCombined((string)$fields, (string)$expand);

            // Read per-route hints (set by attributes or router metadata; fall back to config)
            $route = $request->attributes->get('_route'); // depends on Glueful's request setup
            $routeFieldsConfig = [];

            // Get fields config from Route object if available
            if ($route instanceof \Glueful\Routing\Route) {
                $fieldsConfig = $route->getFieldsConfig();
                if ($fieldsConfig !== null) {
                    $routeFieldsConfig = $fieldsConfig;
                }
            } elseif (\is_array($route)) {
                // Legacy array-based route config
                $routeFieldsConfig = $route['fields'] ?? [];
            }

            $opts = \array_merge($this->defaults(), $routeFieldsConfig);

            $selector = new FieldSelector(
                tree: $tree,
                strict: (bool)($opts['strict'] ?? false),
                maxDepth: (int)($opts['maxDepth'] ?? 6),
                maxFields: (int)($opts['maxFields'] ?? 200),
                maxItems: (int)($opts['maxItems'] ?? 1000),
            );

            // Stash for controllers/expanders DI
            $request->attributes->set(FieldSelector::class, $selector);

            // Continue pipeline
            $response = $next($request);

            // Only project JSON-ish responses we control; skip binaries/streams, etc.
            if (!$response instanceof Response) {
                /** @var Response */
                return $response;
            }
            $ctype = $response->headers->get('Content-Type', '');
            if (!str_contains($ctype, 'application/json')) {
                return $response;
            }

            // Decode, project, re-encode
            $payload = json_decode((string)$response->getContent(), true);
            if ($payload === null) {
                return $response;
            }

            $allowed = \is_array($opts['allowed'] ?? null) ? $opts['allowed'] : null;
            $key     = \is_string($opts['whitelistKey'] ?? null) ? $opts['whitelistKey'] : null;

            // Build comprehensive context for expanders
            $context = $this->buildContext($request, $payload);

            $projected = $this->projector->project($payload, $selector, $allowed, $key, $context);
            $response->setContent((string)json_encode($projected));

            // Optional debug header (guarded)
            if ($request->query->getBoolean('fields_debug')) {
                $encodedTree = json_encode($this->previewTree($tree));
                $treePreview = substr($encodedTree !== false ? $encodedTree : '{}', 0, 1024);
                $response->headers->set('X-Fields-Tree', $treePreview);
            }

            return $response;
        } catch (InvalidFieldSelectionException $e) {
            return $e->toResponse();
        }
    }

    private function parseCombined(?string $fields, ?string $expand): FieldTree
    {
        // If it "looks" GraphQL-ish (has '(' and ')'), prefer GraphQL parser
        if ($fields !== null && $fields !== '' && str_contains($fields, '(')) {
            return (new GraphQLProjectionParser())->parse($fields);
        }
        // Else merge REST fields + expand
        return (new RestProjectionParser())->parse($fields, $expand);
    }

    /** @return array<string,mixed> */
    private function defaults(): array
    {
        // Pull from config('api.field_selection') with fallbacks
        $cfg = \function_exists('config') ? (array)\config('api.field_selection', []) : [];
        return array_merge([
            'strict' => false,
            'maxDepth' => 6,
            'maxFields' => 200,
            'maxItems' => 1000,
            'whitelistKey' => null,
            'allowed' => null,
        ], $cfg);
    }

    /**
     * Build comprehensive context for expanders
     *
     * @param array<string,mixed>|null $payload
     * @return array<string,mixed>
     */
    private function buildContext(Request $request, ?array $payload): array
    {
        $context = [
            'route' => $request->attributes->get('_route'),
            'route_params' => $request->attributes->get('_route_params', []),
            'user' => $request->attributes->get('user'),
            'request_id' => $request->headers->get('X-Request-Id'),
            'method' => $request->getMethod(),
            'uri' => $request->getRequestUri(),
        ];

        // Extract collection IDs for batch loading
        if (is_array($payload)) {
            $context['collection_ids'] = $this->extractCollectionIds($payload);
        }

        return $context;
    }

    /**
     * Extract IDs from payload for batch loading in expanders
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function extractCollectionIds(array $payload): array
    {
        $ids = [];

        // Single item with ID
        if (isset($payload['id'])) {
            $ids['item_ids'] = [$payload['id']];
            $ids['single_item'] = true;
        } elseif (array_is_list($payload)) {
            $ids['item_ids'] = array_filter(array_column($payload, 'id'));
            $ids['is_collection'] = true;
            $ids['collection_size'] = count($payload);
        } elseif (isset($payload['data']) && is_array($payload['data'])) {
            $ids['item_ids'] = array_filter(array_column($payload['data'], 'id'));
            $ids['is_paginated'] = true;
            $ids['page'] = $payload['current_page'] ?? $payload['page'] ?? null;
            $ids['per_page'] = $payload['per_page'] ?? null;
            $ids['total'] = $payload['total'] ?? null;
        }

        return $ids;
    }

    /** @return array<string,mixed> */
    private function previewTree(FieldTree $tree): array
    {
        $walk = function (FieldNode $n) use (&$walk) {
            $out = [];
            foreach ($n->children() as $c) {
                $out[$c->name] = $walk($c);
            }
            return $out;
        };
        $root = [];
        foreach ($tree->roots() as $k => $n) {
            $root[$k] = $walk($n);
        }
        return $root;
    }
}

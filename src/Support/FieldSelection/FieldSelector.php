<?php

declare(strict_types=1);

namespace Glueful\Support\FieldSelection;

use Glueful\Support\FieldSelection\Parsers\GraphQLProjectionParser;
use Glueful\Support\FieldSelection\Parsers\RestProjectionParser;
use Glueful\Support\FieldSelection\Performance\{FieldSelectionMetrics, FieldTreeCache};
use Symfony\Component\HttpFoundation\Request;

/**
 * Lightweight value-object exposed to controllers (DI-injectable).
 * Wraps the parsed FieldTree + options for guards and DX helpers.
 */
final class FieldSelector
{
    public function __construct(
        public readonly FieldTree $tree,
        public readonly bool $strict = false,
        public readonly int $maxDepth = 6,
        public readonly int $maxFields = 200,
        public readonly int $maxItems = 1000
    ) {
    }

    /**
     * Factory method to create FieldSelector from HTTP request
     *
     * @param array<string>|null $whitelist Optional field whitelist
     */
    public static function fromRequest(
        Request $request,
        bool $strict = false,
        int $maxDepth = 6,
        int $maxFields = 200,
        int $maxItems = 1000,
        ?array $whitelist = null
    ): self {
        $metrics = FieldSelectionMetrics::getInstance();
        $cache = new FieldTreeCache();

        $fields = $request->query->get('fields');
        $expand = $request->query->get('expand');

        // Fast path: no field selection
        if ($fields === null && $expand === null) {
            return new self(FieldTree::empty(), $strict, $maxDepth, $maxFields, $maxItems);
        }

        // Generate cache key
        $cacheKey = FieldTreeCache::generateKey((string)$fields, (string)$expand, $whitelist ?? []);

        // Try cache first
        $cachedTree = $cache->get($cacheKey);
        if ($cachedTree !== null) {
            $metrics->increment('field_parsing_cache_hits');
            return new self($cachedTree, $strict, $maxDepth, $maxFields, $maxItems);
        }

        // Start performance monitoring
        $metrics->startTimer('field_parsing');
        $startMemory = memory_get_usage(true);

        // Parse based on syntax detection
        // GraphQL style uses nested parentheses like: user(id,name,posts(title))
        // REST style with transformations uses: id,name:format(Y-m-d),price:currency(USD)
        $isGraphQLStyle = $fields !== null && $fields !== '' &&
                         preg_match('/\w+\s*\([^)]*\s*\w+\s*\([^)]*\)/', (string)$fields);

        if ($isGraphQLStyle) {
            // GraphQL-style syntax detected
            $tree = (new GraphQLProjectionParser())->parse((string)$fields);
        } else {
            // REST-style syntax (including transformations with parentheses)
            $tree = (new RestProjectionParser())->parse((string)$fields, (string)$expand);
        }

        // Apply whitelist if provided
        if ($whitelist !== null && count($whitelist) > 0) {
            $tree = $tree->applyWhitelist($whitelist);
        }

        // End performance monitoring
        $duration = $metrics->endTimer('field_parsing');
        $memoryUsed = memory_get_usage(true) - $startMemory;
        $fieldCount = count($tree->roots());

        // Record parsing metrics
        $metrics->recordParsingMetrics((string)$fields . '|' . (string)$expand, $fieldCount, $duration, $memoryUsed);

        // Cache the result
        $cache->put($cacheKey, $tree);
        $metrics->increment('field_parsing_cache_misses');

        // Check for slow patterns
        if ($duration > 100) { // 100ms threshold
            $metrics->recordSlowPattern((string)$fields . '|' . (string)$expand, $duration, [
                'field_count' => $fieldCount,
                'whitelist_size' => $whitelist !== null ? count($whitelist) : 0,
                'is_graphql_style' => $isGraphQLStyle
            ]);
        }

        return new self($tree, $strict, $maxDepth, $maxFields, $maxItems);
    }

    /**
     * Factory method with advanced whitelist patterns and context
     *
     * @param array<string>|null $whitelist Advanced patterns like ['posts.*', 'email:if(owner)', '*,-password']
     * @param array<string,mixed> $context User context for conditional evaluation
     * @param array<string,mixed> $data Data context for conditional evaluation
     */
    public static function fromRequestAdvanced(
        Request $request,
        ?array $whitelist = null,
        array $context = [],
        array $data = [],
        bool $strict = false,
        int $maxDepth = 6,
        int $maxFields = 200,
        int $maxItems = 1000
    ): self {
        $fields = $request->query->get('fields');
        $expand = $request->query->get('expand');

        // Fast path: no field selection
        if ($fields === null && $expand === null) {
            return new self(FieldTree::empty(), $strict, $maxDepth, $maxFields, $maxItems);
        }

        // Parse based on syntax detection
        $isGraphQLStyle = $fields !== null && $fields !== '' &&
                         preg_match('/\w+\s*\([^)]*\s*\w+\s*\([^)]*\)/', (string)$fields);

        if ($isGraphQLStyle) {
            $tree = (new GraphQLProjectionParser())->parse((string)$fields);
        } else {
            $tree = (new RestProjectionParser())->parse((string)$fields, (string)$expand);
        }

        // Apply advanced whitelist if provided
        if ($whitelist !== null && count($whitelist) > 0) {
            $tree = $tree->applyAdvancedWhitelist($whitelist, $context, $data);
        }

        return new self($tree, $strict, $maxDepth, $maxFields, $maxItems);
    }

    public function empty(): bool
    {
        return $this->tree->isEmpty();
    }

    public function requested(string $dotPath): bool
    {
        return $this->tree->requested($dotPath);
    }
}

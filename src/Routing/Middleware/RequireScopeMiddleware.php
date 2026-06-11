<?php

declare(strict_types=1);

namespace Glueful\Routing\Middleware;

use Glueful\Auth\ApiKey\ApiKeyService;
use Glueful\Auth\ApiKey\Exceptions\InsufficientScopeException;
use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces #[RequireScope] declarations on routes.
 *
 * Reads the route's scope config (set by AttributeRouteLoader) and the
 * authenticated key's scopes (set by ApiKeyAuthenticationProvider into
 * the 'api_key_scopes' request attribute). For each attribute entry, at
 * least one of its scopes must satisfy a granted scope (OR within). Every
 * attribute entry must independently pass (AND across attributes).
 */
final class RequireScopeMiddleware implements RouteMiddleware
{
    /**
     * @param mixed ...$params
     */
    public function handle(Request $request, callable $next, ...$params): Response
    {
        $route = $request->attributes->get('_route');
        $config = ($route !== null && method_exists($route, 'getRequireScopeConfig'))
            ? $route->getRequireScopeConfig()
            : [];

        if ($config === []) {
            return $next($request);
        }

        // A #[RequireScope] route requires scope-bearing (API-key) authentication. If the
        // request carries no api_key_scopes attribute at all, it was not authenticated via
        // a path that grants scopes (e.g. a JWT request) -- deny, rather than letting the
        // "empty scopes = unrestricted key" rule treat the absence as full access.
        if (!$request->attributes->has('api_key_scopes')) {
            throw new InsufficientScopeException(
                'Insufficient scope: this route requires a scoped API key'
            );
        }

        $granted = $request->attributes->get('api_key_scopes', []);
        if (!is_array($granted)) {
            $granted = [];
        }
        /** @var array<int, string> $grantedScopes */
        $grantedScopes = array_values(array_filter($granted, 'is_string'));

        foreach ($config as $requiredAnyOf) {
            $satisfied = false;
            foreach ($requiredAnyOf as $required) {
                if (ApiKeyService::scopeSatisfies($grantedScopes, $required)) {
                    $satisfied = true;
                    break;
                }
            }
            if (!$satisfied) {
                throw new InsufficientScopeException(sprintf(
                    'Insufficient scope: required any of [%s]',
                    implode(', ', $requiredAnyOf)
                ));
            }
        }

        return $next($request);
    }
}

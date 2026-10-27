<?php

declare(strict_types=1);

namespace Glueful\Routing\Middleware;

use Glueful\Auth\ApiKey\ApiKeyService;
use Glueful\Auth\ApiKey\Exceptions\InsufficientScopeException;
use Glueful\Auth\UserIdentity;
use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces #[RequireScope] declarations on routes.
 *
 * Reads the route's scope config (set by AttributeRouteLoader) and the
 * request's granted scopes: an API key's (the 'api_key_scopes' attribute set by
 * ApiKeyAuthenticationProvider) or, for JWT/identity auth, the scopes() of the
 * 'auth.user' UserIdentity set by AuthMiddleware. For each attribute entry, at
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

        // Fall back to middleware params for file-defined routes that declare their required scope
        // as a middleware argument (e.g. ->middleware('require_scope:read:content')) rather than a
        // #[RequireScope] attribute. The listed scopes form a single any-of group; enforcement below
        // is fail-closed (an unauthenticated request is denied, not waved through).
        if ($config === []) {
            $paramScopes = array_values(array_filter(
                array_map(static fn ($p): string => (string) $p, $params),
                static fn (string $s): bool => $s !== '',
            ));
            if ($paramScopes !== []) {
                $config = [$paramScopes];
            }
        }

        if ($config === []) {
            return $next($request);
        }

        $grantedScopes = $this->grantedScopes($request);
        if ($grantedScopes === null) {
            throw new InsufficientScopeException(
                'Insufficient scope: this route requires a scoped credential'
            );
        }

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

    /**
     * The scopes the request was granted, or null when it carries no scope-bearing credential.
     *
     * - API key: the `api_key_scopes` attribute is authoritative whenever present (API-key
     *   requests also carry a synthesised `auth.user`, which must not widen the key). An empty
     *   set is an unrestricted key and stays [], which ApiKeyService::scopeSatisfies() accepts.
     * - JWT / identity: the `auth.user` UserIdentity's scopes(). Unlike a key, an EMPTY identity
     *   scope set grants nothing, so it maps to null (denied) rather than [] (unrestricted).
     * - Neither (unauthenticated): null, denied — absence is never treated as full access.
     *
     * @return array<int, string>|null
     */
    private function grantedScopes(Request $request): ?array
    {
        if ($request->attributes->has('api_key_scopes')) {
            $granted = $request->attributes->get('api_key_scopes', []);

            return array_values(array_filter(is_array($granted) ? $granted : [], 'is_string'));
        }

        $identity = $request->attributes->get('auth.user');
        if ($identity instanceof UserIdentity) {
            $scopes = array_values(array_filter($identity->scopes(), 'is_string'));

            return $scopes === [] ? null : $scopes;
        }

        return null;
    }
}

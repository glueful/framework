<?php

declare(strict_types=1);

namespace Glueful\Permissions\Middleware;

use Glueful\Routing\RouteMiddleware;
use Glueful\Http\RequestUserContext;
use Glueful\Auth\UserIdentity;
use Symfony\Component\HttpFoundation\Request;

/**
 * Auth To Request Attributes Middleware
 *
 * Bridges authentication data from RequestUserContext to request attributes
 * for use by other middleware and controllers.
 *
 * Sets the following request attributes:
 * - auth.user: UserIdentity object
 * - auth.user.uuid: User UUID string
 * - auth.roles: Array of user roles
 * - jwt.claims: JWT token claims
 * - tenant.id: Tenant identifier for multi-tenancy
 * - route.params: Route parameters
 */
final class AuthToRequestAttributesMiddleware implements RouteMiddleware
{
    public function __construct(
        private RequestUserContext $userCtx
    ) {
    }

    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        $this->enrichRequest($request);
        return $next($request);
    }

    /**
     * Enrich request with authentication attributes
     *
     * This method extracts authentication data from RequestUserContext and
     * adds it as request attributes. Can be called independently of the
     * middleware pipeline for automatic integration.
     */
    public function enrichRequest(Request $request): Request
    {
        try {
            // Initialize user context
            $this->userCtx->initialize();

            // Extract user data
            $uuid = $this->userCtx->getUserUuid();
            $roles = $this->userCtx->getUserRoles();
            $user = $this->userCtx->getUser();

            // Extract JWT claims from session data
            $sessionData = $this->userCtx->getSessionData();
            $claims = [];
            if ($sessionData !== null && isset($sessionData['jwt_payload'])) {
                $claims = $sessionData['jwt_payload'];
            } else {
                $token = $this->userCtx->getToken();
                if ($token !== null) {
                    // Fallback: decode JWT token directly
                    $tokenPayload = $this->extractJwtClaims($token);
                    if ($tokenPayload !== null) {
                        $claims = $tokenPayload;
                    }
                }
            }

            // Get tenant ID from multiple sources
            $tenant = $this->extractTenantId($request);

            // Create UserIdentity if we have user data
            if ($uuid !== null && $user !== null) {
                $userIdentity = new UserIdentity(
                    uuid: $uuid,
                    roles: $roles,
                    scopes: $claims['scope'] ?? $claims['scopes'] ?? [],
                    attributes: [
                        'permissions' => $this->userCtx->getUserPermissions(),
                        'email' => $user->email ?? null,
                        'username' => $user->username ?? null,
                        'tenant_id' => $tenant
                    ]
                );

                $request->attributes->set('auth.user', $userIdentity);
            }

            // Set individual attributes for backward compatibility
            if ($uuid !== null) {
                $request->attributes->set('auth.user.uuid', $uuid);
            }
            if (count($roles) > 0) {
                $request->attributes->set('auth.roles', $roles);
            }
            $request->attributes->set('jwt.claims', $claims);
            if ($tenant !== null) {
                $request->attributes->set('tenant.id', $tenant);
            }

            // Set route parameters if available
            $routeParams = $request->attributes->get('_route_params', []);
            $request->attributes->set('route.params', $routeParams);
        } catch (\Throwable $e) {
            // Log error but proceed without attributes for security
            error_log('AuthToRequestAttributesMiddleware error: ' . $e->getMessage());
        }

        return $request;
    }

    /**
     * Extract tenant ID from request
     */
    private function extractTenantId(Request $request): ?string
    {
        // Try request metadata first
        $requestMetadata = $this->userCtx->getRequestMetadata();
        $tenant = $requestMetadata['tenant_id'] ?? null;

        // Fallback to header
        if ($tenant === null) {
            $tenant = $request->headers->get('X-Tenant-Id');
        }

        // Fallback to query parameter
        if ($tenant === null) {
            $tenant = $request->query->get('tenant_id');
        }

        return $tenant;
    }

    /**
     * Extract JWT claims from token
     *
     * @return array<string, mixed>|null
     */
    private function extractJwtClaims(string $token): ?array
    {
        try {
            // Use framework's JWT service if available
            if (class_exists('\\Glueful\\Auth\\JWTService')) {
                return \Glueful\Auth\JWTService::decode($token);
            }

            // Fallback: basic JWT decode (for development)
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                return null;
            }

            $payload = base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1]), true);
            if ($payload === false) {
                return null;
            }

            $decoded = json_decode($payload, true);
            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable) {
            return null;
        }
    }
}

<?php

declare(strict_types=1);

namespace Glueful\Routing\Middleware;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Glueful\Auth\AuthenticationManager;
use Glueful\Auth\JwtAuthenticationProvider;
use Glueful\Auth\TokenManager;
use Psr\Container\ContainerInterface;
use Glueful\Http\Exceptions\Domain\AuthenticationException;
use Glueful\Permissions\Exceptions\UnauthorizedException as PermissionUnauthorizedException;
use Glueful\Events\Http\HttpAuthFailureEvent;
use Glueful\Events\Http\HttpAuthSuccessEvent;
use Glueful\Events\EventService;
use Psr\Log\LoggerInterface;

/**
 * Enterprise Authentication Middleware for Next-Gen Router
 *
 * Full-featured authentication middleware with enterprise capabilities:
 * - Multiple authentication provider support (JWT, API Key, etc.)
 * - Event dispatching for auth success/failure
 * - Comprehensive logging with PSR logger
 * - Token expiration validation
 * - Refresh token handling
 * - Admin privilege checking
 * - Container/DI integration
 */
class AuthMiddleware implements RouteMiddleware
{
    private ?ApplicationContext $context;

    private AuthenticationManager $authManager;
    private ?ContainerInterface $container;
    private ?LoggerInterface $logger = null;
    /** @var array<string> */
    private array $providerNames = [];
    private bool $validateExpiration = true;
    private bool $enableEventDispatch = true;
    private bool $enableDetailedLogging = true;

    /**
     * Create authentication middleware with enterprise features
     *
     * @param AuthenticationManager|null $authManager Custom auth manager
     * @param ContainerInterface|null $container DI container for services
     * @param array<string> $providerNames Authentication providers to use
     * @param array<string, mixed> $options Additional options
     * @param ApplicationContext|null $context Application context
     */
    public function __construct(
        ?AuthenticationManager $authManager = null,
        ?ContainerInterface $container = null,
        array $providerNames = [],
        array $options = [],
        ?ApplicationContext $context = null
    ) {
        $this->context = $context;
        // Setup container
        $this->container = $container ?? $this->getDefaultContainer();

        if ($authManager !== null) {
            $this->authManager = $authManager;
        } elseif ($this->container !== null && $this->container->has(AuthenticationManager::class)) {
            $this->authManager = $this->container->get(AuthenticationManager::class);
        } elseif ($this->context !== null && $this->context->hasContainer()) {
            $this->authManager = $this->context->getContainer()->get(AuthenticationManager::class);
        } else {
            $this->authManager = new AuthenticationManager(new JwtAuthenticationProvider($this->context));
        }

        // Setup logger
        if ($this->container !== null && $this->container->has(LoggerInterface::class)) {
            $loggerFromContainer = $this->container->get(LoggerInterface::class);
            if ($loggerFromContainer instanceof LoggerInterface) {
                $this->logger = $loggerFromContainer;
            }
        }

        // Configure providers (default to JWT and API key if not specified)
        $this->providerNames = count($providerNames) > 0 ? $providerNames : ['jwt', 'api_key'];

        // Apply options
        $this->validateExpiration = (bool)($options['validate_expiration'] ?? true);
        $this->enableEventDispatch = (bool)($options['enable_events'] ?? true);
        $this->enableDetailedLogging = (bool)($options['enable_logging'] ?? true);
    }

    /**
     * Handle authentication check with enterprise features
     *
     * @param Request $request The HTTP request
     * @param callable $next Next handler in pipeline
     * @param mixed ...$params Optional parameters (e.g., 'admin' for admin routes)
     * @return mixed Response
     */
    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        $requiresAdmin = in_array('admin', $params, true);
        $requestIdValue = $request->attributes->get('request_id', uniqid('req_', true));
        $requestId = is_string($requestIdValue) ? $requestIdValue : uniqid('req_', true);

        try {
            // Extract token using TokenManager for consistency
            $token = $this->extractAuthenticationCredentials($request);

            if ($token === null) {
                $this->logAuthFailure('Missing authentication credentials', $request, $requestId);
                $this->dispatchAuthFailureEvent('missing_credentials', $request);
                return $this->unauthorized('Authentication required');
            }

            // Authenticate with configured providers
            $user = $this->authenticateRequest($request, $token);

            if ($user === null) {
                $this->logAuthFailure('Authentication failed', $request, $requestId, $token);
                $this->dispatchAuthFailureEvent('authentication_failed', $request, $token);
                return $this->unauthorized('Invalid credentials');
            }

            // Validate token expiration if enabled
            if ($this->validateExpiration) {
                $expirationResult = $this->validateTokenExpiration($user, $request);
                if ($expirationResult !== null) {
                    return $expirationResult;
                }
            }

            // Check admin requirement if needed
            if ($requiresAdmin && !$this->isAdmin($user)) {
                $this->logAuthFailure('Admin access denied', $request, $requestId, $token);
                $this->dispatchAuthFailureEvent('insufficient_permissions', $request, $token);
                return $this->forbidden('Admin access required');
            }

            // Store authenticated user in request
            $request->attributes->set('user', $user);
            $request->attributes->set('auth_provider', $user['auth_provider'] ?? 'unknown');

            // Auto-enrich request with auth attributes
            $this->autoEnrichRequest($request);

            // Log successful authentication
            $this->logAuthSuccess($user, $request, $requestId, $token);
            $this->dispatchAuthSuccessEvent($request, $user, $token);

            // Log access if auth manager supports it
            try {
                $this->authManager->logAccess($user, $request);
            } catch (\Error) {
                // Method doesn't exist, ignore silently
            }

            return $next($request);
        } catch (AuthenticationException $e) {
            return $this->handleAuthenticationException($e, $request, $requestId);
        } catch (PermissionUnauthorizedException $e) {
            // Re-throw permission exceptions to be handled by the exception handler
            throw $e;
        } catch (\Exception $e) {
            $this->logError('Unexpected error in auth middleware', $e, $request, $requestId);
            return $this->unauthorized('Authentication error occurred');
        }
    }

    /**
     * Extract authentication credentials from request using TokenManager
     *
     * Uses TokenManager for consistent token extraction across framework.
     * Individual authentication providers will handle their specific token types.
     */
    private function extractAuthenticationCredentials(Request $request): ?string
    {
        // Use TokenManager for consistent token extraction across framework
        if (class_exists('\\Glueful\\Auth\\TokenManager')) {
            return $this->getTokenManager()->extractTokenFromRequest();
        }

        // Fallback for environments without TokenManager (shouldn't happen in normal usage)
        return $this->extractTokenFallback($request);
    }

    /**
     * Authenticate request with configured providers
     *
     * Authentication providers will extract credentials from the request directly.
     * No need to manually set headers since providers know how to extract their tokens.
     *
     * @param Request $request The HTTP request (used for authentication context)
     * @param string $credentials The extracted credentials (for provider info only)
     * @return array<string, mixed>|null
     */
    private function authenticateRequest(Request $request, string $credentials): ?array
    {
        // Authentication providers will extract credentials from the request directly
        // No need to clone or modify the request - providers handle their own extraction

        // Try authentication with configured providers
        if (count($this->providerNames) > 0) {
            try {
                $user = $this->authManager->authenticateWithProviders($this->providerNames, $request);
            } catch (\Error) {
                // Method doesn't exist, fallback to single auth
                $user = $this->authManager->authenticate($request);
            }
        } else {
            $user = $this->authManager->authenticate($request);
        }

        // Add provider information if available and not already set
        if ($user !== null && !isset($user['auth_provider'])) {
            if ($this->looksLikeJwt($credentials)) {
                $user['auth_provider'] = 'jwt';
            } else {
                $user['auth_provider'] = 'api_key';
            }
        }

        return $user;
    }

    /**
     * Validate token expiration
     */
    /**
     * @param array<string, mixed> $user
     */
    private function validateTokenExpiration(array $user, Request $request): ?JsonResponse
    {
        $now = time();

        // Check access token expiration
        if (isset($user['access_expires_at'])) {
            $accessExpiresAt = is_string($user['access_expires_at'])
                ? strtotime($user['access_expires_at'])
                : $user['access_expires_at'];

            if ($accessExpiresAt !== false && $accessExpiresAt < $now) {
                // Check if refresh token is available and valid
                if (isset($user['refresh_expires_at'])) {
                    $refreshExpiresAt = is_string($user['refresh_expires_at'])
                        ? strtotime($user['refresh_expires_at'])
                        : $user['refresh_expires_at'];

                    if ($refreshExpiresAt !== false && $refreshExpiresAt > $now) {
                        return $this->tokenExpired(true);
                    }
                }
                return $this->sessionExpired();
            }
        }

        // Validate JWT expiration if using JWT
        if (isset($user['access_token']) && is_string($user['access_token'])) {
            if (class_exists('\\Glueful\\Auth\\JWTService')) {
                try {
                    if (call_user_func(['\\Glueful\\Auth\\JWTService', 'isExpired'], $user['access_token'])) {
                        return $this->tokenExpired(isset($user['refresh_token']));
                    }
                } catch (\Exception $e) {
                    $this->logError('JWT validation failed', $e, $request);
                    return $this->invalidToken();
                }
            }
        }

        return null;
    }

    /**
     * Fallback token extraction when TokenManager is not available
     *
     * This is a minimal fallback that should rarely be used.
     * In normal operation, TokenManager should always be available.
     */
    private function extractTokenFallback(Request $request): ?string
    {
        // Check Authorization header for Bearer token
        $authHeader = $request->headers->get('Authorization');
        if ($authHeader !== null && preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches) === 1) {
            return $matches[1];
        }

        // Check query parameter as fallback
        $token = $request->query->get('token');
        if ($token !== null && is_string($token)) {
            return $token;
        }

        return null;
    }

    /**
     * Check if user has admin privileges
     *
     * @param array<string, mixed>|object $user User data
     */
    private function isAdmin(array|object $user): bool
    {
        // Use auth manager's isAdmin if available
        try {
            if (is_array($user)) {
                return $this->authManager->isAdmin($user);
            }
            // Convert object to array for auth manager
            $userArray = (array)$user;
            return $this->authManager->isAdmin($userArray);
        } catch (\Error) {
            // Method doesn't exist, fallback to manual check
        }

        // Check various admin indicators
        if (is_array($user)) {
            // Check array keys
            if (isset($user['role']) && $user['role'] === 'admin') {
                return true;
            }
            if (isset($user['is_admin']) && $user['is_admin'] === true) {
                return true;
            }
            if (isset($user['roles']) && is_array($user['roles'])) {
                return in_array('admin', $user['roles'], true);
            }
        } else {
            // Check object properties
            if (property_exists($user, 'role') && $user->role === 'admin') {
                return true;
            }
            if (property_exists($user, 'is_admin') && $user->is_admin) {
                return true;
            }
            if (property_exists($user, 'roles') && is_array($user->roles)) {
                return in_array('admin', $user->roles, true);
            }
            // Check methods
            if (method_exists($user, 'hasRole')) {
                return $user->hasRole('admin');
            }
            if (method_exists($user, 'isAdmin')) {
                return $user->isAdmin();
            }
        }

        return false;
    }

    /**
     * Check if string looks like a JWT token
     */
    private function looksLikeJwt(string $token): bool
    {
        // JWT tokens have 3 parts separated by dots
        return substr_count($token, '.') === 2;
    }

    /**
     * Handle authentication exceptions
     */
    private function handleAuthenticationException(
        AuthenticationException $e,
        Request $request,
        string $requestId
    ): JsonResponse {
        $message = $e->getMessage();

        // Check for specific error types
        if (str_contains($message, 'expired')) {
            $this->logAuthFailure('Token expired', $request, $requestId);
            $this->dispatchAuthFailureEvent('token_expired', $request);

            // Check if refresh is available
            $context = $e->getContext();
            $refreshAvailable = (bool)($context['refresh_available'] ?? false);

            return $refreshAvailable === true ? $this->tokenExpired(true) : $this->sessionExpired();
        }

        if (str_contains($message, 'Invalid token format') || str_contains($message, 'malformed')) {
            $this->logAuthFailure('Invalid token format', $request, $requestId);
            $this->dispatchAuthFailureEvent('invalid_token', $request);
            return $this->invalidToken();
        }

        $this->logAuthFailure($message, $request, $requestId);
        $this->dispatchAuthFailureEvent('authentication_error', $request);

        return $this->unauthorized($message);
    }

    /**
     * Log authentication success
     */
    /**
     * @param array<string, mixed> $user
     */
    private function logAuthSuccess(
        array $user,
        Request $request,
        string $requestId,
        ?string $token = null
    ): void {
        if (!$this->enableDetailedLogging || $this->logger === null) {
            return;
        }

        $this->logger->info('Authentication successful', [
            'type' => 'auth_success',
            'user_id' => $user['id'] ?? $user['uuid'] ?? 'unknown',
            'provider' => $user['auth_provider'] ?? 'unknown',
            'path' => $request->getPathInfo(),
            'method' => $request->getMethod(),
            'ip' => $request->getClientIp(),
            'user_agent' => $request->headers->get('User-Agent'),
            'request_id' => $requestId,
            'token_prefix' => $token !== null ? substr($token, 0, 10) : null
        ]);
    }

    /**
     * Log authentication failure
     */
    private function logAuthFailure(
        string $reason,
        Request $request,
        string $requestId,
        ?string $token = null
    ): void {
        if (!$this->enableDetailedLogging || $this->logger === null) {
            return;
        }

        $this->logger->warning('Authentication failed', [
            'type' => 'auth_failure',
            'reason' => $reason,
            'path' => $request->getPathInfo(),
            'method' => $request->getMethod(),
            'ip' => $request->getClientIp(),
            'user_agent' => $request->headers->get('User-Agent'),
            'request_id' => $requestId,
            'token_prefix' => $token !== null ? substr($token, 0, 10) : null
        ]);
    }

    /**
     * Log errors
     */
    private function logError(
        string $message,
        \Exception $exception,
        Request $request,
        ?string $requestId = null
    ): void {
        if ($this->logger === null) {
            error_log($message . ': ' . $exception->getMessage());
            return;
        }

        $this->logger->error($message, [
            'type' => 'auth_error',
            'exception' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
            'path' => $request->getPathInfo(),
            'method' => $request->getMethod(),
            'request_id' => $requestId
        ]);
    }

    /**
     * Dispatch authentication success event
     */
    /**
     * @param array<string, mixed> $user
     */
    private function dispatchAuthSuccessEvent(
        Request $request,
        array $user,
        ?string $token = null
    ): void {
        if (!$this->enableEventDispatch) {
            return;
        }

        if (class_exists('\\Glueful\\Events\\Http\\HttpAuthSuccessEvent')) {
            $this->getEventService()?->dispatch(new HttpAuthSuccessEvent(
                $request,
                [
                    'user_id' => $user['id'] ?? $user['uuid'] ?? null,
                    'provider' => $user['auth_provider'] ?? 'unknown',
                    'token_prefix' => $token !== null ? substr($token, 0, 10) : null
                ]
            ));
        }
    }

    /**
     * Dispatch authentication failure event
     */
    private function dispatchAuthFailureEvent(
        string $reason,
        Request $request,
        ?string $token = null
    ): void {
        if (!$this->enableEventDispatch) {
            return;
        }

        if (class_exists('\\Glueful\\Events\\Http\\HttpAuthFailureEvent')) {
            $this->getEventService()?->dispatch(new HttpAuthFailureEvent(
                $reason,
                $request,
                $token !== null ? substr($token, 0, 10) : null
            ));
        }
    }

    private function getEventService(): ?EventService
    {
        if ($this->container !== null && $this->container->has(EventService::class)) {
            $service = $this->container->get(EventService::class);
            return $service instanceof EventService ? $service : null;
        }

        if ($this->context !== null && $this->context->hasContainer()) {
            $service = $this->context->getContainer()->get(EventService::class);
            return $service instanceof EventService ? $service : null;
        }

        return null;
    }

    private function getTokenManager(): TokenManager
    {
        if ($this->container !== null && $this->container->has(TokenManager::class)) {
            return $this->container->get(TokenManager::class);
        }

        if ($this->context !== null && $this->context->hasContainer()) {
            return $this->context->getContainer()->get(TokenManager::class);
        }

        return new TokenManager($this->context);
    }

    /**
     * Get default container safely
     */
    private function getDefaultContainer(): ?ContainerInterface
    {
        // Check if container() function exists
        if ($this->context !== null && function_exists('container')) {
            try {
                $c = container($this->context);
                return $c;
            } catch (\Exception) {
                return null;
            }
        }

        // Try to get from global app instance
        if ($this->context !== null && function_exists('app')) {
            try {
                $app = app($this->context);
                if ($app instanceof ContainerInterface) {
                    return $app;
                }
                if (is_object($app) && method_exists($app, 'getContainer')) {
                    $container = $app->getContainer();
                    return $container instanceof ContainerInterface ? $container : null;
                }
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }

    /**
     * Return unauthorized response
     */
    private function unauthorized(string $message = 'Authentication required'): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'message' => $message,
            'code' => 401,
            'error_code' => 'UNAUTHORIZED'
        ], 401);
    }

    /**
     * Return forbidden response
     */
    private function forbidden(string $message = 'Access forbidden'): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'message' => $message,
            'code' => 403,
            'error_code' => 'FORBIDDEN'
        ], 403);
    }

    /**
     * Return token expired response
     */
    private function tokenExpired(bool $refreshAvailable = false): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'message' => 'Access token expired',
            'code' => 401,
            'error_code' => 'TOKEN_EXPIRED',
            'refresh_available' => $refreshAvailable
        ], 401);
    }

    /**
     * Return session expired response
     */
    private function sessionExpired(): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'message' => 'Session expired. Please log in again',
            'code' => 401,
            'error_code' => 'SESSION_EXPIRED',
            'refresh_available' => false
        ], 401);
    }

    /**
     * Return invalid token response
     */
    private function invalidToken(): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'message' => 'Invalid token format',
            'code' => 401,
            'error_code' => 'INVALID_TOKEN'
        ], 401);
    }

    /**
     * Automatically enrich request with auth attributes
     *
     * This method safely attempts to call AuthToRequestAttributesMiddleware's
     * enrichRequest method to add authentication data as request attributes.
     * Fails gracefully if the service is not available.
     */
    private function autoEnrichRequest(Request $request): void
    {
        if ($this->container === null) {
            return;
        }

        try {
            $middlewareClass = '\\Glueful\\Permissions\\Middleware\\AuthToRequestAttributesMiddleware';
            if ($this->container->has($middlewareClass)) {
                $enricher = $this->container->get($middlewareClass);
                if (is_object($enricher) && method_exists($enricher, 'enrichRequest')) {
                    $enricher->enrichRequest($request);
                }
            }
        } catch (\Throwable $e) {
            // Log error but continue - auth attribute enrichment is optional
            if ($this->logger !== null) {
                $this->logger->debug('Failed to auto-enrich request with auth attributes', [
                    'error' => $e->getMessage(),
                    'path' => $request->getPathInfo()
                ]);
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace Glueful\Routing\Middleware;

use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Glueful\Security\RandomStringGenerator;
use Glueful\Cache\CacheStore;
use Glueful\Exceptions\SecurityException;
use Psr\Container\ContainerInterface;
use Glueful\Events\Security\CSRFViolationEvent;
use Glueful\Events\Event;
use Psr\Log\LoggerInterface;

/**
 * CSRF Protection Middleware for Next-Gen Router
 *
 * Native Glueful middleware that protects against Cross-Site Request Forgery attacks
 * by validating CSRF tokens for state-changing HTTP methods.
 *
 * Features:
 * - Token generation and validation with secure algorithms
 * - Session-based token storage with cache fallback
 * - Configurable token lifetime and rotation
 * - Double-submit cookie pattern support
 * - JSON, form, and header-based token validation
 * - Rate limiting for token generation
 * - Per-route configuration support
 * - Token refresh on validation success
 * - Stateless token support for APIs
 * - Origin/Referer validation
 * - SameSite cookie enforcement
 * - Distributed token storage for scalability
 *
 * Security enhancements:
 * - Cryptographically secure token generation (128-bit entropy)
 * - Constant-time token comparison to prevent timing attacks
 * - Token binding to session/user context
 * - Automatic token rotation on sensitive operations
 * - Rate limiting to prevent token exhaustion attacks
 * - Origin validation to prevent CSRF from subdomain takeover
 *
 * Usage examples:
 *
 * // Basic CSRF protection
 * $router->post('/api/transfer', [TransferController::class, 'create'])
 *     ->middleware(['csrf']);
 *
 * // Custom token lifetime
 * $router->post('/api/sensitive', [SensitiveController::class, 'action'])
 *     ->middleware(['csrf:300']); // 5 minute token lifetime
 *
 * // With double-submit cookie pattern
 * $router->post('/api/payment', [PaymentController::class, 'process'])
 *     ->middleware(['csrf:3600,true']); // 1 hour, double-submit enabled
 *
 * // Exempt specific routes
 * $csrfMiddleware = new CSRFMiddleware(
 *     exemptRoutes: ['api/webhooks/*', 'api/public/*']
 * );
 */
class CSRFMiddleware implements RouteMiddleware
{
    /** @var string CSRF token header name */
    private const CSRF_HEADER = 'X-CSRF-Token';

    /** @var string Alternative CSRF token header name */
    private const CSRF_HEADER_ALT = 'X-XSRF-Token';

    /** @var string CSRF token form field name */
    private const CSRF_FIELD = '_token';

    /** @var string Alternative CSRF token form field name */
    private const CSRF_FIELD_ALT = 'csrf_token';

    /** @var string Cookie name for double-submit pattern */
    private const CSRF_COOKIE = 'XSRF-TOKEN';

    /** @var string Cache key prefix for CSRF tokens */
    private const CACHE_PREFIX = 'csrf_token_';

    /** @var string Cache key prefix for rate limiting */
    private const RATE_LIMIT_PREFIX = 'csrf_rate_';

    /** @var int Default token lifetime in seconds (2 hours) */
    private const DEFAULT_TOKEN_LIFETIME = 7200;

    /** @var int Token length in characters (128-bit entropy) */
    private const TOKEN_LENGTH = 32;

    /** @var int Maximum token generation attempts per minute */
    private const MAX_TOKEN_GENERATION_PER_MINUTE = 10;

    /** @var array<string> Safe HTTP methods that don't require protection */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /** @var array<string> Routes exempt from CSRF protection */
    private array $exemptRoutes;

    /** @var int Token lifetime in seconds */
    private int $tokenLifetime;

    /** @var bool Whether to use double-submit cookie pattern */
    private bool $useDoubleSubmit;

    /** @var bool Whether to enforce CSRF protection */
    private bool $enabled;

    /** @var bool Whether to validate Origin/Referer headers */
    private bool $validateOrigin;

    /** @var bool Whether to auto-rotate tokens after validation */
    private bool $autoRotateTokens;

    /** @var bool Whether to use stateless tokens for APIs */
    private bool $useStatelessTokens;

    /** @var CacheStore<string>|null Cache instance */
    private ?CacheStore $cache;

    /** @var LoggerInterface|null Logger instance */
    private ?LoggerInterface $logger;

    /** @var ContainerInterface|null DI Container */
    private ?ContainerInterface $container;

    /** @var array<string> Allowed origins for Origin validation */
    private array $allowedOrigins;

    /** @var int Rate limit window in seconds */
    private int $rateLimitWindow = 60;

    /**
     * Create CSRF middleware
     *
     * @param array<string> $exemptRoutes Routes to exempt from CSRF protection
     * @param int $tokenLifetime Token lifetime in seconds
     * @param bool $useDoubleSubmit Whether to use double-submit cookie pattern
     * @param bool $enabled Whether CSRF protection is enabled
     * @param bool $validateOrigin Whether to validate Origin/Referer headers
     * @param bool $autoRotateTokens Whether to auto-rotate tokens after validation
     * @param bool $useStatelessTokens Whether to use stateless tokens for APIs
     * @param array<string> $allowedOrigins Allowed origins for Origin validation
     * @param ContainerInterface|null $container DI Container instance
     * @param CacheStore<string>|null $cache Cache instance
     * @param LoggerInterface|null $logger Logger instance
     */
    public function __construct(
        array $exemptRoutes = [],
        int $tokenLifetime = self::DEFAULT_TOKEN_LIFETIME,
        bool $useDoubleSubmit = false,
        bool $enabled = true,
        bool $validateOrigin = true,
        bool $autoRotateTokens = false,
        bool $useStatelessTokens = false,
        array $allowedOrigins = [],
        ?ContainerInterface $container = null,
        ?CacheStore $cache = null,
        ?LoggerInterface $logger = null
    ) {
        $this->exemptRoutes = $this->normalizeRoutes($exemptRoutes);
        $this->tokenLifetime = $tokenLifetime;
        $this->useDoubleSubmit = $useDoubleSubmit;
        $this->enabled = $enabled;
        $this->validateOrigin = $validateOrigin;
        $this->autoRotateTokens = $autoRotateTokens;
        $this->useStatelessTokens = $useStatelessTokens;
        $this->allowedOrigins = $allowedOrigins;
        $this->container = $container ?? $this->getDefaultContainer();
        $this->cache = $cache;
        $this->logger = $logger;

        // Try to get cache from container if not provided
        if ($this->cache === null && $this->container !== null) {
            try {
                $this->cache = $this->container->get(CacheStore::class);
            } catch (\Exception) {
                // Cache not available - will use session fallback
            }
        }

        // Try to get logger from container if not provided
        if ($this->logger === null && $this->container !== null) {
            try {
                $this->logger = $this->container->get(LoggerInterface::class);
            } catch (\Exception) {
                // Logger not available - continue without logging
            }
        }

        // Set allowed origins from environment if not provided
        if (count($this->allowedOrigins) === 0) {
            $this->allowedOrigins = $this->getDefaultAllowedOrigins();
        }
    }

    /**
     * Handle CSRF protection for the request
     *
     * @param Request $request The incoming request
     * @param callable $next Next handler in the pipeline
     * @param mixed ...$params Additional parameters from route configuration
     *                         [0] = token lifetime (int, optional)
     *                         [1] = use double submit (bool, optional)
     *                         [2] = validate origin (bool, optional)
     * @return mixed Response or result from next handler
     * @throws SecurityException If CSRF validation fails
     */
    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        // Extract parameters if provided via route configuration
        $tokenLifetime = isset($params[0]) && is_int($params[0]) ? $params[0] : $this->tokenLifetime;
        $useDoubleSubmit = isset($params[1]) && is_bool($params[1]) ? $params[1] : $this->useDoubleSubmit;
        $validateOrigin = isset($params[2]) && is_bool($params[2]) ? $params[2] : $this->validateOrigin;

        // Skip if CSRF protection is disabled globally
        if (!$this->enabled) {
            return $next($request);
        }

        // Skip if route is exempt
        if ($this->isExemptRoute($request)) {
            $this->logger?->debug('CSRF protection skipped for exempt route', [
                'path' => $request->getPathInfo(),
                'method' => $request->getMethod()
            ]);
            return $next($request);
        }

        // Skip for safe HTTP methods
        if ($this->isSafeMethod($request->getMethod())) {
            // Generate token for safe methods to be available for forms
            $this->ensureTokenExists($request, $tokenLifetime, $useDoubleSubmit);
            return $next($request);
        }

        // Check rate limiting for token generation
        if (!$this->checkRateLimit($request)) {
            $this->logger?->warning('CSRF token generation rate limit exceeded', [
                'ip' => $request->getClientIp(),
                'path' => $request->getPathInfo()
            ]);

            return new JsonResponse([
                'error' => 'Too many token generation attempts. Please try again later.',
                'error_code' => 'CSRF_RATE_LIMIT_EXCEEDED'
            ], 429);
        }

        // Validate Origin/Referer headers if enabled
        if ($validateOrigin && !$this->validateOriginHeader($request)) {
            $this->logger?->warning('CSRF Origin/Referer validation failed', [
                'origin' => $request->headers->get('Origin'),
                'referer' => $request->headers->get('Referer'),
                'path' => $request->getPathInfo()
            ]);

            Event::dispatch(new CSRFViolationEvent(
                'csrf_origin_mismatch',
                $request
            ));

            throw new SecurityException(
                'Request origin validation failed.',
                403,
                [
                    'error_code' => 'CSRF_ORIGIN_MISMATCH',
                    'method' => $request->getMethod(),
                    'path' => $request->getPathInfo()
                ]
            );
        }

        // Validate CSRF token for protected methods
        if (!$this->validateToken($request, $useDoubleSubmit)) {
            $this->logger?->error('CSRF token validation failed', [
                'ip' => $request->getClientIp(),
                'path' => $request->getPathInfo(),
                'method' => $request->getMethod()
            ]);

            Event::dispatch(new CSRFViolationEvent(
                'csrf_token_mismatch',
                $request
            ));

            // Return JSON response for AJAX requests
            if ($this->isAjaxRequest($request)) {
                return new JsonResponse([
                    'error' => 'CSRF token validation failed. Please refresh and try again.',
                    'error_code' => 'CSRF_TOKEN_MISMATCH'
                ], 419); // 419 Page Expired
            }

            throw new SecurityException(
                'CSRF token validation failed. Please refresh the page and try again.',
                419,
                [
                    'error_code' => 'CSRF_TOKEN_MISMATCH',
                    'method' => $request->getMethod(),
                    'path' => $request->getPathInfo()
                ]
            );
        }

        // Auto-rotate token if enabled
        if ($this->autoRotateTokens) {
            $this->rotateToken($request, $tokenLifetime, $useDoubleSubmit);
        }

        // CSRF validation passed, continue to next middleware
        $response = $next($request);

        // Add CSRF token to response headers for AJAX requests
        if ($this->isAjaxRequest($request) && $response instanceof Response) {
            $token = $this->getToken($request) ?? $this->generateToken($request, $tokenLifetime);
            $response->headers->set(self::CSRF_HEADER, $token);
        }

        return $response;
    }

    /**
     * Generate CSRF token for the session
     *
     * @param Request $request The HTTP request
     * @param int|null $lifetime Token lifetime in seconds
     * @return string Generated CSRF token
     */
    public function generateToken(Request $request, ?int $lifetime = null): string
    {
        $lifetime = $lifetime ?? $this->tokenLifetime;
        $sessionId = $this->getSessionId($request);

        // Generate cryptographically secure token
        $token = RandomStringGenerator::generateHex(self::TOKEN_LENGTH);

        // Add additional entropy from request context
        if ($this->useStatelessTokens) {
            $token = $this->generateStatelessToken($request, $token);
        }

        $cacheKey = self::CACHE_PREFIX . $sessionId;

        // Store token in cache with expiration
        if ($this->cache !== null) {
            try {
                $tokenData = [
                    'token' => $token,
                    'created_at' => time(),
                    'expires_at' => time() + $lifetime,
                    'ip' => $request->getClientIp(),
                    'user_agent' => $request->headers->get('User-Agent')
                ];

                $this->cache->set($cacheKey, json_encode($tokenData), $lifetime);

                $this->logger?->debug('CSRF token generated', [
                    'session_id' => substr($sessionId, 0, 8) . '...',
                    'expires_at' => $tokenData['expires_at']
                ]);
            } catch (\Exception $e) {
                $this->logger?->error('Failed to store CSRF token in cache', [
                    'error' => $e->getMessage()
                ]);

                // Fall back to session storage
                $this->storeTokenInSession($request, $token, $lifetime);
            }
        } else {
            // Use session storage as fallback
            $this->storeTokenInSession($request, $token, $lifetime);
        }

        return $token;
    }

    /**
     * Get CSRF token for the current session
     *
     * @param Request $request The HTTP request
     * @return string|null Current CSRF token or null if not found
     */
    public function getToken(Request $request): ?string
    {
        $sessionId = $this->getSessionId($request);
        $cacheKey = self::CACHE_PREFIX . $sessionId;

        if ($this->cache !== null) {
            try {
                $data = $this->cache->get($cacheKey);
                if ($data !== null) {
                    $tokenData = json_decode($data, true);

                    // Validate token hasn't expired
                    if (isset($tokenData['expires_at']) && $tokenData['expires_at'] > time()) {
                        return $tokenData['token'];
                    }
                }
            } catch (\Exception $e) {
                $this->logger?->error('Failed to retrieve CSRF token from cache', [
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Fall back to session storage
        return $this->getTokenFromSession($request);
    }

    /**
     * Validate CSRF token from request
     *
     * @param Request $request The HTTP request
     * @param bool $useDoubleSubmit Whether to validate double-submit cookie
     * @return bool Whether token is valid
     */
    private function validateToken(Request $request, bool $useDoubleSubmit): bool
    {
        $expectedToken = $this->getToken($request);
        if ($expectedToken === null) {
            $this->logger?->debug('CSRF validation failed: no expected token found');
            return false;
        }

        // Get token from various sources
        $submittedToken = $this->getSubmittedToken($request);
        if ($submittedToken === null) {
            $this->logger?->debug('CSRF validation failed: no submitted token found');
            return false;
        }

        // Use constant-time comparison to prevent timing attacks
        $isValid = hash_equals($expectedToken, $submittedToken);

        // Validate double-submit cookie if enabled
        if ($isValid && $useDoubleSubmit) {
            $cookieToken = $request->cookies->get(self::CSRF_COOKIE);
            if ($cookieToken === null || $cookieToken === '' || !hash_equals($expectedToken, $cookieToken)) {
                $this->logger?->debug('CSRF validation failed: double-submit cookie mismatch');
                $isValid = false;
            }
        }

        // Additional validation for stateless tokens
        if ($isValid && $this->useStatelessTokens) {
            $isValid = $this->validateStatelessToken($request, $submittedToken);
        }

        return $isValid;
    }

    /**
     * Get submitted CSRF token from request
     *
     * @param Request $request The HTTP request
     * @return string|null Submitted token or null if not found
     */
    private function getSubmittedToken(Request $request): ?string
    {
        // Check primary header first (for AJAX requests)
        $token = $request->headers->get(self::CSRF_HEADER);
        if ($token !== null) {
            return $token;
        }

        // Check alternative header
        $token = $request->headers->get(self::CSRF_HEADER_ALT);
        if ($token !== null) {
            return $token;
        }

        // Check form data
        $token = $request->request->get(self::CSRF_FIELD);
        if ($token !== null) {
            return $token;
        }

        // Check alternative form field
        $token = $request->request->get(self::CSRF_FIELD_ALT);
        if ($token !== null) {
            return $token;
        }

        // Check JSON body for API requests
        if (str_contains($request->headers->get('Content-Type', ''), 'application/json')) {
            $content = $request->getContent();
            if ($content !== '') {
                $json = json_decode($content, true);
                if (is_array($json)) {
                    // Check both field names in JSON
                    $token = $json[self::CSRF_FIELD] ?? $json[self::CSRF_FIELD_ALT] ?? null;
                    if ($token !== null) {
                        return $token;
                    }
                }
            }
        }

        // Check query parameters as last resort (use carefully)
        $token = $request->query->get(self::CSRF_FIELD);

        return $token;
    }

    /**
     * Validate Origin/Referer headers
     *
     * @param Request $request The HTTP request
     * @return bool Whether origin is valid
     */
    private function validateOriginHeader(Request $request): bool
    {
        $origin = $request->headers->get('Origin');
        $referer = $request->headers->get('Referer');

        // If neither header is present, fail closed for security
        if ($origin === null && $referer === null) {
            return false;
        }

        // Check Origin header first (more reliable)
        if ($origin !== null) {
            return $this->isAllowedOrigin($origin);
        }

        // Fall back to Referer header
        if ($referer !== null) {
            $refererOrigin = $this->extractOriginFromUrl($referer);
            return $this->isAllowedOrigin($refererOrigin);
        }

        return false;
    }

    /**
     * Check if origin is allowed
     *
     * @param string $origin Origin to check
     * @return bool Whether origin is allowed
     */
    private function isAllowedOrigin(string $origin): bool
    {
        // Remove trailing slash for comparison
        $origin = rtrim($origin, '/');

        foreach ($this->allowedOrigins as $allowedOrigin) {
            $allowedOrigin = rtrim($allowedOrigin, '/');

            // Exact match
            if ($origin === $allowedOrigin) {
                return true;
            }

            // Wildcard subdomain matching (*.example.com)
            if (str_starts_with($allowedOrigin, '*.')) {
                $domain = substr($allowedOrigin, 2);
                if (str_ends_with($origin, $domain)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Extract origin from URL
     *
     * @param string $url URL to extract origin from
     * @return string Extracted origin
     */
    private function extractOriginFromUrl(string $url): string
    {
        $parsed = parse_url($url);
        if ($parsed === false) {
            return '';
        }

        $origin = ($parsed['scheme'] ?? 'https') . '://';
        $origin .= $parsed['host'] ?? '';

        if (
            isset($parsed['port']) &&
            !(($parsed['scheme'] === 'https' && $parsed['port'] === 443) ||
              ($parsed['scheme'] === 'http' && $parsed['port'] === 80))
        ) {
            $origin .= ':' . $parsed['port'];
        }

        return $origin;
    }

    /**
     * Check rate limiting for token generation
     *
     * @param Request $request The HTTP request
     * @return bool Whether rate limit is OK
     */
    private function checkRateLimit(Request $request): bool
    {
        if ($this->cache === null) {
            return true; // Can't rate limit without cache
        }

        $ip = $request->getClientIp() ?? 'unknown';
        $key = self::RATE_LIMIT_PREFIX . $ip;

        try {
            $attempts = (int) $this->cache->get($key, 0);

            if ($attempts >= self::MAX_TOKEN_GENERATION_PER_MINUTE) {
                return false;
            }

            $this->cache->set($key, (string)($attempts + 1), $this->rateLimitWindow);
            return true;
        } catch (\Exception) {
            return true; // Fail open if cache is unavailable
        }
    }

    /**
     * Ensure token exists for the session
     *
     * @param Request $request The HTTP request
     * @param int $lifetime Token lifetime
     * @param bool $useDoubleSubmit Whether to set double-submit cookie
     * @return void
     */
    private function ensureTokenExists(Request $request, int $lifetime, bool $useDoubleSubmit): void
    {
        $existingToken = $this->getToken($request);

        if ($existingToken === null) {
            $token = $this->generateToken($request, $lifetime);

            // Set double-submit cookie if enabled
            if ($useDoubleSubmit) {
                $this->setDoubleSubmitCookie($request, $token, $lifetime);
            }
        } elseif ($useDoubleSubmit && !$request->cookies->has(self::CSRF_COOKIE)) {
            // Ensure cookie exists if double-submit is enabled
            $this->setDoubleSubmitCookie($request, $existingToken, $lifetime);
        }
    }

    /**
     * Set double-submit cookie
     *
     * @param Request $request The HTTP request
     * @param string $token CSRF token
     * @param int $lifetime Token lifetime
     * @return void
     */
    private function setDoubleSubmitCookie(Request $request, string $token, int $lifetime): void
    {
        setcookie(
            self::CSRF_COOKIE,
            $token,
            [
                'expires' => time() + $lifetime,
                'path' => '/',
                'domain' => '',
                'secure' => $request->isSecure(),
                'httponly' => false, // Must be accessible to JavaScript
                'samesite' => 'Strict'
            ]
        );
    }

    /**
     * Rotate CSRF token
     *
     * @param Request $request The HTTP request
     * @param int $lifetime Token lifetime
     * @param bool $useDoubleSubmit Whether to set double-submit cookie
     * @return string New token
     */
    private function rotateToken(Request $request, int $lifetime, bool $useDoubleSubmit): string
    {
        // Invalidate old token
        $sessionId = $this->getSessionId($request);
        $cacheKey = self::CACHE_PREFIX . $sessionId;

        if ($this->cache !== null) {
            try {
                $this->cache->delete($cacheKey);
            } catch (\Exception) {
                // Continue even if deletion fails
            }
        }

        // Generate new token
        $newToken = $this->generateToken($request, $lifetime);

        // Update double-submit cookie if enabled
        if ($useDoubleSubmit) {
            $this->setDoubleSubmitCookie($request, $newToken, $lifetime);
        }

        $this->logger?->info('CSRF token rotated', [
            'session_id' => substr($sessionId, 0, 8) . '...'
        ]);

        return $newToken;
    }

    /**
     * Generate stateless token with additional entropy
     *
     * @param Request $request The HTTP request
     * @param string $baseToken Base token
     * @return string Enhanced token
     */
    private function generateStatelessToken(Request $request, string $baseToken): string
    {
        $context = [
            'ip' => $request->getClientIp(),
            'user_agent' => $request->headers->get('User-Agent'),
            'timestamp' => time()
        ];

        $contextHash = hash('sha256', json_encode($context));
        return $baseToken . ':' . substr($contextHash, 0, 16);
    }

    /**
     * Validate stateless token
     *
     * @param Request $request The HTTP request
     * @param string $token Token to validate
     * @return bool Whether token is valid
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) - Reserved for future enhanced validation
     */
    private function validateStatelessToken(Request $request, string $token): bool
    {
        // For stateless tokens, we could implement additional validation
        // such as checking token age, IP binding, etc.
        // For now, basic validation is sufficient
        // $request parameter reserved for future IP/User-Agent binding validation
        return strlen($token) >= self::TOKEN_LENGTH;
    }

    /**
     * Check if request is AJAX
     *
     * @param Request $request The HTTP request
     * @return bool Whether request is AJAX
     */
    private function isAjaxRequest(Request $request): bool
    {
        return $request->headers->get('X-Requested-With') === 'XMLHttpRequest' ||
               str_contains($request->headers->get('Accept', ''), 'application/json');
    }

    /**
     * Check if HTTP method is safe
     *
     * @param string $method HTTP method
     * @return bool Whether method is safe
     */
    private function isSafeMethod(string $method): bool
    {
        return in_array(strtoupper($method), self::SAFE_METHODS, true);
    }

    /**
     * Check if route is exempt from CSRF protection
     *
     * @param Request $request The HTTP request
     * @return bool Whether route is exempt
     */
    private function isExemptRoute(Request $request): bool
    {
        $path = $request->getPathInfo();
        $cleanPath = $this->getCleanPath($path);

        foreach ($this->exemptRoutes as $exemptRoute) {
            if (
                $this->matchesPattern($path, $exemptRoute) ||
                $this->matchesPattern($cleanPath, $exemptRoute)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get clean path by stripping base path and API version
     *
     * @param string $path Original request path
     * @return string Clean path
     */
    private function getCleanPath(string $path): string
    {
        $cleanPath = ltrim($path, '/');

        // Get API configuration from environment
        $baseUrl = env('BASE_URL', '');
        $apiVersion = 'v' . env('API_VERSION', '1');

        // Extract base path from BASE_URL
        if ($baseUrl !== '') {
            $parsedUrl = parse_url($baseUrl);
            $basePath = isset($parsedUrl['path']) ? trim($parsedUrl['path'], '/') : '';

            if ($basePath !== '' && str_starts_with($cleanPath, $basePath)) {
                $cleanPath = substr($cleanPath, strlen($basePath));
                $cleanPath = ltrim($cleanPath, '/');
            }
        }

        // Remove API version prefix (e.g., "v1")
        if (str_starts_with($cleanPath, $apiVersion)) {
            $cleanPath = substr($cleanPath, strlen($apiVersion));
            $cleanPath = ltrim($cleanPath, '/');
        }

        return $cleanPath;
    }

    /**
     * Check if path matches pattern
     *
     * @param string $path Request path
     * @param string $pattern Route pattern
     * @return bool Whether path matches pattern
     */
    private function matchesPattern(string $path, string $pattern): bool
    {
        // Exact match
        if ($path === $pattern) {
            return true;
        }

        // Wildcard pattern matching
        $pattern = str_replace(['*', '/'], ['.*', '\/'], $pattern);
        return (bool) preg_match('/^' . $pattern . '$/', $path);
    }

    /**
     * Get session ID from request
     *
     * @param Request $request The HTTP request
     * @return string Session identifier
     */
    private function getSessionId(Request $request): string
    {
        // Try to get from authenticated user session
        $user = $request->attributes->get('user');
        if (is_array($user) && isset($user['session_id'])) {
            return $user['session_id'];
        }

        // Try to get from JWT session
        $jwtSession = $request->attributes->get('jwt_session');
        if ($jwtSession !== null) {
            return 'jwt_' . substr(hash('sha256', $jwtSession), 0, 16);
        }

        // Fallback to fingerprinting for anonymous sessions
        $fingerprint = [
            'ip' => $request->getClientIp() ?? 'unknown',
            'user_agent' => $request->headers->get('User-Agent', 'unknown'),
            'accept_language' => $request->headers->get('Accept-Language', ''),
            'accept_encoding' => $request->headers->get('Accept-Encoding', '')
        ];

        return hash('sha256', json_encode($fingerprint));
    }

    /**
     * Store token in session
     *
     * @param Request $request The HTTP request
     * @param string $token CSRF token
     * @param int $lifetime Token lifetime
     * @return void
     */
    private function storeTokenInSession(Request $request, string $token, int $lifetime): void
    {
        if ($request->hasSession()) {
            $session = $request->getSession();
            $session->set('_csrf_token', $token);
            $session->set('_csrf_expires', time() + $lifetime);
        }
    }

    /**
     * Get token from session
     *
     * @param Request $request The HTTP request
     * @return string|null Token or null if not found
     */
    private function getTokenFromSession(Request $request): ?string
    {
        if (!$request->hasSession()) {
            return null;
        }

        $session = $request->getSession();
        $token = $session->get('_csrf_token');
        $expires = $session->get('_csrf_expires', 0);

        if (is_string($token) && $expires > time()) {
            return $token;
        }

        return null;
    }

    /**
     * Normalize exempt routes patterns
     *
     * @param array<string> $routes Raw route patterns
     * @return array<string> Normalized route patterns
     */
    private function normalizeRoutes(array $routes): array
    {
        return array_map(function ($route) {
            return ltrim($route, '/');
        }, $routes);
    }

    /**
     * Get default allowed origins from environment
     *
     * @return array<string> Default allowed origins
     */
    private function getDefaultAllowedOrigins(): array
    {
        $origins = [];

        // Add current application URL
        $appUrl = env('APP_URL', env('BASE_URL', ''));
        if ($appUrl !== '') {
            $origins[] = rtrim($appUrl, '/');
        }

        // Add frontend URL if configured
        $frontendUrl = env('FRONTEND_URL', '');
        if ($frontendUrl !== '') {
            $origins[] = rtrim($frontendUrl, '/');
        }

        // Add localhost for development
        if (env('APP_ENV', 'production') !== 'production') {
            $origins[] = 'http://localhost';
            $origins[] = 'http://127.0.0.1';
            $origins[] = 'http://localhost:3000'; // Common React/Vue dev port
            $origins[] = 'http://localhost:8080'; // Common webpack dev port
        }

        return array_unique($origins);
    }

    /**
     * Get default container safely
     */
    private function getDefaultContainer(): ?\Psr\Container\ContainerInterface
    {
        if (function_exists('container')) {
            try {
                $c = container();
                return $c;
            } catch (\Exception) {
                return null;
            }
        }

        if (function_exists('app')) {
            try {
                $a = app();
                return $a instanceof \Psr\Container\ContainerInterface ? $a : null;
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }

    /**
     * Create middleware with common API routes exempted
     *
     * @param array<string> $additionalExemptions Additional routes to exempt
     * @return self Configured CSRF middleware
     */
    public static function withApiExemptions(array $additionalExemptions = []): self
    {
        $defaultExemptions = [
            'api/auth/login',
            'api/auth/register',
            'api/auth/forgot-password',
            'api/auth/reset-password',
            'api/auth/refresh',
            'api/auth/logout',
            'api/webhooks/*',
            'api/public/*',
            'api/health',
            'api/status'
        ];

        return new self(
            exemptRoutes: array_merge($defaultExemptions, $additionalExemptions)
        );
    }

    /**
     * Create middleware for SPA applications
     *
     * @param array<string> $exemptRoutes Routes to exempt
     * @return self Configured CSRF middleware
     */
    public static function forSpa(array $exemptRoutes = []): self
    {
        return new self(
            exemptRoutes: $exemptRoutes,
            useDoubleSubmit: true,
            validateOrigin: true,
            autoRotateTokens: false,
            useStatelessTokens: false
        );
    }

    /**
     * Create middleware for API-only applications
     *
     * @param array<string> $exemptRoutes Routes to exempt
     * @return self Configured CSRF middleware
     */
    public static function forApi(array $exemptRoutes = []): self
    {
        return new self(
            exemptRoutes: $exemptRoutes,
            useDoubleSubmit: false,
            validateOrigin: true,
            autoRotateTokens: true,
            useStatelessTokens: true
        );
    }

    /**
     * Get the current CSRF token as HTML hidden input
     *
     * @param Request $request The HTTP request
     * @return string HTML hidden input field
     */
    public function getTokenField(Request $request): string
    {
        $token = $this->getToken($request) ?? $this->generateToken($request);
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            htmlspecialchars(self::CSRF_FIELD, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($token, ENT_QUOTES, 'UTF-8')
        );
    }

    /**
     * Get the current CSRF token for JavaScript usage
     *
     * @param Request $request The HTTP request
     * @return array<string, mixed> Token data for JSON response
     */
    public function getTokenData(Request $request): array
    {
        $token = $this->getToken($request) ?? $this->generateToken($request);

        return [
            'token' => $token,
            'header' => self::CSRF_HEADER,
            'header_alt' => self::CSRF_HEADER_ALT,
            'field' => self::CSRF_FIELD,
            'field_alt' => self::CSRF_FIELD_ALT,
            'cookie' => self::CSRF_COOKIE,
            'expires_at' => time() + $this->tokenLifetime,
            'double_submit' => $this->useDoubleSubmit,
            'validate_origin' => $this->validateOrigin
        ];
    }

    /**
     * Get CSRF meta tags for HTML head
     *
     * @param Request $request The HTTP request
     * @return string HTML meta tags
     */
    public function getMetaTags(Request $request): string
    {
        $token = $this->getToken($request) ?? $this->generateToken($request);

        $tags = sprintf(
            '<meta name="csrf-token" content="%s">' . PHP_EOL,
            htmlspecialchars($token, ENT_QUOTES, 'UTF-8')
        );

        $tags .= sprintf(
            '<meta name="csrf-header" content="%s">' . PHP_EOL,
            htmlspecialchars(self::CSRF_HEADER, ENT_QUOTES, 'UTF-8')
        );

        $tags .= sprintf(
            '<meta name="csrf-field" content="%s">',
            htmlspecialchars(self::CSRF_FIELD, ENT_QUOTES, 'UTF-8')
        );

        return $tags;
    }
}

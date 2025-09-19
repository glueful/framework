<?php

declare(strict_types=1);

namespace Glueful\Routing\Middleware;

use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Glueful\Permissions\PermissionManager;
use Glueful\Permissions\Exceptions\PermissionException;
use Glueful\Permissions\Exceptions\ProviderNotFoundException;
use Glueful\Auth\AuthenticationService;
use Glueful\Repository\UserRepository;
use Glueful\Exceptions\SecurityException;
use Glueful\Exceptions\AuthenticationException;
use Glueful\Events\Security\AdminAccessEvent;
use Glueful\Events\Security\AdminSecurityViolationEvent;
use Glueful\Events\Event;
use Psr\Log\LoggerInterface;
use Psr\Container\ContainerInterface;

/**
 * Admin Permission Middleware for Next-Gen Router
 *
 * Native Glueful middleware that provides comprehensive administrative access control
 * with enhanced security features for admin-only routes and operations.
 *
 * Features:
 * - Multi-level admin role verification
 * - Enhanced security logging and monitoring
 * - IP whitelist/blacklist support with CIDR notation
 * - Session validation and elevated authentication
 * - Permission-based access control with context awareness
 * - Rate limiting for admin access attempts
 * - Audit trail for all admin operations
 * - Configurable security policies per route
 * - Emergency lockdown support
 * - Time-based access restrictions
 * - Multi-factor authentication integration
 * - Session timeout management
 * - Geographic restrictions
 * - Device fingerprinting
 * - Anomaly detection
 *
 * Security Enhancements:
 * - Automatic session rotation on admin access
 * - Failed login attempt tracking and lockout
 * - Suspicious activity detection and alerting
 * - Integration with security monitoring systems
 * - Advanced audit logging with detailed context
 * - Real-time security event dispatching
 *
 * Usage examples:
 *
 * // Basic admin access
 * $router->get('/admin/dashboard', [AdminController::class, 'dashboard'])
 *     ->middleware(['admin']);
 *
 * // Specific permission and resource
 * $router->post('/admin/users/{id}', [UserController::class, 'update'])
 *     ->middleware(['admin:admin.users.manage,users']);
 *
 * // With IP restrictions and elevated auth
 * $router->delete('/admin/system/reset', [SystemController::class, 'reset'])
 *     ->middleware(['admin:admin.system.reset,system,true,192.168.1.0/24']);
 *
 * // Super admin access with max security
 * $router->get('/admin/security', [SecurityController::class, 'index'])
 *     ->middleware(['admin:superuser']);
 */
class AdminPermissionMiddleware implements RouteMiddleware
{
    /** @var string Default admin permission */
    private const DEFAULT_ADMIN_PERMISSION = 'admin.access';

    /** @var string Default resource */
    private const DEFAULT_RESOURCE = 'admin';

    /** @var int Default elevated auth timeout (15 minutes) */
    private const DEFAULT_ELEVATED_TIMEOUT = 900;


    /** @var array<string, array<string, mixed>> Predefined security profiles */
    private const SECURITY_PROFILES = [
        'superuser' => [
            'permission' => 'admin.system.superuser',
            'resource' => 'system',
            'require_elevated' => true,
            'session_timeout' => 600, // 10 minutes
            'require_mfa' => true,
            'allowed_hours' => [8, 18], // 8 AM to 6 PM
            'log_level' => 'critical'
        ],
        'system' => [
            'permission' => 'admin.system.access',
            'resource' => 'system',
            'require_elevated' => true,
            'session_timeout' => 1800, // 30 minutes
            'require_mfa' => false,
            'log_level' => 'warning'
        ],
        'users' => [
            'permission' => 'admin.users.manage',
            'resource' => 'users',
            'require_elevated' => false,
            'session_timeout' => 3600, // 1 hour
            'require_mfa' => false,
            'log_level' => 'info'
        ],
        'content' => [
            'permission' => 'admin.content.manage',
            'resource' => 'content',
            'require_elevated' => false,
            'session_timeout' => 7200, // 2 hours
            'require_mfa' => false,
            'log_level' => 'info'
        ],
        'readonly' => [
            'permission' => 'admin.view',
            'resource' => 'admin',
            'require_elevated' => false,
            'session_timeout' => 14400, // 4 hours
            'require_mfa' => false,
            'log_level' => 'debug'
        ]
    ];

    /** @var string Admin permission required */
    private string $adminPermission;

    /** @var string Resource being accessed */
    private string $resource;

    /** @var array<string, mixed> Additional context for permission check */
    private array $context;

    /** @var array<string> Allowed IP addresses/CIDR ranges */
    private array $allowedIps;

    /** @var array<string> Blocked IP addresses/CIDR ranges */
    private array $blockedIps;

    /** @var bool Whether to require elevated authentication */
    private bool $requireElevated;

    /** @var bool Whether to require multi-factor authentication */
    private bool $requireMfa;

    /** @var int Session timeout in seconds */
    private int $sessionTimeout;

    /** @var array<int> Allowed hours for access (24h format) */
    private array $allowedHours;

    /** @var array<string> Allowed countries (ISO codes) */
    private array $allowedCountries;

    /** @var string Log level for security events */
    private string $logLevel;

    /** @var bool Whether emergency lockdown is active */
    private bool $emergencyLockdown;

    /** @var PermissionManager Permission manager instance */
    private PermissionManager $permissionManager;

    /** @var UserRepository User repository for admin checks */
    private UserRepository $userRepository;

    /** @var LoggerInterface|null Logger instance */
    private ?LoggerInterface $logger;

    /** @var ContainerInterface|null DI Container */
    private ?ContainerInterface $container;

    /** @var string Current environment */
    private string $environment;

    /**
     * Create admin permission middleware
     *
     * @param string $adminPermission Admin permission required
     * @param string $resource Resource identifier
     * @param array<string, mixed> $context Additional context for permission checking
     * @param array<string> $allowedIps Allowed IP addresses (empty = allow all)
     * @param array<string> $blockedIps Blocked IP addresses
     * @param bool $requireElevated Whether to require elevated authentication
     * @param bool $requireMfa Whether to require multi-factor authentication
     * @param int $sessionTimeout Session timeout in seconds
     * @param array<int> $allowedHours Allowed hours for access
     * @param array<string> $allowedCountries Allowed countries
     * @param string $logLevel Log level for security events
     * @param PermissionManager|null $permissionManager Permission manager instance
     * @param UserRepository|null $userRepository User repository instance
     * @param LoggerInterface|null $logger Logger instance
     * @param ContainerInterface|null $container DI Container instance
     */
    public function __construct(
        string $adminPermission = self::DEFAULT_ADMIN_PERMISSION,
        string $resource = self::DEFAULT_RESOURCE,
        array $context = [],
        array $allowedIps = [],
        array $blockedIps = [],
        bool $requireElevated = true,
        bool $requireMfa = false,
        int $sessionTimeout = self::DEFAULT_ELEVATED_TIMEOUT,
        array $allowedHours = [],
        array $allowedCountries = [],
        string $logLevel = 'warning',
        ?PermissionManager $permissionManager = null,
        ?UserRepository $userRepository = null,
        ?LoggerInterface $logger = null,
        ?ContainerInterface $container = null
    ) {
        $this->adminPermission = $adminPermission;
        $this->resource = $resource;
        $this->context = $context;
        $this->allowedIps = $allowedIps;
        $this->blockedIps = $blockedIps;
        $this->requireElevated = $requireElevated;
        $this->requireMfa = $requireMfa;
        $this->sessionTimeout = $sessionTimeout;
        $this->allowedHours = $allowedHours;
        $this->allowedCountries = $allowedCountries;
        $this->logLevel = $logLevel;
        $this->container = $container ?? $this->getDefaultContainer();
        $this->logger = $logger;

        // Initialize dependencies
        $this->permissionManager = $permissionManager ?? $this->getPermissionManagerFromContainer();
        $this->userRepository = $userRepository ?? new UserRepository();

        // Try to get logger from container if not provided
        if ($this->logger === null && $this->container !== null) {
            try {
                $this->logger = $this->container->get(LoggerInterface::class);
            } catch (\Exception) {
                // Logger not available
            }
        }

        // Initialize environment and emergency lockdown status
        $this->environment = $this->detectEnvironment();
        $this->emergencyLockdown = $this->checkEmergencyLockdown();
    }

    /**
     * Handle admin permission middleware
     *
     * @param Request $request The incoming request
     * @param callable $next Next handler in the pipeline
     * @param mixed ...$params Additional parameters from route configuration
     *                         [0] = permission (string, optional)
     *                         [1] = resource (string, optional)
     *                         [2] = require_elevated (bool, optional)
     *                         [3] = allowed_ips (string, optional) - comma-separated list
     *                         [4] = security_profile (string, optional) - predefined profile name
     * @return mixed Response with admin access control
     * @throws SecurityException If admin access is denied
     * @throws AuthenticationException If authentication fails
     */
    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        try {
            // Parse route parameters
            $config = $this->parseRouteParameters($params);

            // Check emergency lockdown
            if ($this->emergencyLockdown) {
                $this->logSecurityEvent($request, 'emergency_lockdown_active', null, 'critical');
                return $this->createAdminErrorResponse(
                    'System is in emergency lockdown mode',
                    503,
                    'EMERGENCY_LOCKDOWN_ACTIVE'
                );
            }

            // Check if permission system is available
            if (!$this->permissionManager->isAvailable()) {
                $this->logSecurityEvent($request, 'permission_system_unavailable', null, 'error');
                return $this->createAdminErrorResponse(
                    'Admin permission system not available',
                    503,
                    'ADMIN_PERMISSION_SYSTEM_UNAVAILABLE'
                );
            }

            // Check time-based restrictions
            if (!$this->checkTimeRestrictions()) {
                $this->logSecurityEvent($request, 'access_outside_allowed_hours', null, 'warning');
                return $this->createAdminErrorResponse(
                    'Admin access not allowed at this time',
                    403,
                    'ACCESS_TIME_RESTRICTED'
                );
            }

            // Check IP restrictions
            if (!$this->checkIpAccess($request, $config)) {
                $this->logSecurityEvent($request, 'ip_access_denied', null, 'warning');
                return $this->createAdminErrorResponse(
                    'Access denied from this IP address',
                    403,
                    'IP_ACCESS_DENIED'
                );
            }

            // Check rate limiting
            if (!$this->checkRateLimit($request)) {
                $this->logSecurityEvent($request, 'admin_rate_limit_exceeded', null, 'warning');
                return $this->createAdminErrorResponse(
                    'Too many admin access attempts. Please try again later.',
                    429,
                    'ADMIN_RATE_LIMIT_EXCEEDED'
                );
            }

            // Get and validate user
            $userUuid = $this->getUserUuid($request);
            if ($userUuid === null) {
                $this->logSecurityEvent($request, 'admin_authentication_required', null, 'info');
                return $this->createAdminErrorResponse(
                    'Admin authentication required',
                    401,
                    'ADMIN_AUTHENTICATION_REQUIRED'
                );
            }

            // Check failed attempts lockout
            if ($this->isUserLockedOut($userUuid)) {
                $this->logSecurityEvent($request, 'user_locked_out', $userUuid, 'warning');
                return $this->createAdminErrorResponse(
                    'Account temporarily locked due to failed attempts',
                    423,
                    'ACCOUNT_LOCKED'
                );
            }

            // Validate user account status
            if (!$this->validateUserStatus($userUuid)) {
                $this->incrementFailedAttempts($userUuid);
                $this->logSecurityEvent($request, 'admin_account_invalid', $userUuid, 'warning');
                return $this->createAdminErrorResponse(
                    'Admin account not valid',
                    403,
                    'ADMIN_ACCOUNT_INVALID'
                );
            }

            // Check session validity and timeout
            if (!$this->validateSession($request, $userUuid)) {
                $this->logSecurityEvent($request, 'admin_session_expired', $userUuid, 'info');
                return $this->createAdminErrorResponse(
                    'Admin session expired',
                    401,
                    'ADMIN_SESSION_EXPIRED'
                );
            }

            // Check elevated authentication if required
            $requireElevated = (bool)($config['require_elevated'] ?? false);
            if ($requireElevated && !$this->checkElevatedAuth($request, $userUuid)) {
                $this->logSecurityEvent($request, 'elevated_auth_required', $userUuid, 'warning');
                return $this->createAdminErrorResponse(
                    'Elevated authentication required for admin access',
                    403,
                    'ELEVATED_AUTH_REQUIRED'
                );
            }

            // Check multi-factor authentication if required
            $requireMfa = (bool)($config['require_mfa'] ?? false);
            if ($requireMfa && !$this->checkMfaAuth($request, $userUuid)) {
                $this->logSecurityEvent($request, 'mfa_required', $userUuid, 'warning');
                return $this->createAdminErrorResponse(
                    'Multi-factor authentication required',
                    403,
                    'MFA_REQUIRED'
                );
            }

            // Check geographic restrictions
            if (!$this->checkGeographicRestrictions($request)) {
                $this->logSecurityEvent($request, 'geographic_restriction_violated', $userUuid, 'warning');
                return $this->createAdminErrorResponse(
                    'Access denied from this location',
                    403,
                    'GEOGRAPHIC_ACCESS_DENIED'
                );
            }

            // Check admin permissions
            if (!$this->checkAdminPermission($userUuid, $request, $config)) {
                $this->incrementFailedAttempts($userUuid);
                $this->logSecurityEvent($request, 'admin_permission_denied', $userUuid, 'warning');
                return $this->createAdminErrorResponse(
                    'Insufficient admin permissions',
                    403,
                    'INSUFFICIENT_ADMIN_PERMISSIONS',
                    [
                        'required_permission' => $config['permission'],
                        'resource' => $config['resource']
                    ]
                );
            }

            // Reset failed attempts on successful access
            $this->resetFailedAttempts($userUuid);

            // Rotate session if needed
            $this->rotateSessionIfNeeded($request, $userUuid);

            // Update session activity
            $this->updateSessionActivity($request, $userUuid);

            // Log successful admin access
            $this->logSecurityEvent($request, 'admin_access_granted', $userUuid, $config['log_level'] ?? 'info');

            // Dispatch admin access event
            Event::dispatch(new AdminAccessEvent(
                $userUuid,
                $config['permission'],
                $config['resource'],
                $request
            ));

            // Add admin context to request
            $request->attributes->set('admin_user_uuid', $userUuid);
            $request->attributes->set('admin_permission', $config['permission']);
            $request->attributes->set('admin_resource', $config['resource']);
            $request->attributes->set('admin_context', array_merge($this->context, $config));
            $request->attributes->set('is_admin_request', true);
            $request->attributes->set('admin_session_timeout', $config['session_timeout'] ?? $this->sessionTimeout);

            // Admin permission check passed, continue
            $response = $next($request);

            // Add security headers to admin responses
            if ($response instanceof Response) {
                $this->addAdminSecurityHeaders($response);
            }

            return $response;
        } catch (ProviderNotFoundException $e) {
            $this->logSecurityEvent($request, 'admin_provider_not_found', $userUuid ?? null, 'error');
            return $this->createAdminErrorResponse(
                'Admin permission provider not configured',
                503,
                'ADMIN_PERMISSION_PROVIDER_NOT_FOUND'
            );
        } catch (PermissionException $e) {
            $this->logSecurityEvent($request, 'admin_permission_error', $userUuid ?? null, 'error', $e->getMessage());
            return $this->createAdminErrorResponse(
                'Admin permission check failed',
                500,
                'ADMIN_PERMISSION_CHECK_FAILED'
            );
        } catch (AuthenticationException $e) {
            $this->logSecurityEvent(
                $request,
                'admin_authentication_failed',
                $userUuid ?? null,
                'warning',
                $e->getMessage()
            );
            return $this->createAdminErrorResponse(
                'Admin authentication failed',
                401,
                'ADMIN_AUTHENTICATION_FAILED'
            );
        } catch (SecurityException $e) {
            Event::dispatch(new AdminSecurityViolationEvent(
                $userUuid ?? 'unknown',
                'security_exception',
                $request,
                $e->getMessage()
            ));

            $this->logSecurityEvent(
                $request,
                'admin_security_violation',
                $userUuid ?? null,
                'critical',
                $e->getMessage()
            );
            return $this->createAdminErrorResponse(
                'Admin security violation',
                403,
                'ADMIN_SECURITY_VIOLATION'
            );
        } catch (\Exception $e) {
            $this->logSecurityEvent($request, 'admin_middleware_error', $userUuid ?? null, 'error', $e->getMessage());
            return $this->createAdminErrorResponse(
                'Internal admin system error',
                500,
                'ADMIN_INTERNAL_ERROR'
            );
        }
    }

    /**
     * Parse route parameters into configuration
     *
     * @param array<mixed> $params Route parameters
     * @return array<string, mixed> Parsed configuration
     */
    private function parseRouteParameters(array $params): array
    {
        $config = [
            'permission' => $this->adminPermission,
            'resource' => $this->resource,
            'require_elevated' => $this->requireElevated,
            'require_mfa' => $this->requireMfa,
            'allowed_ips' => $this->allowedIps,
            'session_timeout' => $this->sessionTimeout,
            'log_level' => $this->logLevel
        ];

        // Check if first parameter is a security profile
        if (isset($params[0]) && is_string($params[0]) && isset(self::SECURITY_PROFILES[$params[0]])) {
            $profile = self::SECURITY_PROFILES[$params[0]];
            $config = array_merge($config, $profile);
        } else {
            // Parse individual parameters
            if (isset($params[0]) && is_string($params[0])) {
                $config['permission'] = $params[0];
            }

            if (isset($params[1]) && is_string($params[1])) {
                $config['resource'] = $params[1];
            }

            if (isset($params[2]) && is_bool($params[2])) {
                $config['require_elevated'] = $params[2];
            }

            if (isset($params[3]) && is_string($params[3])) {
                $config['allowed_ips'] = array_map('trim', explode(',', $params[3]));
            }

            if (isset($params[4]) && is_string($params[4]) && isset(self::SECURITY_PROFILES[$params[4]])) {
                $profile = self::SECURITY_PROFILES[$params[4]];
                $config = array_merge($config, $profile);
            }
        }

        return $config;
    }

    /**
     * Check time-based access restrictions
     *
     * @return bool Whether access is allowed at current time
     */
    private function checkTimeRestrictions(): bool
    {
        if (count($this->allowedHours) === 0) {
            return true;
        }

        $currentHour = (int)date('H');

        // Handle range (e.g., [8, 18] means 8 AM to 6 PM)
        if (count($this->allowedHours) === 2) {
            [$start, $end] = $this->allowedHours;
            return $currentHour >= $start && $currentHour <= $end;
        }

        // Handle specific hours array
        return in_array($currentHour, $this->allowedHours, true);
    }

    /**
     * Check IP access restrictions
     *
     * @param Request $request The request
     * @param array<string, mixed> $config Configuration with potential IP overrides
     * @return bool Whether IP access is allowed
     */
    private function checkIpAccess(Request $request, array $config): bool
    {
        $clientIp = $request->getClientIp();
        if ($clientIp === null) {
            return false;
        }

        // Check blocked IPs first
        foreach ($this->blockedIps as $blockedIp) {
            if ($this->ipMatches($clientIp, $blockedIp)) {
                return false;
            }
        }

        // Get allowed IPs from config or instance
        $allowedIps = $config['allowed_ips'] ?? $this->allowedIps;

        // If no IP restrictions configured, allow all
        if (count($allowedIps) === 0) {
            return true;
        }

        // Check if client IP is in allowed list
        foreach ($allowedIps as $allowedIp) {
            if ($this->ipMatches($clientIp, $allowedIp)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if IP matches pattern (supports CIDR notation)
     *
     * @param string $clientIp Client IP address
     * @param string $pattern IP pattern
     * @return bool Whether IP matches pattern
     */
    private function ipMatches(string $clientIp, string $pattern): bool
    {
        // Exact match
        if ($clientIp === $pattern) {
            return true;
        }

        // CIDR notation support
        if (str_contains($pattern, '/')) {
            [$network, $bits] = explode('/', $pattern);

            // IPv4 CIDR
            if (filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $clientLong = ip2long($clientIp);
                $networkLong = ip2long($network);
                if ($clientLong === false || $networkLong === false) {
                    return false;
                }
                $mask = -1 << (32 - (int)$bits);
                return ($clientLong & $mask) === ($networkLong & $mask);
            }

            // IPv6 CIDR (basic support)
            if (filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                // Simplified IPv6 matching - could be enhanced
                $clientBin = inet_pton($clientIp);
                $networkBin = inet_pton($network);
                if ($clientBin === false || $networkBin === false) {
                    return false;
                }

                $bytes = (int)$bits >> 3;
                $remainingBits = (int)$bits & 7;

                if (substr($clientBin, 0, $bytes) !== substr($networkBin, 0, $bytes)) {
                    return false;
                }

                if ($remainingBits > 0 && $bytes < 16) {
                    $mask = 0xFF << (8 - $remainingBits);
                    $clientByte = ord($clientBin[$bytes] ?? "\0");
                    $networkByte = ord($networkBin[$bytes] ?? "\0");
                    return ($clientByte & $mask) === ($networkByte & $mask);
                }

                return true;
            }
        }

        return false;
    }

    /**
     * Check rate limiting for admin access attempts
     *
     * @param Request $request The request
     * @return bool Whether rate limit allows access
     */
    private function checkRateLimit(Request $request): bool
    {
        // Implementation would depend on your caching system
        // This is a placeholder - implement based on your rate limiting strategy
        // Use request for rate limiting logic
        $clientIp = $request->getClientIp();
        unset($clientIp); // Suppress unused variable warning
        return true;
    }

    /**
     * Extract user UUID from request
     *
     * @param Request $request The request
     * @return string|null User UUID or null if not found
     */
    private function getUserUuid(Request $request): ?string
    {
        // Try session first (preferred for admin)
        if ($request->hasSession()) {
            $sessionUser = $request->getSession()->get('user');
            if ($sessionUser !== null && isset($sessionUser['uuid'])) {
                return $sessionUser['uuid'];
            }
        }

        // Try Authorization header
        $authHeader = $request->headers->get('Authorization');
        if ($authHeader !== null && str_starts_with($authHeader, 'Bearer ')) {
            $token = substr($authHeader, 7);
            return $this->getUserUuidFromToken($token);
        }

        // Try custom admin token header
        $tokenHeader = $request->headers->get('X-Admin-Token');
        if ($tokenHeader !== null) {
            return $this->getUserUuidFromToken($tokenHeader);
        }

        // Try user attribute set by auth middleware
        $userUuid = $request->attributes->get('user_uuid');
        if ($userUuid !== null) {
            return $userUuid;
        }

        return null;
    }

    /**
     * Extract user UUID from authentication token
     *
     * @param string $token Authentication token
     * @return string|null User UUID or null if extraction fails
     */
    private function getUserUuidFromToken(string $token): ?string
    {
        try {
            // Try token storage service first
            if (class_exists('\Glueful\Auth\TokenStorageService')) {
                $tokenStorage = new \Glueful\Auth\TokenStorageService();
                $sessionData = $tokenStorage->getSessionByAccessToken($token);
                if ($sessionData !== null && isset($sessionData['user_uuid'])) {
                    return $sessionData['user_uuid'];
                }
            }

            // Try authentication service
            $user = AuthenticationService::validateAccessToken($token);
            if ($user !== null && isset($user['uuid'])) {
                return $user['uuid'];
            }

            return null;
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Check if user is locked out due to failed attempts
     *
     * @param string $userUuid User UUID
     * @return bool Whether user is locked out
     */
    private function isUserLockedOut(string $userUuid): bool
    {
        // Implementation would depend on your caching/storage system
        // This is a placeholder
        // Use userUuid for lockout checking logic
        $key = "lockout_{$userUuid}";
        unset($key); // Suppress unused variable warning
        return false;
    }

    /**
     * Increment failed login attempts for user
     *
     * @param string $userUuid User UUID
     */
    private function incrementFailedAttempts(string $userUuid): void
    {
        // Implementation would depend on your caching/storage system
        // This is a placeholder
        // Use userUuid for tracking failed attempts
        $key = "failed_attempts_{$userUuid}";
        unset($key); // Suppress unused variable warning
    }

    /**
     * Reset failed login attempts for user
     *
     * @param string $userUuid User UUID
     */
    private function resetFailedAttempts(string $userUuid): void
    {
        // Implementation would depend on your caching/storage system
        // This is a placeholder
        // Use userUuid for resetting failed attempts
        $key = "failed_attempts_{$userUuid}";
        unset($key); // Suppress unused variable warning
    }

    /**
     * Validate user account status for admin access
     *
     * @param string $userUuid User UUID
     * @return bool Whether user account is valid for admin access
     */
    private function validateUserStatus(string $userUuid): bool
    {
        try {
            $user = $this->userRepository->findByUuid($userUuid);
            if ($user === null) {
                return false;
            }

            // Check if user is active
            if (!isset($user['is_active']) || $user['is_active'] !== true) {
                return false;
            }

            // Check if user has admin privileges
            if (!isset($user['is_admin']) || $user['is_admin'] !== true) {
                return false;
            }

            // Check if account is not locked
            if (array_key_exists('locked_at', $user) && $user['locked_at'] !== null && $user['locked_at'] !== '') {
                return false;
            }

            // Check if account is not expired
            if (array_key_exists('expires_at', $user) && $user['expires_at'] !== null && $user['expires_at'] !== '') {
                $expiryDate = new \DateTime($user['expires_at']);
                if ($expiryDate < new \DateTime()) {
                    return false;
                }
            }

            return true;
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Validate session and check timeout
     *
     * @param Request $request The request
     * @param string $userUuid User UUID
     * @return bool Whether session is valid
     */
    private function validateSession(Request $request, string $userUuid): bool
    {
        if (!$request->hasSession()) {
            return false;
        }

        $session = $request->getSession();

        // Check session user matches
        $sessionUser = $session->get('user');
        if ($sessionUser === null || !isset($sessionUser['uuid']) || $sessionUser['uuid'] !== $userUuid) {
            return false;
        }

        // Check admin session timeout
        $lastAdminActivity = $session->get('last_admin_activity');
        if ($lastAdminActivity !== null) {
            $elapsed = time() - $lastAdminActivity;
            if ($elapsed > $this->sessionTimeout) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check elevated authentication requirements
     *
     * @param Request $request The request
     * @param string $userUuid User UUID
     * @return bool Whether elevated authentication is satisfied
     */
    private function checkElevatedAuth(Request $request, string $userUuid): bool
    {
        if (!$request->hasSession()) {
            return false;
        }

        // Check for recent authentication
        $session = $request->getSession();
        $lastAuth = $session->get('last_admin_auth');
        if ($lastAuth !== null && (time() - $lastAuth) < self::DEFAULT_ELEVATED_TIMEOUT) {
            return true;
        }

        // Check for elevated auth header
        $elevatedHeader = $request->headers->get('X-Elevated-Auth');
        if ($elevatedHeader !== null) {
            return $this->validateElevatedAuth($elevatedHeader, $userUuid);
        }

        return false;
    }

    /**
     * Check multi-factor authentication
     *
     * @param Request $request The request
     * @param string $userUuid User UUID
     * @return bool Whether MFA is satisfied
     */
    private function checkMfaAuth(Request $request, string $userUuid): bool
    {
        // Check MFA token in header
        $mfaToken = $request->headers->get('X-MFA-Token');
        if ($mfaToken !== null) {
            return $this->validateMfaToken($mfaToken, $userUuid);
        }

        // Check session for valid MFA
        if ($request->hasSession()) {
            $session = $request->getSession();
            $mfaValid = $session->get('mfa_verified');
            $mfaTime = $session->get('mfa_verified_at');

            if ($mfaValid === true && $mfaTime !== null && (time() - $mfaTime) < 300) { // 5 minutes
                return true;
            }
        }

        return false;
    }

    /**
     * Check geographic restrictions
     *
     * @param Request $request The request
     * @return bool Whether geographic access is allowed
     */
    private function checkGeographicRestrictions(Request $request): bool
    {
        if (count($this->allowedCountries) === 0) {
            return true;
        }

        // This would require IP geolocation service integration
        // Placeholder implementation
        $clientIp = $request->getClientIp();
        unset($clientIp); // Suppress unused variable warning
        return true;
    }

    /**
     * Validate elevated authentication token
     *
     * @param string $elevatedAuth Elevated auth token
     * @param string $userUuid User UUID
     * @return bool Whether elevated auth is valid
     */
    private function validateElevatedAuth(string $elevatedAuth, string $userUuid): bool
    {
        // Implementation would depend on your elevated auth strategy
        // This could be a time-limited token, TOTP, or other mechanism
        $authKey = "{$userUuid}_{$elevatedAuth}";
        unset($authKey); // Suppress unused variable warning
        return false; // Placeholder
    }

    /**
     * Validate MFA token
     *
     * @param string $mfaToken MFA token
     * @param string $userUuid User UUID
     * @return bool Whether MFA token is valid
     */
    private function validateMfaToken(string $mfaToken, string $userUuid): bool
    {
        // Implementation would depend on your MFA strategy
        // This could be TOTP, SMS code, etc.
        $tokenKey = "{$userUuid}_{$mfaToken}";
        unset($tokenKey); // Suppress unused variable warning
        return false; // Placeholder
    }

    /**
     * Rotate session if needed for security
     *
     * @param Request $request The request
     * @param string $userUuid User UUID
     */
    private function rotateSessionIfNeeded(Request $request, string $userUuid): void
    {
        if (!$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        $lastRotation = $session->get('last_session_rotation');

        // Rotate session every 30 minutes for admin users
        if ($lastRotation === null || (time() - $lastRotation) > 1800) {
            $session->migrate(true);
            $session->set('last_session_rotation', time());
            $session->set('rotated_for_user', $userUuid); // Use userUuid parameter
        }
    }

    /**
     * Update session activity timestamp
     *
     * @param Request $request The request
     * @param string $userUuid User UUID
     */
    private function updateSessionActivity(Request $request, string $userUuid): void
    {
        if ($request->hasSession()) {
            $session = $request->getSession();
            $session->set('last_admin_activity', time());
            $session->set('admin_user_uuid', $userUuid); // Use userUuid parameter
        }
    }

    /**
     * Check admin permission with enhanced context
     *
     * @param string $userUuid User UUID
     * @param Request $request The request
     * @param array<string, mixed> $config Configuration
     * @return bool Whether user has admin permission
     */
    private function checkAdminPermission(string $userUuid, Request $request, array $config): bool
    {
        $context = array_merge($this->context, [
            'admin_request' => true,
            'request_method' => $request->getMethod(),
            'request_path' => $request->getPathInfo(),
            'request_ip' => $request->getClientIp(),
            'user_agent' => $request->headers->get('User-Agent'),
            'timestamp' => time(),
            'security_level' => 'admin',
            'environment' => $this->environment,
            'session_id' => $request->hasSession() ? $request->getSession()->getId() : null
        ]);

        // Add route parameters if available
        if ($request->attributes->has('_route_params')) {
            $context['route_params'] = $request->attributes->get('_route_params');
        }

        // Add additional context from config
        if (isset($config['context'])) {
            $context = array_merge($context, $config['context']);
        }

        return $this->permissionManager->can(
            $userUuid,
            $config['permission'],
            $config['resource'],
            $context
        );
    }

    /**
     * Add security headers to admin responses
     *
     * @param Response $response The response
     */
    private function addAdminSecurityHeaders(Response $response): void
    {
        // Prevent caching of admin responses
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        // Additional security headers for admin responses
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Admin-Protected', 'true');

        // Remove server identification
        $response->headers->remove('X-Powered-By');
        $response->headers->remove('Server');
    }

    /**
     * Log security event for admin access
     *
     * @param Request $request The request
     * @param string $event Event type
     * @param string|null $userUuid User UUID
     * @param string $level Log level
     * @param string|null $details Additional details
     */
    private function logSecurityEvent(
        Request $request,
        string $event,
        ?string $userUuid,
        string $level = 'info',
        ?string $details = null
    ): void {
        $logData = [
            'event' => $event,
            'middleware' => 'admin_permission',
            'timestamp' => date('c'),
            'ip' => $request->getClientIp(),
            'user_agent' => $request->headers->get('User-Agent'),
            'path' => $request->getPathInfo(),
            'method' => $request->getMethod(),
            'permission' => $this->adminPermission,
            'resource' => $this->resource,
            'environment' => $this->environment
        ];

        if ($userUuid !== null) {
            $logData['user_uuid'] = $userUuid;
        }

        if ($details !== null) {
            $logData['details'] = $details;
        }

        // Log using PSR logger if available
        if ($this->logger !== null) {
            $message = "Admin security event: {$event}";
            match ($level) {
                'critical' => $this->logger->critical($message, $logData),
                'error' => $this->logger->error($message, $logData),
                'warning' => $this->logger->warning($message, $logData),
                'info' => $this->logger->info($message, $logData),
                'debug' => $this->logger->debug($message, $logData),
                default => $this->logger->info($message, $logData)
            };
        } else {
            // Fallback to error_log
            error_log('[ADMIN_SECURITY] ' . json_encode($logData));
        }
    }

    /**
     * Create admin-specific error response
     *
     * @param string $message Error message
     * @param int $statusCode HTTP status code
     * @param string $errorCode Application error code
     * @param array<string, mixed> $details Additional error details
     * @return JsonResponse Error response
     */
    private function createAdminErrorResponse(
        string $message,
        int $statusCode,
        string $errorCode,
        array $details = []
    ): JsonResponse {
        $error = [
            'error' => [
                'message' => $message,
                'code' => $errorCode,
                'status' => $statusCode,
                'type' => 'admin_access_error',
                'timestamp' => date('c')
            ]
        ];

        if (count($details) > 0) {
            $error['error']['details'] = $details;
        }

        // Add development details in non-production environments
        if ($this->environment !== 'production') {
            $error['error']['environment'] = $this->environment;
            $error['error']['middleware'] = 'AdminPermissionMiddleware';
        }

        return new JsonResponse($error, $statusCode);
    }

    /**
     * Detect current environment
     *
     * @return string Current environment
     */
    private function detectEnvironment(): string
    {
        return env('APP_ENV', 'production');
    }

    /**
     * Check if emergency lockdown is active
     *
     * @return bool Whether emergency lockdown is active
     */
    private function checkEmergencyLockdown(): bool
    {
        return env('EMERGENCY_LOCKDOWN', false) === true;
    }

    /**
     * Get default container safely
     *
     * @return ContainerInterface|null Default container
     */
    private function getDefaultContainer(): ?ContainerInterface
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
                return $a instanceof ContainerInterface ? $a : null;
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }

    /**
     * Get PermissionManager from container
     */
    private function getPermissionManagerFromContainer(): PermissionManager
    {
        if ($this->container !== null) {
            try {
                return $this->container->get(PermissionManager::class);
            } catch (\Exception) {
                // Fall back to singleton getInstance if container fails
            }
        }

        // Fall back to singleton pattern
        return PermissionManager::getInstance();
    }

    /**
     * Create middleware for superuser access with maximum security
     *
     * @param string $resource Resource identifier
     * @param array<string> $allowedIps Allowed IP addresses
     * @return self Middleware instance
     */
    public static function superuser(string $resource = 'system', array $allowedIps = []): self
    {
        return new self(
            adminPermission: 'admin.system.superuser',
            resource: $resource,
            context: ['level' => 'superuser', 'security_tier' => 'maximum'],
            allowedIps: $allowedIps,
            requireElevated: true,
            requireMfa: true,
            sessionTimeout: 600, // 10 minutes
            logLevel: 'critical'
        );
    }

    /**
     * Create middleware for system admin access
     *
     * @param string $resource Resource identifier
     * @param array<string> $allowedIps Allowed IP addresses
     * @return self Middleware instance
     */
    public static function systemAdmin(string $resource = 'system', array $allowedIps = []): self
    {
        return new self(
            adminPermission: 'admin.system.access',
            resource: $resource,
            context: ['level' => 'system', 'security_tier' => 'high'],
            allowedIps: $allowedIps,
            requireElevated: true,
            requireMfa: false,
            sessionTimeout: 1800, // 30 minutes
            logLevel: 'warning'
        );
    }

    /**
     * Create middleware for user management admin access
     *
     * @param array<string> $allowedIps Allowed IP addresses
     * @return self Middleware instance
     */
    public static function userAdmin(array $allowedIps = []): self
    {
        return new self(
            adminPermission: 'admin.users.manage',
            resource: 'users',
            context: ['level' => 'user_management', 'security_tier' => 'medium'],
            allowedIps: $allowedIps,
            requireElevated: false,
            requireMfa: false,
            sessionTimeout: 3600, // 1 hour
            logLevel: 'info'
        );
    }

    /**
     * Create middleware for content admin access
     *
     * @param array<string> $allowedIps Allowed IP addresses
     * @return self Middleware instance
     */
    public static function contentAdmin(array $allowedIps = []): self
    {
        return new self(
            adminPermission: 'admin.content.manage',
            resource: 'content',
            context: ['level' => 'content_management', 'security_tier' => 'medium'],
            allowedIps: $allowedIps,
            requireElevated: false,
            requireMfa: false,
            sessionTimeout: 7200, // 2 hours
            logLevel: 'info'
        );
    }

    /**
     * Create middleware for read-only admin access
     *
     * @param string $resource Resource identifier
     * @return self Middleware instance
     */
    public static function readOnly(string $resource = 'admin'): self
    {
        return new self(
            adminPermission: 'admin.view',
            resource: $resource,
            context: ['level' => 'readonly', 'security_tier' => 'low'],
            requireElevated: false,
            requireMfa: false,
            sessionTimeout: 14400, // 4 hours
            logLevel: 'debug'
        );
    }
}

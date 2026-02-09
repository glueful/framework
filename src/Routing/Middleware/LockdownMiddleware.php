<?php

declare(strict_types=1);

namespace Glueful\Routing\Middleware;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Routing\RouteMiddleware;
use Glueful\Http\Exceptions\Domain\SecurityException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Psr\Log\LoggerInterface;
use Psr\Container\ContainerInterface;

/**
 * Emergency Lockdown Middleware for Next-Gen Router
 *
 * Native Glueful middleware that enforces emergency security lockdown restrictions:
 * - System-wide lockdown mode enforcement
 * - IP-based access blocking with automatic expiration
 * - Granular endpoint access control with wildcard patterns
 * - Maintenance page generation for web requests
 * - JSON error responses for API requests
 * - Automatic cleanup of expired lockdowns and IP blocks
 * - Integration with existing lockdown configuration and CLI tools
 *
 * Features:
 * - File-based lockdown state management
 * - Configurable endpoint restrictions by severity level
 * - Real client IP detection through various proxy headers
 * - Separate handling for web vs API requests
 * - Comprehensive HTML maintenance pages
 * - Automatic expiration and cleanup
 * - Integration with existing security infrastructure
 *
 * Usage examples:
 *
 * // Apply to all routes for system-wide protection
 * $router->middleware(['lockdown']);
 *
 * // Apply to specific route groups
 * $router->group(['middleware' => ['lockdown']], function($router) {
 *     $router->get('/admin/*', [AdminController::class, 'index']);
 * });
 *
 * // CLI activation via existing command
 * php glueful security:lockdown --enable --severity=high
 *
 * Configuration via config/lockdown.php:
 * - Severity-based endpoint restrictions
 * - IP blocking thresholds and whitelists
 * - Maintenance mode messages and settings
 * - Automatic recovery and cleanup settings
 */
class LockdownMiddleware implements RouteMiddleware
{
    private ?ApplicationContext $context;

    /** @var LoggerInterface Logger instance */
    private LoggerInterface $logger;

    /** @var ContainerInterface|null DI Container */
    private ?ContainerInterface $container;

    /** @var string Current environment */
    private string $environment;

    /** @var array<string, mixed> Lockdown configuration */
    private array $lockdownConfig;

    /**
     * Create lockdown middleware
     *
     * @param LoggerInterface|null $logger Logger instance
     * @param ContainerInterface|null $container DI Container instance
     * @param ApplicationContext|null $context Application context
     */
    public function __construct(
        ?LoggerInterface $logger = null,
        ?ContainerInterface $container = null,
        ?ApplicationContext $context = null
    ) {
        $this->context = $context;
        $this->container = $container ?? $this->getDefaultContainer();
        $this->logger = $logger ?? $this->getLogger();
        $this->environment = $this->detectEnvironment();
        $this->lockdownConfig = $this->loadLockdownConfig();
    }

    /**
     * Handle lockdown middleware
     *
     * @param Request $request The incoming request
     * @param callable $next Next handler in the pipeline
     * @param mixed ...$params Additional parameters from route configuration
     * @return mixed Response with lockdown enforcement applied
     */
    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        try {
            // Check if system is in lockdown mode
            if (!$this->isSystemInLockdown()) {
                return $next($request);
            }

            $clientIp = $this->getClientIP($request);
            $requestPath = $this->getRequestPath($request);

            // Log lockdown access attempt
            $this->logLockdownAccess($request, $clientIp, $requestPath);

            // Check if IP is blocked
            if ($this->isIPBlocked($clientIp)) {
                $this->logSecurityViolation($request, 'ip_blocked', $clientIp);
                throw new SecurityException('Access denied due to security restrictions', 403);
            }

            // Check if endpoint is allowed during lockdown
            if (!$this->isEndpointAllowed($requestPath)) {
                $this->logSecurityViolation($request, 'endpoint_blocked', $clientIp, [
                    'path' => $requestPath,
                    'lockdown_active' => true
                ]);

                // Return maintenance response for web requests
                if ($this->isWebRequest($request)) {
                    return $this->getMaintenanceResponse();
                }

                // Return JSON error for API requests
                return $this->getLockdownApiResponse();
            }

            // Add lockdown context to request for downstream middleware/controllers
            $request->attributes->set('lockdown_active', true);
            $request->attributes->set('lockdown_severity', $this->getLockdownSeverity());

            return $next($request);
        } catch (SecurityException $e) {
            // Log security exceptions
            $this->logger->critical('Lockdown security exception', [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'client_ip' => $this->getClientIP($request),
                'request_path' => $this->getRequestPath($request),
                'user_agent' => $request->headers->get('User-Agent'),
                'timestamp' => date('c'),
            ]);

            // Return appropriate error response
            if ($this->isWebRequest($request)) {
                return new Response('Access Denied', 403, [
                    'Content-Type' => 'text/plain'
                ]);
            }

            return new JsonResponse([
                'error' => [
                    'message' => 'Access denied',
                    'code' => 'ACCESS_DENIED',
                    'status' => 403
                ]
            ], 403);
        } catch (\Exception $e) {
            $this->logger->error('Lockdown middleware error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Continue processing on unexpected errors to avoid breaking the system
            return $next($request);
        }
    }

    /**
     * Check if system is in lockdown mode
     *
     * @return bool True if system is in lockdown
     */
    private function isSystemInLockdown(): bool
    {
        $storagePath = $this->getStoragePath();
        $maintenanceFile = $storagePath . 'framework/maintenance.json';

        if (!file_exists($maintenanceFile)) {
            return false;
        }

        $maintenanceData = json_decode(file_get_contents($maintenanceFile), true);

        if ($maintenanceData === null || ($maintenanceData['enabled'] ?? false) !== true) {
            return false;
        }

        // Check if lockdown has expired
        if (isset($maintenanceData['end_time']) && time() > $maintenanceData['end_time']) {
            $this->disableLockdown();
            return false;
        }

        return $maintenanceData['lockdown_mode'] ?? false;
    }

    /**
     * Get lockdown severity level
     *
     * @return string Severity level (low, medium, high, critical)
     */
    private function getLockdownSeverity(): string
    {
        $storagePath = $this->getStoragePath();
        $maintenanceFile = $storagePath . 'framework/maintenance.json';

        if (!file_exists($maintenanceFile)) {
            return 'medium';
        }

        $maintenanceData = json_decode(file_get_contents($maintenanceFile), true);
        return $maintenanceData['severity'] ?? 'medium';
    }

    /**
     * Get client IP address with comprehensive proxy support
     *
     * @param Request $request Request object
     * @return string Client IP address
     */
    private function getClientIP(Request $request): string
    {
        // Try Symfony's built-in method first
        $clientIp = $request->getClientIp();
        if ($clientIp !== null) {
            return $clientIp;
        }

        // Fallback to manual header checking for additional proxy support
        $headers = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_X_FORWARDED_FOR',      // Standard proxy
            'HTTP_X_REAL_IP',            // Nginx proxy
            'HTTP_X_FORWARDED',          // Proxy
            'HTTP_FORWARDED_FOR',        // Proxy
            'HTTP_FORWARDED',            // RFC 7239
            'REMOTE_ADDR'                // Direct connection
        ];

        foreach ($headers as $header) {
            if (isset($_SERVER[$header]) && $_SERVER[$header] !== '') {
                $ip = $_SERVER[$header];

                // Handle comma-separated list (X-Forwarded-For)
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }

                // Validate IP and exclude private/reserved ranges for public IPs
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }

                // Allow private IPs if that's all we have
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    /**
     * Get normalized request path
     *
     * @param Request $request Request object
     * @return string Normalized request path
     */
    private function getRequestPath(Request $request): string
    {
        $path = $request->getPathInfo();
        return $path !== '' ? $path : '/';
    }

    /**
     * Check if IP address is blocked
     *
     * @param string $ip IP address to check
     * @return bool True if IP is blocked
     */
    private function isIPBlocked(string $ip): bool
    {
        // Check whitelist first
        $whitelist = $this->lockdownConfig['ip_blocking']['whitelist'] ?? [];
        if (in_array($ip, $whitelist, true)) {
            return false;
        }

        $storagePath = $this->getStoragePath();
        $blockedIpsFile = $storagePath . 'blocked_ips.json';

        if (!file_exists($blockedIpsFile)) {
            return false;
        }

        $blockedIps = json_decode(file_get_contents($blockedIpsFile), true) ?? [];

        if (!isset($blockedIps[$ip])) {
            return false;
        }

        $blockData = $blockedIps[$ip];

        // Check if block has expired
        if (isset($blockData['expires_at']) && time() > $blockData['expires_at']) {
            $this->unblockIP($ip);
            return false;
        }

        return true;
    }

    /**
     * Check if endpoint is allowed during lockdown
     *
     * @param string $path Request path
     * @return bool True if endpoint is allowed
     */
    private function isEndpointAllowed(string $path): bool
    {
        // Always allow configured endpoints
        $alwaysAllowed = $this->lockdownConfig['always_allowed_endpoints'] ?? [];
        foreach ($alwaysAllowed as $allowedPath) {
            if ($this->pathMatches($path, $allowedPath)) {
                return true;
            }
        }

        // Get current severity level
        $severity = $this->getLockdownSeverity();

        // Get restrictions for current severity
        $restrictions = $this->lockdownConfig['endpoint_restrictions'][$severity] ?? [];

        // Include restrictions from lower severity levels
        $severityLevels = ['low', 'medium', 'high', 'critical'];
        $currentIndex = array_search($severity, $severityLevels, true);

        if ($currentIndex !== false) {
            for ($i = 0; $i <= $currentIndex; $i++) {
                $levelRestrictions = $this->lockdownConfig['endpoint_restrictions'][$severityLevels[$i]] ?? [];
                $restrictions = array_merge($restrictions, $levelRestrictions);
            }
        }

        // Check if path matches any restricted pattern
        foreach ($restrictions as $restrictedPath) {
            if ($this->pathMatches($path, $restrictedPath)) {
                return false;
            }
        }

        return true; // Allow by default if not explicitly restricted
    }

    /**
     * Check if path matches pattern (supports wildcards)
     *
     * @param string $path Actual request path
     * @param string $pattern Pattern to match against
     * @return bool True if path matches pattern
     */
    private function pathMatches(string $path, string $pattern): bool
    {
        if ($pattern === '*') {
            return true;
        }

        if ($pattern === $path) {
            return true;
        }

        // Handle wildcard patterns like /api/admin/*
        if (str_ends_with($pattern, '/*')) {
            $prefix = substr($pattern, 0, -2);
            return str_starts_with($path, $prefix);
        }

        // Handle wildcard patterns like */admin
        if (str_starts_with($pattern, '*/')) {
            $suffix = substr($pattern, 2);
            return str_ends_with($path, $suffix);
        }

        return false;
    }

    /**
     * Check if request is a web request (vs API)
     *
     * @param Request $request Request object
     * @return bool True if web request
     */
    private function isWebRequest(Request $request): bool
    {
        $path = $this->getRequestPath($request);

        // Use helper to check if this is an API path (uses configured prefix)
        if ($this->context !== null && function_exists('is_api_path') && is_api_path($this->context, $path)) {
            return false;
        }

        // Fallback check for when helper isn't loaded
        if (str_starts_with($path, '/api/')) {
            return false;
        }

        // Check Accept header for JSON preference
        $acceptHeader = $request->headers->get('Accept', '');
        if (
            str_contains($acceptHeader, 'application/json') &&
            !str_contains($acceptHeader, 'text/html')
        ) {
            return false;
        }

        // Check Content-Type for JSON requests
        $contentType = $request->headers->get('Content-Type', '');
        if (str_contains($contentType, 'application/json')) {
            return false;
        }

        return true;
    }

    /**
     * Get maintenance mode response for web requests
     *
     * @return Response Maintenance page response
     */
    private function getMaintenanceResponse(): Response
    {
        $storagePath = $this->getStoragePath();
        $maintenanceFile = $storagePath . 'framework/maintenance.json';

        $maintenanceData = [];
        if (file_exists($maintenanceFile)) {
            $maintenanceData = json_decode(file_get_contents($maintenanceFile), true) ?? [];
        }

        $config = $this->lockdownConfig['maintenance_mode'] ?? [];
        $message = $maintenanceData['message'] ??
                  $config['default_message'] ??
                  'System temporarily unavailable for maintenance';
        $endTime = $maintenanceData['end_time'] ?? null;
        $severity = $maintenanceData['severity'] ?? 'medium';

        $html = $this->generateMaintenanceHTML($message, $endTime, $severity);

        $response = new Response($html, 503, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff'
        ]);

        // Add Retry-After header if configured
        $retryAfterEnabled = (bool)($config['retry_after_header'] ?? true);
        if ($retryAfterEnabled) {
            if ($endTime !== null) {
                $retryAfter = max(60, $endTime - time()); // Minimum 1 minute
                $response->headers->set('Retry-After', (string)$retryAfter);
            } else {
                $response->headers->set('Retry-After', '3600'); // Default 1 hour
            }
        }

        return $response;
    }

    /**
     * Get lockdown API response
     *
     * @return JsonResponse Lockdown error response
     */
    private function getLockdownApiResponse(): JsonResponse
    {
        $storagePath = $this->getStoragePath();
        $maintenanceFile = $storagePath . 'framework/maintenance.json';

        $maintenanceData = [];
        if (file_exists($maintenanceFile)) {
            $maintenanceData = json_decode(file_get_contents($maintenanceFile), true) ?? [];
        }

        $response = [
            'error' => [
                'message' => 'System is currently in security lockdown mode',
                'code' => 'SECURITY_LOCKDOWN_ACTIVE',
                'status' => 503,
                'type' => 'security_lockdown',
                'severity' => $maintenanceData['severity'] ?? 'medium'
            ]
        ];

        if (isset($maintenanceData['end_time'])) {
            $response['error']['expected_resolution'] = date('c', $maintenanceData['end_time']);
            $response['error']['retry_after'] = max(60, $maintenanceData['end_time'] - time());
        }

        return new JsonResponse($response, 503);
    }

    /**
     * Generate maintenance mode HTML page
     *
     * @param string $message Maintenance message
     * @param int|null $endTime Expected end time
     * @param string $severity Lockdown severity
     * @return string Complete HTML page
     */
    private function generateMaintenanceHTML(string $message, ?int $endTime, string $severity = 'medium'): string
    {
        $endTimeText = '';
        $config = $this->lockdownConfig['maintenance_mode'] ?? [];

        $showEndTime = (bool)($config['show_end_time'] ?? true);
        if ($endTime !== null && $showEndTime) {
            $endTimeText = '<p class="end-time">Expected to be resolved by: <strong>' .
                          date('Y-m-d H:i:s T', $endTime) . '</strong></p>';
        }

        $severityClass = $this->getSeverityClass($severity);
        $severityText = ucfirst($severity);
        $statusIcon = $this->getSeverityIcon($severity);

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>System Maintenance - Security Lockdown</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
            margin: 0; padding: 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
        }
        .container {
            max-width: 600px; background: white; padding: 40px;
            border-radius: 12px; box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            text-align: center; position: relative;
        }
        .status-badge {
            display: inline-block; padding: 8px 16px; border-radius: 20px;
            font-size: 14px; font-weight: 600; margin-bottom: 20px;
        }
        .severity-low { background: #fff3cd; color: #856404; }
        .severity-medium { background: #d1ecf1; color: #0c5460; }
        .severity-high { background: #f8d7da; color: #721c24; }
        .severity-critical { background: #f5c6cb; color: #491217; }
        
        h1 { color: #2c3e50; margin-bottom: 20px; font-size: 28px; }
        .icon { font-size: 48px; margin-bottom: 20px; }
        p { color: #666; line-height: 1.6; margin-bottom: 20px; font-size: 16px; }
        .message-box {
            background: #f8f9fa; border-left: 4px solid #007bff;
            padding: 20px; margin: 20px 0; text-align: left; border-radius: 4px;
        }
        .end-time { color: #495057; font-size: 15px; }
        .support-text { font-size: 14px; color: #6c757d; margin-top: 30px; }
        .security-notice {
            background: #fff3cd; border: 1px solid #ffeaa7;
            padding: 15px; border-radius: 6px; margin: 20px 0;
            border-left: 4px solid #ffc107;
        }
        .timestamp { font-size: 12px; color: #adb5bd; margin-top: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="status-badge {$severityClass}">{$severityText} Security Lockdown</div>
        <div class="icon">{$statusIcon}</div>
        <h1>System Maintenance</h1>
        
        <div class="message-box">
            <p><strong>{$message}</strong></p>
            {$endTimeText}
        </div>
        
        <div class="security-notice">
            <p><strong>🔒 Security Notice:</strong> This system is currently in emergency lockdown mode due to
            security maintenance or incident response procedures.</p>
        </div>
        
        <p>We apologize for any inconvenience. Our security team is working to resolve this issue as
        quickly as possible.</p>
        
        <p class="support-text">
            <strong>Need immediate assistance?</strong><br>
            If you believe you are seeing this message in error or require emergency access,
            please contact your system administrator.
        </p>
        
        <p class="timestamp">
            <small>Status checked at: {$this->getCurrentTimestamp()}</small>
        </p>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Get CSS class for severity level
     *
     * @param string $severity Severity level
     * @return string CSS class name
     */
    private function getSeverityClass(string $severity): string
    {
        return match ($severity) {
            'low' => 'severity-low',
            'medium' => 'severity-medium',
            'high' => 'severity-high',
            'critical' => 'severity-critical',
            default => 'severity-medium'
        };
    }

    /**
     * Get icon for severity level
     *
     * @param string $severity Severity level
     * @return string Icon emoji
     */
    private function getSeverityIcon(string $severity): string
    {
        return match ($severity) {
            'low' => '🔒',
            'medium' => '🛡️',
            'high' => '⚠️',
            'critical' => '🚨',
            default => '🛡️'
        };
    }

    /**
     * Get current timestamp for display
     *
     * @return string Formatted timestamp
     */
    private function getCurrentTimestamp(): string
    {
        return date('Y-m-d H:i:s T');
    }

    /**
     * Disable lockdown mode (cleanup expired lockdown)
     */
    private function disableLockdown(): void
    {
        $storagePath = $this->getStoragePath();

        // Remove maintenance file
        $maintenanceFile = $storagePath . 'framework/maintenance.json';
        if (file_exists($maintenanceFile)) {
            unlink($maintenanceFile);
        }

        // Remove lockdown routes if configured for auto-cleanup
        $cleanupEnabled = (bool)($this->lockdownConfig['auto_recovery']['cleanup_expired_lockdowns'] ?? true);
        if ($cleanupEnabled) {
            $lockdownRoutes = $storagePath . 'lockdown_routes.json';
            if (file_exists($lockdownRoutes)) {
                unlink($lockdownRoutes);
            }
        }

        $this->logger->info('Lockdown automatically disabled due to expiration', [
            'timestamp' => date('c'),
            'environment' => $this->environment
        ]);
    }

    /**
     * Unblock an IP address (cleanup expired block)
     *
     * @param string $ip IP address to unblock
     */
    private function unblockIP(string $ip): void
    {
        $storagePath = $this->getStoragePath();
        $blockedIpsFile = $storagePath . 'blocked_ips.json';

        if (!file_exists($blockedIpsFile)) {
            return;
        }

        $blockedIps = json_decode(file_get_contents($blockedIpsFile), true) ?? [];

        if (isset($blockedIps[$ip])) {
            unset($blockedIps[$ip]);
            file_put_contents($blockedIpsFile, json_encode($blockedIps, JSON_PRETTY_PRINT));

            $this->logger->info('IP address automatically unblocked due to expiration', [
                'ip' => $ip,
                'timestamp' => date('c')
            ]);
        }
    }

    /**
     * Log lockdown access attempt
     *
     * @param Request $request Request object
     * @param string $clientIp Client IP address
     * @param string $requestPath Request path
     */
    private function logLockdownAccess(Request $request, string $clientIp, string $requestPath): void
    {
        $logData = [
            'type' => 'lockdown_access_attempt',
            'client_ip' => $clientIp,
            'request_path' => $requestPath,
            'request_method' => $request->getMethod(),
            'user_agent' => $request->headers->get('User-Agent'),
            'lockdown_severity' => $this->getLockdownSeverity(),
            'timestamp' => date('c'),
            'environment' => $this->environment,
        ];

        // Add user context if available
        if ($request->attributes->has('user_uuid')) {
            $logData['user_uuid'] = $request->attributes->get('user_uuid');
        }

        $this->logger->info('Lockdown access attempt', $logData);
    }

    /**
     * Log security violation
     *
     * @param Request $request Request object
     * @param string $violationType Type of violation
     * @param string $clientIp Client IP address
     * @param array<string, mixed> $additional Additional data
     */
    private function logSecurityViolation(
        Request $request,
        string $violationType,
        string $clientIp,
        array $additional = []
    ): void {
        $logData = array_merge([
            'type' => 'lockdown_security_violation',
            'violation_type' => $violationType,
            'client_ip' => $clientIp,
            'request_path' => $this->getRequestPath($request),
            'request_method' => $request->getMethod(),
            'user_agent' => $request->headers->get('User-Agent'),
            'referer' => $request->headers->get('Referer'),
            'lockdown_severity' => $this->getLockdownSeverity(),
            'timestamp' => date('c'),
            'environment' => $this->environment,
        ], $additional);

        $this->logger->warning('Lockdown security violation', $logData);
    }

    /**
     * Load lockdown configuration
     *
     * @return array<string, mixed> Lockdown configuration
     */
    private function loadLockdownConfig(): array
    {
        try {
            return $this->getConfig('lockdown', []);
        } catch (\Exception) {
            // Return default configuration if config loading fails
            return [
                'always_allowed_endpoints' => ['/health', '/status'],
                'endpoint_restrictions' => [
                    'low' => [],
                    'medium' => ['/api/admin/*'],
                    'high' => ['/api/admin/*', '/api/users/create'],
                    'critical' => ['*']
                ],
                'ip_blocking' => [
                    'whitelist' => ['127.0.0.1', '::1']
                ],
                'maintenance_mode' => [
                    'default_message' => 'System temporarily unavailable due to security maintenance',
                    'show_end_time' => true,
                    'retry_after_header' => true
                ],
                'auto_recovery' => [
                    'cleanup_expired_lockdowns' => true
                ]
            ];
        }
    }

    /**
     * Get storage path
     *
     * @return string Storage path with trailing slash
     */
    private function getStoragePath(): string
    {
        try {
            $path = $this->getConfig('app.paths.storage', './storage/');
            return rtrim($path, '/') . '/';
        } catch (\Exception) {
            return './storage/';
        }
    }

    /**
     * Detect current environment
     *
     * @return string Environment name
     */
    private function detectEnvironment(): string
    {
        return env('APP_ENV', 'production');
    }

    /**
     * Get logger instance
     *
     * @return LoggerInterface Logger instance
     */
    private function getLogger(): LoggerInterface
    {
        if ($this->container !== null) {
            try {
                return $this->container->get(LoggerInterface::class);
            } catch (\Exception) {
                // Fall through to create a default logger
            }
        }

        // Create a simple error_log based logger as fallback
        return new class implements LoggerInterface {
            public function emergency(string|\Stringable $message, array $context = []): void
            {
                $this->log('emergency', $message, $context);
            }

            public function alert(string|\Stringable $message, array $context = []): void
            {
                $this->log('alert', $message, $context);
            }

            public function critical(string|\Stringable $message, array $context = []): void
            {
                $this->log('critical', $message, $context);
            }

            public function error(string|\Stringable $message, array $context = []): void
            {
                $this->log('error', $message, $context);
            }

            public function warning(string|\Stringable $message, array $context = []): void
            {
                $this->log('warning', $message, $context);
            }

            public function notice(string|\Stringable $message, array $context = []): void
            {
                $this->log('notice', $message, $context);
            }

            public function info(string|\Stringable $message, array $context = []): void
            {
                $this->log('info', $message, $context);
            }

            public function debug(string|\Stringable $message, array $context = []): void
            {
                $this->log('debug', $message, $context);
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $contextStr = ($context !== []) ? ' ' . json_encode($context) : '';
                error_log("[{$level}] {$message}{$contextStr}");
            }
        };
    }

    /**
     * Get default container safely
     *
     * @return ContainerInterface|null Container instance
     */
    private function getDefaultContainer(): ?ContainerInterface
    {
        if ($this->context !== null && function_exists('container')) {
            try {
                $c = container($this->context);
                return $c;
            } catch (\Exception) {
                return null;
            }
        }

        if ($this->context !== null && function_exists('app')) {
            try {
                $a = app($this->context);
                return $a instanceof ContainerInterface ? $a : null;
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }

    private function getConfig(string $key, mixed $default = null): mixed
    {
        if ($this->context === null) {
            return $default;
        }

        return config($this->context, $key, $default);
    }

    /**
     * Create middleware for emergency lockdown
     *
     * @return self Middleware instance
     */
    public static function emergency(): self
    {
        return new self();
    }

    /**
     * Create middleware with custom configuration
     *
     * @param LoggerInterface|null $logger Custom logger
     * @return self Middleware instance
     */
    public static function withLogger(?LoggerInterface $logger): self
    {
        return new self($logger);
    }
}

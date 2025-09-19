<?php

declare(strict_types=1);

namespace Glueful\Routing\Middleware;

use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Psr\Log\LoggerInterface;
use Psr\Container\ContainerInterface;

/**
 * Request/Response Logging Middleware for Next-Gen Router
 *
 * Native Glueful middleware that provides comprehensive HTTP request and response
 * logging with configurable detail levels, security filtering, and performance metrics.
 *
 * Features:
 * - Configurable logging levels (request, response, both)
 * - Security-aware sensitive data filtering
 * - Performance metrics and slow request detection
 * - Structured logging with metadata
 * - Environment-aware logging configuration
 * - Memory and timing profiling
 * - Error and exception logging
 * - Request correlation tracking
 *
 * Security Features:
 * - Automatic redaction of sensitive headers and request data
 * - Configurable field filtering for PII protection
 * - IP address anonymization options
 * - Body content size limiting and truncation
 * - GDPR-compliant logging options
 *
 * Usage examples:
 *
 * // Basic request/response logging
 * $router->get('/api/users', [UserController::class, 'index'])
 *     ->middleware(['request_logging']);
 *
 * // Log requests only with headers
 * $router->post('/api/users', [UserController::class, 'create'])
 *     ->middleware(['request_logging:request,true,false']);
 *
 * // Log everything with bodies for debugging
 * $router->get('/api/debug', [DebugController::class, 'info'])
 *     ->middleware(['request_logging:both,true,true,debug']);
 *
 * // Performance monitoring for critical endpoints
 * $router->post('/api/payments', [PaymentController::class, 'process'])
 *     ->middleware(['request_logging:both,true,false,info,1000']);
 */
class RequestResponseLoggingMiddleware implements RouteMiddleware
{
    /** @var string Default log level */
    private const DEFAULT_LOG_LEVEL = 'info';

    /** @var int Default slow request threshold (ms) */
    private const DEFAULT_SLOW_THRESHOLD = 2000;

    /** @var int Default body size limit (bytes) */
    private const DEFAULT_BODY_SIZE_LIMIT = 10240; // 10KB

    /** @var array<string> Sensitive headers to redact */
    private const SENSITIVE_HEADERS = [
        'authorization',
        'x-api-key',
        'x-auth-token',
        'x-access-token',
        'cookie',
        'set-cookie',
        'x-csrf-token',
        'x-xsrf-token',
        'x-admin-token',
        'x-mfa-token',
        'x-elevated-auth',
    ];

    /** @var array<string> Sensitive request fields to redact */
    private const SENSITIVE_FIELDS = [
        'password',
        'secret',
        'token',
        'api_key',
        'access_token',
        'refresh_token',
        'client_secret',
        'private_key',
        'credit_card',
        'ssn',
        'social_security_number',
        'cvv',
        'cvc',
        'pin',
        'otp',
    ];

    /** @var string What to log (request, response, both) */
    private string $logMode;

    /** @var bool Whether to log headers */
    private bool $logHeaders;

    /** @var bool Whether to log request/response bodies */
    private bool $logBodies;

    /** @var string Log level for entries */
    private string $logLevel;

    /** @var int Slow request threshold in milliseconds */
    private int $slowThreshold;

    /** @var int Body size limit for logging */
    private int $bodySizeLimit;

    /** @var bool Whether to anonymize IP addresses */
    private bool $anonymizeIps;

    /** @var LoggerInterface Logger instance */
    private LoggerInterface $logger;

    /** @var ContainerInterface|null DI Container */
    private ?ContainerInterface $container;

    /** @var string Current environment */
    private string $environment;

    /** @var float Request start time */
    private float $startTime;

    /** @var int Request start memory */
    private int $startMemory;

    /** @var string Request correlation ID */
    private string $correlationId;

    /**
     * Create request/response logging middleware
     *
     * @param string $logMode What to log: 'request', 'response', or 'both'
     * @param bool $logHeaders Whether to log headers
     * @param bool $logBodies Whether to log request/response bodies
     * @param string $logLevel PSR-3 log level
     * @param int $slowThreshold Slow request threshold in milliseconds
     * @param int $bodySizeLimit Body size limit for logging in bytes
     * @param bool $anonymizeIps Whether to anonymize IP addresses
     * @param LoggerInterface|null $logger Logger instance
     * @param ContainerInterface|null $container DI Container instance
     */
    public function __construct(
        string $logMode = 'both',
        bool $logHeaders = true,
        bool $logBodies = false,
        string $logLevel = self::DEFAULT_LOG_LEVEL,
        int $slowThreshold = self::DEFAULT_SLOW_THRESHOLD,
        int $bodySizeLimit = self::DEFAULT_BODY_SIZE_LIMIT,
        bool $anonymizeIps = false,
        ?LoggerInterface $logger = null,
        ?ContainerInterface $container = null
    ) {
        $this->logMode = $logMode;
        $this->logHeaders = $logHeaders;
        $this->logBodies = $logBodies;
        $this->logLevel = $logLevel;
        $this->slowThreshold = $slowThreshold;
        $this->bodySizeLimit = $bodySizeLimit;
        $this->anonymizeIps = $anonymizeIps;
        $this->container = $container ?? $this->getDefaultContainer();

        // Initialize dependencies
        $this->logger = $logger ?? $this->getLogger();
        $this->environment = $this->detectEnvironment();
        $this->correlationId = $this->generateCorrelationId();
    }

    /**
     * Handle request/response logging middleware
     *
     * @param Request $request The incoming request
     * @param callable $next Next handler in the pipeline
     * @param mixed ...$params Additional parameters from route configuration
     *                         [0] = log_mode (string, optional) - 'request', 'response', 'both'
     *                         [1] = log_headers (bool, optional)
     *                         [2] = log_bodies (bool, optional)
     *                         [3] = log_level (string, optional)
     *                         [4] = slow_threshold_ms (int, optional)
     * @return mixed Response with logging applied
     */
    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        // Parse route parameters
        $config = $this->parseRouteParameters($params);

        // Initialize timing and memory tracking
        $this->startTime = microtime(true);
        $this->startMemory = memory_get_usage(true);

        // Add correlation ID to request
        $request->attributes->set('correlation_id', $this->correlationId);

        try {
            // Log request if enabled
            if (in_array($config['log_mode'], ['request', 'both'], true)) {
                $this->logRequest($request, $config);
            }

            // Process request through middleware chain
            $response = $next($request);

            // Calculate timing and memory metrics
            $duration = microtime(true) - $this->startTime;
            $memoryUsage = memory_get_usage(true) - $this->startMemory;

            // Log response if enabled
            if (in_array($config['log_mode'], ['response', 'both'], true) && $response instanceof Response) {
                $this->logResponse($request, $response, $duration, $memoryUsage, $config);
            }

            // Log slow requests
            if ($duration * 1000 > $config['slow_threshold']) {
                $this->logSlowRequest($request, $response, $duration, $config);
            }

            return $response;
        } catch (\Exception $e) {
            // Log request processing failure
            $duration = microtime(true) - $this->startTime;
            $this->logRequestFailure($request, $e, $duration, $config);
            throw $e;
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
            'log_mode' => $this->logMode,
            'log_headers' => $this->logHeaders,
            'log_bodies' => $this->logBodies,
            'log_level' => $this->logLevel,
            'slow_threshold' => $this->slowThreshold,
        ];

        if (isset($params[0]) && is_string($params[0])) {
            $config['log_mode'] = $params[0];
        }

        if (isset($params[1]) && is_bool($params[1])) {
            $config['log_headers'] = $params[1];
        }

        if (isset($params[2]) && is_bool($params[2])) {
            $config['log_bodies'] = $params[2];
        }

        if (isset($params[3]) && is_string($params[3])) {
            $config['log_level'] = $params[3];
        }

        if (isset($params[4]) && is_int($params[4])) {
            $config['slow_threshold'] = $params[4];
        }

        return $config;
    }

    /**
     * Log incoming HTTP request
     *
     * @param Request $request The request
     * @param array<string, mixed> $config Configuration
     */
    private function logRequest(Request $request, array $config): void
    {
        $logData = [
            'type' => 'http_request',
            'correlation_id' => $this->correlationId,
            'method' => $request->getMethod(),
            'uri' => $request->getRequestUri(),
            'path' => $request->getPathInfo(),
            'query_string' => $request->getQueryString(),
            'scheme' => $request->getScheme(),
            'client_ip' => $this->getClientIp($request),
            'user_agent' => $request->headers->get('User-Agent'),
            'referer' => $request->headers->get('Referer'),
            'timestamp' => date('c'),
            'environment' => $this->environment,
        ];

        // Add route information if available
        if ($request->attributes->has('_route')) {
            $logData['route'] = $request->attributes->get('_route');
        }

        if ($request->attributes->has('_route_params')) {
            $routeParams = $request->attributes->get('_route_params', []);
            if (is_array($routeParams)) {
                $this->sanitizeArray($routeParams);
                $logData['route_params'] = $routeParams;
            }
        }

        // Add user information if available
        $userUuid = $request->attributes->get('user_uuid');
        if ($userUuid !== null) {
            $logData['user_uuid'] = $userUuid;
        }

        if ($request->hasSession() && $request->getSession()->has('user')) {
            $user = $request->getSession()->get('user');
            if (is_array($user) && isset($user['uuid'])) {
                $logData['session_user_uuid'] = $user['uuid'];
            }
        }

        // Add headers if enabled
        $logHeaders = (bool)($config['log_headers'] ?? false);
        if ($logHeaders) {
            $logData['headers'] = $this->sanitizeHeaders($request->headers->all());
        }

        // Add request body if enabled
        $logBodies = (bool)($config['log_bodies'] ?? false);
        if ($logBodies) {
            $logData['body'] = $this->sanitizeBody($request->getContent());
            $logData['content_type'] = $request->headers->get('Content-Type');
            $logData['content_length'] = $request->headers->get('Content-Length');
        }

        $this->logger->log($config['log_level'], 'HTTP Request', $logData);
    }

    /**
     * Log HTTP response
     *
     * @param Request $request The request
     * @param Response $response The response
     * @param float $duration Request duration in seconds
     * @param int $memoryUsage Memory used in bytes
     * @param array<string, mixed> $config Configuration
     */
    private function logResponse(
        Request $request,
        Response $response,
        float $duration,
        int $memoryUsage,
        array $config
    ): void {
        $statusCode = $response->getStatusCode();
        $logLevel = $this->getResponseLogLevel($statusCode, $config['log_level']);

        $logData = [
            'type' => 'http_response',
            'correlation_id' => $this->correlationId,
            'method' => $request->getMethod(),
            'uri' => $request->getRequestUri(),
            'status_code' => $statusCode,
            'reason_phrase' => Response::$statusTexts[$statusCode] ?? 'Unknown',
            'duration_ms' => round($duration * 1000, 2),
            'memory_usage_mb' => round($memoryUsage / 1024 / 1024, 2),
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'timestamp' => date('c'),
            'environment' => $this->environment,
        ];

        // Add headers if enabled
        $logHeaders = (bool)($config['log_headers'] ?? false);
        if ($logHeaders) {
            $logData['response_headers'] = $this->sanitizeHeaders($response->headers->all());
        }

        // Add response body if enabled
        $logBodies = (bool)($config['log_bodies'] ?? false);
        if ($logBodies) {
            $content = $response->getContent();
            if ($content !== false) {
                $logData['response_body'] = $this->sanitizeBody($content);
                $logData['response_size'] = strlen($content);
            }
        }

        $this->logger->log($logLevel, 'HTTP Response', $logData);
    }

    /**
     * Log slow request
     *
     * @param Request $request The request
     * @param mixed $response The response
     * @param float $duration Request duration in seconds
     * @param array<string, mixed> $config Configuration
     */
    private function logSlowRequest(Request $request, mixed $response, float $duration, array $config): void
    {
        $logData = [
            'type' => 'slow_request',
            'correlation_id' => $this->correlationId,
            'method' => $request->getMethod(),
            'uri' => $request->getRequestUri(),
            'duration_ms' => round($duration * 1000, 2),
            'threshold_ms' => $config['slow_threshold'],
            'status_code' => $response instanceof Response ? $response->getStatusCode() : null,
            'memory_usage_mb' => round((memory_get_usage(true) - $this->startMemory) / 1024 / 1024, 2),
            'timestamp' => date('c'),
        ];

        $this->logger->warning('Slow Request Detected', $logData);
    }

    /**
     * Log request processing failure
     *
     * @param Request $request The request
     * @param \Exception $exception The exception
     * @param float $duration Request duration in seconds
     * @param array<string, mixed> $config Configuration
     */
    private function logRequestFailure(Request $request, \Exception $exception, float $duration, array $config): void
    {
        $logData = [
            'type' => 'request_failure',
            'correlation_id' => $this->correlationId,
            'method' => $request->getMethod(),
            'uri' => $request->getRequestUri(),
            'duration_ms' => round($duration * 1000, 2),
            'error' => $exception->getMessage(),
            'error_class' => get_class($exception),
            'error_code' => $exception->getCode(),
            'error_file' => $exception->getFile(),
            'error_line' => $exception->getLine(),
            'timestamp' => date('c'),
        ];

        // Add stack trace in development
        if ($this->environment !== 'production') {
            $logData['stack_trace'] = $exception->getTraceAsString();
        }

        // Suppress unused parameter warning
        unset($config);

        $this->logger->error('Request Processing Failed', $logData);
    }

    /**
     * Sanitize headers to remove sensitive information
     *
     * @param array<string, mixed> $headers
     * @return array<string, mixed>
     */
    private function sanitizeHeaders(array $headers): array
    {
        $sanitized = [];
        foreach ($headers as $name => $value) {
            $lowerName = strtolower($name);
            if (in_array($lowerName, self::SENSITIVE_HEADERS, true)) {
                $sanitized[$name] = '[REDACTED]';
            } else {
                $sanitized[$name] = $value;
            }
        }
        return $sanitized;
    }

    /**
     * Sanitize request/response body
     */
    private function sanitizeBody(string $body): string
    {
        // Limit body size for logging
        if (strlen($body) > $this->bodySizeLimit) {
            $body = substr($body, 0, $this->bodySizeLimit) . '... [TRUNCATED]';
        }

        // Try to parse as JSON and sanitize sensitive fields
        $decoded = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $this->sanitizeArray($decoded, self::SENSITIVE_FIELDS);
            return json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return $body;
    }

    /**
     * Recursively sanitize array fields
     *
     * @param array<string, mixed> $array
     * @param array<string> $sensitiveFields
     */
    private function sanitizeArray(array &$array, array $sensitiveFields = self::SENSITIVE_FIELDS): void
    {
        foreach ($array as $key => &$value) {
            if (is_array($value)) {
                $this->sanitizeArray($value, $sensitiveFields);
            } elseif (in_array(strtolower((string)$key), $sensitiveFields, true)) {
                $value = '[REDACTED]';
            }
        }
    }

    /**
     * Get client IP address with anonymization option
     */
    private function getClientIp(Request $request): ?string
    {
        $clientIp = $request->getClientIp();

        if ($clientIp === null) {
            return null;
        }

        if ($this->anonymizeIps) {
            return $this->anonymizeIp($clientIp);
        }

        return $clientIp;
    }

    /**
     * Anonymize IP address for privacy compliance
     */
    private function anonymizeIp(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            // IPv4: Replace last octet with 0
            $parts = explode('.', $ip);
            $parts[3] = '0';
            return implode('.', $parts);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // IPv6: Keep only first 64 bits
            $parts = explode(':', $ip);
            return implode(':', array_slice($parts, 0, 4)) . '::';
        }

        return '[INVALID_IP]';
    }

    /**
     * Get appropriate log level based on HTTP status code
     */
    private function getResponseLogLevel(int $statusCode, string $defaultLevel): string
    {
        return match (true) {
            $statusCode >= 500 => 'error',
            $statusCode >= 400 => 'warning',
            $statusCode >= 300 => 'info',
            default => $defaultLevel
        };
    }

    /**
     * Generate unique correlation ID for request tracking
     */
    private function generateCorrelationId(): string
    {
        return uniqid('req_', true);
    }

    /**
     * Detect current environment
     */
    private function detectEnvironment(): string
    {
        return env('APP_ENV', 'production');
    }

    /**
     * Get logger instance
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
     * Create middleware for request-only logging
     *
     * @param string $logLevel Log level
     * @param bool $logHeaders Whether to log headers
     * @param bool $logBodies Whether to log bodies
     * @return self Middleware instance
     */
    public static function requestOnly(
        string $logLevel = 'info',
        bool $logHeaders = true,
        bool $logBodies = false
    ): self {
        return new self(
            logMode: 'request',
            logHeaders: $logHeaders,
            logBodies: $logBodies,
            logLevel: $logLevel
        );
    }

    /**
     * Create middleware for response-only logging
     *
     * @param string $logLevel Log level
     * @param bool $logHeaders Whether to log headers
     * @param bool $logBodies Whether to log bodies
     * @return self Middleware instance
     */
    public static function responseOnly(
        string $logLevel = 'info',
        bool $logHeaders = true,
        bool $logBodies = false
    ): self {
        return new self(
            logMode: 'response',
            logHeaders: $logHeaders,
            logBodies: $logBodies,
            logLevel: $logLevel
        );
    }

    /**
     * Create middleware for debug logging with full details
     *
     * @param bool $logBodies Whether to log request/response bodies
     * @return self Middleware instance
     */
    public static function debug(bool $logBodies = true): self
    {
        return new self(
            logMode: 'both',
            logHeaders: true,
            logBodies: $logBodies,
            logLevel: 'debug',
            slowThreshold: 1000, // More sensitive for debugging
            anonymizeIps: false
        );
    }

    /**
     * Create middleware for production logging with privacy protection
     *
     * @param int $slowThreshold Slow request threshold in milliseconds
     * @return self Middleware instance
     */
    public static function production(int $slowThreshold = 5000): self
    {
        return new self(
            logMode: 'both',
            logHeaders: false,
            logBodies: false,
            logLevel: 'info',
            slowThreshold: $slowThreshold,
            anonymizeIps: true
        );
    }

    /**
     * Create middleware for performance monitoring
     *
     * @param int $slowThreshold Slow request threshold in milliseconds
     * @return self Middleware instance
     */
    public static function performance(int $slowThreshold = 2000): self
    {
        return new self(
            logMode: 'response',
            logHeaders: false,
            logBodies: false,
            logLevel: 'info',
            slowThreshold: $slowThreshold
        );
    }
}

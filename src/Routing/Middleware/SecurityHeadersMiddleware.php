<?php

declare(strict_types=1);

namespace Glueful\Routing\Middleware;

use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Psr\Log\LoggerInterface;

/**
 * Security Headers Middleware for Next-Gen Router
 *
 * Native Glueful middleware that adds comprehensive security headers to responses
 * to protect against common web vulnerabilities and improve security posture.
 *
 * Features:
 * - Content Security Policy (CSP) with reporting
 * - Cross-Site Scripting (XSS) Protection
 * - Click-jacking Prevention (X-Frame-Options)
 * - MIME Sniffing Prevention
 * - Referrer Policy Configuration
 * - Permissions Policy (Feature Policy)
 * - Strict Transport Security (HSTS)
 * - Certificate Transparency
 * - Cross-Origin headers (CORP, COEP, COOP)
 * - Cache control for security
 * - Per-route configuration support
 * - Environment-aware defaults
 * - Nonce generation for inline scripts
 * - Report-URI integration
 * - Security header validation
 *
 * Security Enhancements:
 * - Automatic HTTPS enforcement in production
 * - CSP violation reporting endpoint
 * - Dynamic nonce generation for inline scripts
 * - Environment-specific security policies
 * - Header conflict resolution
 * - Security score calculation
 *
 * Usage examples:
 *
 * // Basic security headers
 * $router->get('/api/data', [DataController::class, 'index'])
 *     ->middleware(['security_headers']);
 *
 * // Custom CSP policy
 * $router->get('/admin', [AdminController::class, 'dashboard'])
 *     ->middleware(['security_headers:strict']);
 *
 * // Report-only mode for testing
 * $router->get('/experimental', [ExperimentalController::class, 'index'])
 *     ->middleware(['security_headers:report_only']);
 */
class SecurityHeadersMiddleware implements RouteMiddleware
{
    /** @var string Nonce attribute name in request */
    private const NONCE_ATTRIBUTE = 'csp_nonce';

    /** @var array<string> Valid referrer policies */
    private const VALID_REFERRER_POLICIES = [
        'no-referrer',
        'no-referrer-when-downgrade',
        'origin',
        'origin-when-cross-origin',
        'same-origin',
        'strict-origin',
        'strict-origin-when-cross-origin',
        'unsafe-url'
    ];

    /** @var array<string, array<string, mixed>> Security profiles */
    private const SECURITY_PROFILES = [
        'strict' => [
            'csp_level' => 'strict',
            'frame_options' => 'DENY',
            'referrer_policy' => 'strict-origin',
            'permissions_policy_restrictive' => true,
            'force_https' => true
        ],
        'moderate' => [
            'csp_level' => 'moderate',
            'frame_options' => 'SAMEORIGIN',
            'referrer_policy' => 'strict-origin-when-cross-origin',
            'permissions_policy_restrictive' => false,
            'force_https' => true
        ],
        'relaxed' => [
            'csp_level' => 'relaxed',
            'frame_options' => 'SAMEORIGIN',
            'referrer_policy' => 'origin-when-cross-origin',
            'permissions_policy_restrictive' => false,
            'force_https' => false
        ],
        'report_only' => [
            'csp_level' => 'moderate',
            'csp_report_only' => true,
            'frame_options' => 'SAMEORIGIN',
            'referrer_policy' => 'strict-origin-when-cross-origin',
            'permissions_policy_restrictive' => false,
            'force_https' => false
        ]
    ];

    /** @var array<string, mixed> Configuration */
    private array $config;

    /** @var bool Whether headers are enabled */
    private bool $enabled;

    /** @var LoggerInterface|null Logger instance */
    private ?LoggerInterface $logger;

    /** @var string Current environment */
    private string $environment;

    /** @var bool Whether to generate CSP nonces */
    private bool $generateNonces;

    /** @var string|null CSP report URI */
    private ?string $reportUri;

    /** @var array<string> Exempt paths */
    private array $exemptPaths;

    /**
     * Create security headers middleware
     *
     * @param array<string, mixed> $config Security header configuration
     * @param bool $enabled Whether middleware is enabled
     * @param string|null $environment Current environment
     * @param bool $generateNonces Whether to generate CSP nonces
     * @param string|null $reportUri CSP report URI
     * @param array<string> $exemptPaths Paths to exempt from headers
     * @param LoggerInterface|null $logger Logger instance
     */
    public function __construct(
        array $config = [],
        bool $enabled = true,
        ?string $environment = null,
        bool $generateNonces = false,
        ?string $reportUri = null,
        array $exemptPaths = [],
        ?LoggerInterface $logger = null
    ) {
        $this->config = $this->mergeWithDefaults($config);
        $this->enabled = $enabled;
        $this->environment = $environment ?? $this->detectEnvironment();
        $this->generateNonces = $generateNonces;
        $this->reportUri = $reportUri;
        $this->exemptPaths = $exemptPaths;
        $this->logger = $logger;

        // Apply environment-specific adjustments
        $this->applyEnvironmentDefaults();
    }

    /**
     * Handle security headers middleware
     *
     * @param Request $request The incoming request
     * @param callable $next Next handler in the pipeline
     * @param mixed ...$params Additional parameters from route configuration
     *                         [0] = security profile (string, optional)
     *                         [1] = custom config (array, optional)
     * @return mixed Response with security headers
     */
    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        // Skip if disabled
        if (!$this->enabled) {
            return $next($request);
        }

        // Check if path is exempt
        if ($this->isExemptPath($request)) {
            $this->logger?->debug('Security headers skipped for exempt path', [
                'path' => $request->getPathInfo()
            ]);
            return $next($request);
        }

        // Apply security profile if provided
        $profile = isset($params[0]) && is_string($params[0]) ? $params[0] : null;
        $customConfig = isset($params[1]) && is_array($params[1]) ? $params[1] : [];

        $effectiveConfig = $this->getEffectiveConfig($profile, $customConfig);

        // Generate CSP nonce if enabled
        $nonce = null;
        if ($this->generateNonces && $this->shouldGenerateNonce($effectiveConfig)) {
            $nonce = $this->generateNonce();
            $request->attributes->set(self::NONCE_ATTRIBUTE, $nonce);
        }

        // Process the request
        $response = $next($request);

        // Add security headers to response
        if ($response instanceof Response) {
            $this->addSecurityHeaders($response, $request, $effectiveConfig, $nonce);

            // Log security headers application
            $this->logger?->debug('Security headers applied', [
                'path' => $request->getPathInfo(),
                'profile' => $profile,
                'score' => $this->calculateSecurityScore($response)
            ]);
        }

        return $response;
    }

    /**
     * Add security headers to response
     *
     * @param Response $response The response
     * @param Request $request The request
     * @param array<string, mixed> $config Effective configuration
     * @param string|null $nonce CSP nonce if generated
     */
    private function addSecurityHeaders(
        Response $response,
        Request $request,
        array $config,
        ?string $nonce = null
    ): void {
        // Content Security Policy
        if (($config['content_security_policy']['enabled'] ?? false) === true) {
            $this->addContentSecurityPolicy($response, $config, $nonce);
        }

        // X-Content-Type-Options
        if (($config['x_content_type_options'] ?? true) === true) {
            $response->headers->set('X-Content-Type-Options', 'nosniff');
        }

        // X-Frame-Options
        if (isset($config['x_frame_options'])) {
            $this->addFrameOptions($response, $config['x_frame_options']);
        }

        // X-XSS-Protection (legacy but still useful for older browsers)
        if (($config['x_xss_protection'] ?? true) === true) {
            $response->headers->set('X-XSS-Protection', '1; mode=block');
        }

        // Strict-Transport-Security (only for HTTPS)
        if ($request->isSecure() && (($config['strict_transport_security']['enabled'] ?? true) === true)) {
            $this->addStrictTransportSecurity($response, $config['strict_transport_security']);
        }

        // Referrer-Policy
        if (isset($config['referrer_policy'])) {
            $this->addReferrerPolicy($response, $config['referrer_policy']);
        }

        // Permissions-Policy (Feature-Policy)
        if (($config['permissions_policy']['enabled'] ?? false) === true) {
            $this->addPermissionsPolicy($response, $config['permissions_policy']);
        }

        // Cross-Origin headers
        $this->addCrossOriginHeaders($response, $config);

        // Cache-Control for security
        if (($config['security_cache_control'] ?? false) === true) {
            $this->addSecurityCacheControl($response);
        }

        // Certificate Transparency
        if (($config['expect_ct']['enabled'] ?? false) === true) {
            $this->addExpectCT($response, $config['expect_ct']);
        }

        // Remove potentially dangerous headers
        $this->removeUnsafeHeaders($response, $config);
    }

    /**
     * Add Content Security Policy header
     *
     * @param Response $response The response
     * @param array<string, mixed> $config Configuration
     * @param string|null $nonce CSP nonce if generated
     */
    private function addContentSecurityPolicy(Response $response, array $config, ?string $nonce = null): void
    {
        $cspConfig = $config['content_security_policy'];
        $directives = [];

        // Build CSP directives
        foreach ($cspConfig['directives'] as $directive => $sources) {
            $directiveSources = $sources;

            // Add nonce to script-src and style-src if generated
            if ($nonce !== null && in_array($directive, ['script-src', 'style-src'], true)) {
                $directiveSources[] = "'nonce-{$nonce}'";
            }

            $directives[] = $directive . ' ' . implode(' ', $directiveSources);
        }

        // Add report-uri if configured
        if ($this->reportUri !== null) {
            $directives[] = 'report-uri ' . $this->reportUri;
        }

        // Determine header name based on report-only mode
        $headerName = (($cspConfig['report_only'] ?? false) === true)
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';

        $response->headers->set($headerName, implode('; ', $directives));
    }

    /**
     * Add X-Frame-Options header
     *
     * @param Response $response The response
     * @param string $frameOptions Frame options value
     */
    private function addFrameOptions(Response $response, string $frameOptions): void
    {
        // Validate frame options
        $upperOption = strtoupper($frameOptions);

        if (str_starts_with($upperOption, 'ALLOW-FROM')) {
            // Handle ALLOW-FROM with URL
            $response->headers->set('X-Frame-Options', $frameOptions);
        } elseif (in_array($upperOption, ['DENY', 'SAMEORIGIN'], true)) {
            $response->headers->set('X-Frame-Options', $upperOption);
        } else {
            $this->logger?->warning('Invalid X-Frame-Options value', [
                'value' => $frameOptions,
                'fallback' => 'DENY'
            ]);
            $response->headers->set('X-Frame-Options', 'DENY');
        }
    }

    /**
     * Add Strict-Transport-Security header
     *
     * @param Response $response The response
     * @param array<string, mixed> $config HSTS configuration
     */
    private function addStrictTransportSecurity(Response $response, array $config): void
    {
        $maxAge = $config['max_age'] ?? 31536000; // 1 year default
        $value = 'max-age=' . $maxAge;

        if (($config['include_subdomains'] ?? true) === true) {
            $value .= '; includeSubDomains';
        }

        if (($config['preload'] ?? false) === true) {
            $value .= '; preload';
        }

        $response->headers->set('Strict-Transport-Security', $value);
    }

    /**
     * Add Referrer-Policy header
     *
     * @param Response $response The response
     * @param string $policy Referrer policy
     */
    private function addReferrerPolicy(Response $response, string $policy): void
    {
        if (in_array($policy, self::VALID_REFERRER_POLICIES, true)) {
            $response->headers->set('Referrer-Policy', $policy);
        } else {
            $this->logger?->warning('Invalid Referrer-Policy value', [
                'value' => $policy,
                'fallback' => 'strict-origin-when-cross-origin'
            ]);
            $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
    }

    /**
     * Add Permissions-Policy header
     *
     * @param Response $response The response
     * @param array<string, mixed> $config Permissions policy configuration
     */
    private function addPermissionsPolicy(Response $response, array $config): void
    {
        $directives = [];

        foreach ($config['directives'] as $feature => $allowList) {
            // Format sources according to spec
            $sources = array_map(function ($source) {
                if ($source === 'self') {
                    return 'self';
                } elseif ($source === '*') {
                    return '*';
                } elseif ($source === 'none') {
                    return '';
                } else {
                    return '"' . $source . '"';
                }
            }, $allowList);

            // Handle empty allow list (feature disabled)
            if (count($sources) === 1 && $sources[0] === '') {
                $directives[] = $feature . '=()';
            } else {
                $directives[] = $feature . '=(' . implode(' ', $sources) . ')';
            }
        }

        $response->headers->set('Permissions-Policy', implode(', ', $directives));
    }

    /**
     * Add Cross-Origin headers
     *
     * @param Response $response The response
     * @param array<string, mixed> $config Configuration
     */
    private function addCrossOriginHeaders(Response $response, array $config): void
    {
        // Cross-Origin-Resource-Policy
        if (isset($config['cross_origin_resource_policy'])) {
            $response->headers->set('Cross-Origin-Resource-Policy', $config['cross_origin_resource_policy']);
        }

        // Cross-Origin-Embedder-Policy
        if (isset($config['cross_origin_embedder_policy'])) {
            $response->headers->set('Cross-Origin-Embedder-Policy', $config['cross_origin_embedder_policy']);
        }

        // Cross-Origin-Opener-Policy
        if (isset($config['cross_origin_opener_policy'])) {
            $response->headers->set('Cross-Origin-Opener-Policy', $config['cross_origin_opener_policy']);
        }
    }

    /**
     * Add security-focused cache control
     *
     * @param Response $response The response
     */
    private function addSecurityCacheControl(Response $response): void
    {
        // Prevent caching of sensitive data
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');
    }

    /**
     * Add Expect-CT header for Certificate Transparency
     *
     * @param Response $response The response
     * @param array<string, mixed> $config Expect-CT configuration
     */
    private function addExpectCT(Response $response, array $config): void
    {
        $maxAge = $config['max_age'] ?? 86400; // 1 day default
        $value = 'max-age=' . $maxAge;

        if (($config['enforce'] ?? false) === true) {
            $value .= ', enforce';
        }

        if (isset($config['report_uri'])) {
            $value .= ', report-uri="' . $config['report_uri'] . '"';
        }

        $response->headers->set('Expect-CT', $value);
    }

    /**
     * Remove potentially unsafe headers
     *
     * @param Response $response The response
     * @param array<string, mixed> $config Configuration
     */
    private function removeUnsafeHeaders(Response $response, array $config): void
    {
        $headersToRemove = $config['remove_headers'] ?? [
            'X-Powered-By',
            'Server',
            'X-AspNet-Version',
            'X-AspNetMvc-Version'
        ];

        foreach ($headersToRemove as $header) {
            $response->headers->remove($header);
        }
    }

    /**
     * Generate CSP nonce
     *
     * @return string Generated nonce
     */
    private function generateNonce(): string
    {
        return base64_encode(random_bytes(16));
    }

    /**
     * Check if nonce should be generated
     *
     * @param array<string, mixed> $config Configuration
     * @return bool Whether to generate nonce
     */
    private function shouldGenerateNonce(array $config): bool
    {
        if (
            !isset($config['content_security_policy']['enabled']) ||
            $config['content_security_policy']['enabled'] !== true
        ) {
            return false;
        }

        $directives = $config['content_security_policy']['directives'] ?? [];

        // Check if script-src or style-src uses 'unsafe-inline'
        foreach (['script-src', 'style-src'] as $directive) {
            if (
                isset($directives[$directive]) &&
                in_array("'unsafe-inline'", $directives[$directive], true)
            ) {
                return true;
            }
        }

        return $this->generateNonces;
    }

    /**
     * Check if path is exempt from security headers
     *
     * @param Request $request The request
     * @return bool Whether path is exempt
     */
    private function isExemptPath(Request $request): bool
    {
        $path = $request->getPathInfo();

        foreach ($this->exemptPaths as $exemptPath) {
            if ($this->matchesPattern($path, $exemptPath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if path matches pattern
     *
     * @param string $path Request path
     * @param string $pattern Exempt pattern
     * @return bool Whether path matches
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
     * Get effective configuration
     *
     * @param string|null $profile Security profile
     * @param array<string, mixed> $customConfig Custom configuration
     * @return array<string, mixed> Effective configuration
     */
    private function getEffectiveConfig(?string $profile, array $customConfig): array
    {
        $config = $this->config;

        // Apply security profile if specified
        if ($profile !== null && isset(self::SECURITY_PROFILES[$profile])) {
            $profileConfig = self::SECURITY_PROFILES[$profile];
            $config = $this->applyProfile($config, $profileConfig);
        }

        // Merge custom configuration
        if (count($customConfig) > 0) {
            $config = array_replace_recursive($config, $customConfig);
        }

        return $config;
    }

    /**
     * Apply security profile to configuration
     *
     * @param array<string, mixed> $config Base configuration
     * @param array<string, mixed> $profile Profile configuration
     * @return array<string, mixed> Modified configuration
     */
    private function applyProfile(array $config, array $profile): array
    {
        // Apply CSP level
        if (isset($profile['csp_level'])) {
            $config['content_security_policy'] = $this->getCspConfigForLevel($profile['csp_level']);
        }

        // Apply report-only mode
        if (isset($profile['csp_report_only'])) {
            $config['content_security_policy']['report_only'] = $profile['csp_report_only'];
        }

        // Apply frame options
        if (isset($profile['frame_options'])) {
            $config['x_frame_options'] = $profile['frame_options'];
        }

        // Apply referrer policy
        if (isset($profile['referrer_policy'])) {
            $config['referrer_policy'] = $profile['referrer_policy'];
        }

        // Apply permissions policy restrictions
        if (isset($profile['permissions_policy_restrictive']) && $profile['permissions_policy_restrictive'] === true) {
            $config['permissions_policy'] = $this->getRestrictivePermissionsPolicy();
        }

        return $config;
    }

    /**
     * Get CSP configuration for security level
     *
     * @param string $level Security level
     * @return array<string, mixed> CSP configuration
     */
    private function getCspConfigForLevel(string $level): array
    {
        $configs = [
            'strict' => [
                'enabled' => true,
                'report_only' => false,
                'directives' => [
                    'default-src' => ["'self'"],
                    'script-src' => ["'self'"],
                    'style-src' => ["'self'"],
                    'img-src' => ["'self'", 'data:'],
                    'font-src' => ["'self'"],
                    'connect-src' => ["'self'"],
                    'frame-src' => ["'none'"],
                    'object-src' => ["'none'"],
                    'base-uri' => ["'self'"],
                    'form-action' => ["'self'"],
                    'frame-ancestors' => ["'none'"],
                    'upgrade-insecure-requests' => []
                ]
            ],
            'moderate' => [
                'enabled' => true,
                'report_only' => false,
                'directives' => [
                    'default-src' => ["'self'"],
                    'script-src' => ["'self'"],
                    'style-src' => ["'self'"],
                    'img-src' => ["'self'", 'data:', 'https:'],
                    'font-src' => ["'self'", 'data:'],
                    'connect-src' => ["'self'"],
                    'frame-src' => ["'self'"],
                    'object-src' => ["'none'"],
                    'base-uri' => ["'self'"],
                    'form-action' => ["'self'"]
                ]
            ],
            'relaxed' => [
                'enabled' => true,
                'report_only' => false,
                'directives' => [
                    'default-src' => ["*"],
                    'script-src' => ["*", "'unsafe-eval'"],
                    'style-src' => ["*"],
                    'img-src' => ["*", 'data:'],
                    'font-src' => ["*", 'data:'],
                    'connect-src' => ["*"],
                    'frame-src' => ["*"],
                    'object-src' => ["*"]
                ]
            ]
        ];

        foreach ($configs as &$config) {
            $config['directives'] = $this->applyUnsafeInlineOptIn($config['directives']);
        }
        unset($config);

        return $configs[$level] ?? $configs['moderate'];
    }

    /**
     * Get restrictive permissions policy
     *
     * @return array<string, mixed> Restrictive permissions policy
     */
    private function getRestrictivePermissionsPolicy(): array
    {
        return [
            'enabled' => true,
            'directives' => [
                'accelerometer' => ['none'],
                'ambient-light-sensor' => ['none'],
                'autoplay' => ['self'],
                'battery' => ['none'],
                'camera' => ['none'],
                'display-capture' => ['none'],
                'document-domain' => ['none'],
                'encrypted-media' => ['self'],
                'fullscreen' => ['self'],
                'geolocation' => ['none'],
                'gyroscope' => ['none'],
                'magnetometer' => ['none'],
                'microphone' => ['none'],
                'midi' => ['none'],
                'payment' => ['none'],
                'picture-in-picture' => ['self'],
                'publickey-credentials-get' => ['self'],
                'screen-wake-lock' => ['none'],
                'sync-xhr' => ['self'],
                'usb' => ['none'],
                'web-share' => ['none'],
                'xr-spatial-tracking' => ['none']
            ]
        ];
    }

    /**
     * Calculate security score based on headers
     *
     * @param Response $response The response
     * @return int Security score (0-100)
     */
    private function calculateSecurityScore(Response $response): int
    {
        $score = 0;
        $headers = $response->headers;

        // Check for essential security headers
        $securityHeaders = [
            'Content-Security-Policy' => 20,
            'X-Content-Type-Options' => 10,
            'X-Frame-Options' => 10,
            'X-XSS-Protection' => 5,
            'Strict-Transport-Security' => 15,
            'Referrer-Policy' => 10,
            'Permissions-Policy' => 10,
            'Cross-Origin-Resource-Policy' => 5,
            'Cross-Origin-Embedder-Policy' => 5,
            'Cross-Origin-Opener-Policy' => 5,
            'Expect-CT' => 5
        ];

        foreach ($securityHeaders as $header => $points) {
            if ($headers->has($header)) {
                $score += $points;
            }
        }

        return min(100, $score);
    }

    /**
     * Merge configuration with defaults
     *
     * @param array<string, mixed> $config User configuration
     * @return array<string, mixed> Merged configuration
     */
    private function mergeWithDefaults(array $config): array
    {
        $defaults = [
            'content_security_policy' => [
                'enabled' => true,
                'report_only' => false,
                'directives' => [
                    'default-src' => ["'self'"],
                    'script-src' => ["'self'"],
                    'style-src' => ["'self'"],
                    'img-src' => ["'self'", 'data:', 'https:'],
                    'font-src' => ["'self'", 'data:'],
                    'connect-src' => ["'self'"],
                    'frame-src' => ["'self'"],
                    'object-src' => ["'none'"],
                    'base-uri' => ["'self'"],
                    'form-action' => ["'self'"]
                ]
            ],
            'x_content_type_options' => true,
            'x_frame_options' => 'SAMEORIGIN',
            'x_xss_protection' => '1; mode=block',
            'strict_transport_security' => [
                'enabled' => true,
                'max_age' => 31536000,
                'include_subdomains' => true,
                'preload' => false
            ],
            'referrer_policy' => 'strict-origin-when-cross-origin',
            'permissions_policy' => [
                'enabled' => false,
                'directives' => [
                    'geolocation' => ['self'],
                    'microphone' => ['self'],
                    'camera' => ['self'],
                    'payment' => ['self']
                ]
            ],
            'cross_origin_resource_policy' => 'same-origin',
            'cross_origin_embedder_policy' => 'unsafe-none',
            'cross_origin_opener_policy' => 'same-origin',
            'expect_ct' => [
                'enabled' => false,
                'max_age' => 86400,
                'enforce' => false
            ],
            'security_cache_control' => false,
            'remove_headers' => [
                'X-Powered-By',
                'Server'
            ]
        ];

        $defaults['content_security_policy']['directives'] = $this->applyUnsafeInlineOptIn(
            $defaults['content_security_policy']['directives']
        );

        // Handle legacy configuration format from security.php
        $config = $this->normalizeLegacyConfig($config);

        return array_replace_recursive($defaults, $config);
    }

    /**
     * Apply unsafe-inline directives when explicitly enabled via env flags.
     *
     * @param array<string, array<string>> $directives
     * @return array<string, array<string>>
     */
    private function applyUnsafeInlineOptIn(array $directives): array
    {
        $allowScriptUnsafeInline = (bool) env('CSP_SCRIPT_UNSAFE_INLINE', false);
        $allowStyleUnsafeInline = (bool) env('CSP_STYLE_UNSAFE_INLINE', false);

        if (
            $allowScriptUnsafeInline
            && isset($directives['script-src'])
            && !in_array("'unsafe-inline'", $directives['script-src'], true)
        ) {
            $directives['script-src'][] = "'unsafe-inline'";
        }

        if (
            $allowStyleUnsafeInline
            && isset($directives['style-src'])
            && !in_array("'unsafe-inline'", $directives['style-src'], true)
        ) {
            $directives['style-src'][] = "'unsafe-inline'";
        }

        return $directives;
    }

    /**
     * Normalize legacy configuration format
     *
     * @param array<string, mixed> $config Configuration
     * @return array<string, mixed> Normalized configuration
     */
    private function normalizeLegacyConfig(array $config): array
    {
        // Handle legacy CSP string format
        if (isset($config['content_security_policy']) && is_string($config['content_security_policy'])) {
            $cspString = $config['content_security_policy'];
            $config['content_security_policy'] = [
                'enabled' => $cspString !== '',
                'directives' => $cspString !== '' ? ['default-src' => [$cspString]] : []
            ];
        }

        // Handle legacy HSTS string format
        if (isset($config['strict_transport_security']) && is_string($config['strict_transport_security'])) {
            $hstsString = $config['strict_transport_security'];
            $config['strict_transport_security'] = [
                'enabled' => $hstsString !== '',
                'max_age' => 31536000
            ];
        }

        return $config;
    }

    /**
     * Apply environment-specific defaults
     */
    private function applyEnvironmentDefaults(): void
    {
        switch ($this->environment) {
            case 'production':
                // Strict security in production
                $this->config['strict_transport_security']['enabled'] = true;
                $this->config['strict_transport_security']['preload'] = true;
                $this->config['content_security_policy']['report_only'] = false;
                break;

            case 'staging':
                // Moderate security in staging
                $this->config['strict_transport_security']['enabled'] = true;
                $this->config['content_security_policy']['report_only'] = false;
                break;

            case 'development':
            case 'local':
                // Relaxed security in development
                $this->config['strict_transport_security']['enabled'] = false;
                $this->config['content_security_policy']['report_only'] = true;
                // Allow unsafe-eval in development for hot reloading
                if (isset($this->config['content_security_policy']['directives']['script-src'])) {
                    $this->config['content_security_policy']['directives']['script-src'][] = "'unsafe-eval'";
                }
                break;
        }
    }

    /**
     * Detect current environment
     *
     * @return string Detected environment
     */
    private function detectEnvironment(): string
    {
        return env('APP_ENV', 'production');
    }

    /**
     * Create middleware with production defaults
     *
     * @param array<string> $exemptPaths Paths to exempt
     * @return self Configured middleware
     */
    public static function production(array $exemptPaths = []): self
    {
        return new self(
            config: [],
            enabled: true,
            environment: 'production',
            generateNonces: true,
            reportUri: env('CSP_REPORT_URI'),
            exemptPaths: $exemptPaths
        );
    }

    /**
     * Create middleware for development
     *
     * @return self Configured middleware
     */
    public static function development(): self
    {
        return new self(
            config: [
                'content_security_policy' => [
                    'report_only' => true
                ],
                'strict_transport_security' => [
                    'enabled' => false
                ]
            ],
            enabled: true,
            environment: 'development'
        );
    }

    /**
     * Get CSP nonce from request
     *
     * @param Request $request The request
     * @return string|null CSP nonce if available
     */
    public static function getNonce(Request $request): ?string
    {
        return $request->attributes->get(self::NONCE_ATTRIBUTE);
    }
}

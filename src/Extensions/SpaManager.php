<?php

declare(strict_types=1);

namespace Glueful\Extensions;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Glueful\Helpers\StaticFileDetector;
use Glueful\Extensions\ServiceProvider;
use Glueful\Auth\AuthenticationService;
use Psr\Container\ContainerInterface;
use Glueful\Routing\Middleware\CSRFMiddleware;
use Symfony\Component\HttpFoundation\Request;

/**
 * SPA Manager
 *
 * Manages Single Page Application routing and serving for extensions.
 * Integrates with the Glueful Extensions system to provide SPA support.
 */
class SpaManager
{
    /** @var array<int, array{path_prefix: string, build_path: string, options: array<string, mixed>, registered_at: int}> */
    protected array $spaApps = [];
    protected LoggerInterface $logger;
    protected StaticFileDetector $staticFileDetector;
    protected ?ContainerInterface $container = null;
    protected ?AuthenticationService $authService = null;
    protected ?CSRFMiddleware $csrfMiddleware = null;

    /** @var int Maximum file size for direct reading (1MB) */
    private const MAX_DIRECT_READ_SIZE = 1048576;

    public function __construct(
        ?LoggerInterface $logger = null,
        ?StaticFileDetector $staticFileDetector = null,
        ?ContainerInterface $container = null
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->staticFileDetector = $staticFileDetector ?? new StaticFileDetector();
        $this->container = $container;

        // Lazy load services when needed
        if ($this->container !== null) {
            try {
                $this->authService = $this->container->get(AuthenticationService::class);
                $this->csrfMiddleware = $this->container->get(CSRFMiddleware::class);
            } catch (\Exception $e) {
                $this->logger->debug('Optional services not available: ' . $e->getMessage());
            }
        }
    }

    /**
     * Resolve Request from the DI container.
     */
    private function resolveRequest(): Request
    {
        if ($this->container !== null && $this->container->has(Request::class)) {
            return $this->container->get(Request::class);
        }

        throw new \RuntimeException('SpaManager requires Request from DI container.');
    }

    /**
     * Register SPA configurations from an extension
     *
     * @param string $extensionClass Extension class name
     * @return void
     */
    public function registerFromExtension(string $extensionClass): void
    {
        if (!class_exists($extensionClass)) {
            $this->logger->warning("Class {$extensionClass} does not exist");
            return;
        }

        if (!is_subclass_of($extensionClass, ServiceProvider::class)) {
            $this->logger->warning("Class {$extensionClass} is not a valid extension");
            return;
        }

        // Note: SPA registration is now handled automatically by ServiceProvider::mountStatic()
        // during provider boot phase. This method is kept for backwards compatibility.
        $this->logger->debug("Extension {$extensionClass} uses ServiceProvider::mountStatic() for SPA support");
    }

    /**
     * Register a single SPA application
     *
     * @param string $pathPrefix URL path prefix
     * @param string $buildPath Path to built SPA index.html
     * @param array $options Additional options
     * @return void
     */
    /**
     * @param array<string, mixed> $options
     */
    public function registerSpaApp(string $pathPrefix, string $buildPath, array $options = []): void
    {
        if (!file_exists($buildPath)) {
            $this->logger->warning("SPA build not found at {$buildPath}");
            return;
        }

        $this->spaApps[] = [
            'path_prefix' => rtrim($pathPrefix, '/'),
            'build_path' => $buildPath,
            'options' => $options,
            'registered_at' => time()
        ];

        // Sort by path length (longest first) for proper matching
        usort($this->spaApps, fn($a, $b) => strlen($b['path_prefix']) - strlen($a['path_prefix']));

        $this->logger->debug("Registered SPA app", [
            'path_prefix' => $pathPrefix,
            'build_path' => $buildPath,
            'name' => $options['name'] ?? 'Unknown'
        ]);
    }

    /**
     * Handle SPA routing for a request path
     *
     * @param string $requestPath Request path to match
     * @return bool Whether a SPA was served
     */
    public function handleSpaRouting(string $requestPath): bool
    {
        // First check if this is an asset request for any SPA
        if ($this->handleAssetRequest($requestPath)) {
            return true;
        }

        // Then check for SPA route matches
        foreach ($this->spaApps as $app) {
            if ($this->matchesPath($requestPath, $app['path_prefix'])) {
                if ($this->checkAccess($app['options'])) {
                    $this->serveSpaApp($app);
                    return true;
                } else {
                    $this->logger->warning("Access denied for SPA", [
                        'path' => $requestPath,
                        'spa' => $app['options']['name'] ?? 'Unknown'
                    ]);
                    return false;
                }
            }
        }
        return false;
    }

    /**
     * Handle asset requests for SPA applications
     *
     * @param string $requestPath Request path
     * @return bool Whether an asset was served
     */
    protected function handleAssetRequest(string $requestPath): bool
    {
        // Check if this looks like an asset request
        if (!preg_match('/\.(js|css|png|jpg|jpeg|gif|svg|ico|woff|woff2|ttf|map|json)$/', $requestPath)) {
            return false;
        }

        // For each registered SPA, check if the asset exists in its directory
        foreach ($this->spaApps as $app) {
            $publicPath = dirname($app['build_path']);
            $assetsPath = $app['options']['assets_path'] ?? dirname($app['build_path']) . '/assets';

            // Get the SPA's path prefix (e.g., "/ui/api/admin")
            $spaPrefix = $app['path_prefix'];

            // Handle requests that include the SPA prefix in the path
            if (str_starts_with($requestPath, $spaPrefix)) {
                $relativeAssetPath = substr($requestPath, strlen($spaPrefix));

                // Try public root files first (env.json, favicon, etc.)
                $publicFile = $publicPath . $relativeAssetPath;
                if (file_exists($publicFile) && $this->validateFilePath($publicFile, $publicPath)) {
                    $this->serveAsset($publicFile, $requestPath);
                    return true;
                }

                // Try assets directory
                if (str_starts_with($relativeAssetPath, '/assets/')) {
                    $assetFileName = substr($relativeAssetPath, 8); // Remove '/assets/'
                    $assetFile = $assetsPath . '/' . $assetFileName;
                    if (file_exists($assetFile) && $this->validateFilePath($assetFile, dirname($app['build_path']))) {
                        $this->serveAsset($assetFile, $requestPath);
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Check if request path matches SPA prefix
     *
     * @param string $requestPath Request path
     * @param string $prefix SPA path prefix
     * @return bool Whether path matches
     */
    protected function matchesPath(string $requestPath, string $prefix): bool
    {
        if ($prefix === '/') {
            return true; // Fallback matches everything
        }
        return str_starts_with($requestPath, $prefix);
    }

    /**
     * Check access permissions for SPA
     *
     * @param array $options SPA options
     * @return bool Whether access is allowed
     */
    /**
     * @param array<string, mixed> $options
     */
    protected function checkAccess(array $options): bool
    {
        // Basic authentication check
        if (isset($options['auth_required']) && (bool) $options['auth_required']) {
            if ($this->authService === null) {
                $this->logger->warning('Authentication required but AuthenticationService not available');
                return false;
            }

            // Check if user is authenticated via session or token
            $request = $this->resolveRequest();
            try {
                // Extract credentials from request
                $credentials = $this->extractCredentialsFromRequest($request);
                if ($credentials === null) {
                    $this->logger->info('Access denied: No authentication credentials found');
                    return false;
                }

                $user = $this->authService->authenticate($credentials);
                if ($user === null) {
                    $this->logger->info('Access denied: User not authenticated');
                    return false;
                }
            } catch (\Exception $e) {
                $this->logger->error('Authentication check failed: ' . $e->getMessage());
                return false;
            }
        }

        // Permission check
        if (isset($options['permissions']) && is_array($options['permissions']) && count($options['permissions']) > 0) {
            if ($this->authService === null) {
                $this->logger->warning('Permissions check required but AuthenticationService not available');
                return false;
            }

            // Check user permissions
            $request = $this->resolveRequest();
            try {
                // Extract credentials from request
                $credentials = $this->extractCredentialsFromRequest($request);
                if ($credentials === null) {
                    return false;
                }

                $user = $this->authService->authenticate($credentials);
                if ($user === null) {
                    return false;
                }

                // Check if user has required permissions
                $userPermissions = isset($user['permissions']) ? $user['permissions'] : [];
                foreach ($options['permissions'] as $requiredPermission) {
                    if (!in_array($requiredPermission, $userPermissions, true)) {
                        $this->logger->info('Access denied: Missing permission ' . $requiredPermission);
                        return false;
                    }
                }
            } catch (\Exception $e) {
                $this->logger->error('Permission check failed: ' . $e->getMessage());
                return false;
            }
        }

        // CSRF check for state-changing operations
        if (isset($options['csrf_required']) && (bool) $options['csrf_required']) {
            if (!$this->validateCsrfToken()) {
                $this->logger->warning('CSRF token validation failed');
                return false;
            }
        }

        return true; // Allow access if all checks pass
    }

    /**
     * Extract authentication credentials from request
     *
     * @param Request $request The HTTP request
     * @return array<string, mixed>|null Credentials array or null if not found
     */
    protected function extractCredentialsFromRequest(Request $request): ?array
    {
        // Check for Bearer token in Authorization header
        $authHeader = $request->headers->get('Authorization');
        if ($authHeader !== null && str_starts_with($authHeader, 'Bearer ')) {
            return ['token' => substr($authHeader, 7)];
        }

        // Check for API key
        $apiKey = $request->headers->get('X-API-Key');
        if ($apiKey !== null) {
            return ['api_key' => $apiKey];
        }

        // Check for session-based authentication
        try {
            if ($request->hasSession()) {
                $session = $request->getSession();
                if ($session->has('user_id')) {
                    return ['session_id' => $session->getId()];
                }
            }
        } catch (\Exception $e) {
            // Session not available
            $this->logger->debug('Session not available: ' . $e->getMessage());
        }

        // Check for credentials in request body (for login requests)
        $username = $request->request->get('username') ?? $request->request->get('email');
        $password = $request->request->get('password');
        if ($username !== null && $password !== null) {
            return [
                'username' => $username,
                'password' => $password
            ];
        }

        return null;
    }

    /**
     * Validate CSRF token from request
     *
     * @return bool Whether CSRF token is valid
     */
    protected function validateCsrfToken(): bool
    {
        if ($this->csrfMiddleware === null) {
            // CSRF middleware not available, skip check
            return true;
        }

        try {
            $request = $this->resolveRequest();
            // The CSRFMiddleware will handle the validation internally
            // For now, we'll do a basic check
            $token = $request->headers->get('X-CSRF-Token')
                ?? $request->request->get('_csrf_token')
                ?? $request->query->get('_csrf_token');

            if (!$token) {
                return false;
            }

            // Token validation would be handled by CSRFMiddleware
            // This is a placeholder for the actual validation
            return true;
        } catch (\Exception $e) {
            $this->logger->error('CSRF validation error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Validate file path for security
     *
     * @param string $filePath Path to validate
     * @param string $basePath Base directory to restrict access within
     * @return bool Whether the path is safe
     */
    protected function validateFilePath(string $filePath, string $basePath): bool
    {
        // Get real paths to prevent directory traversal
        $realFilePath = realpath($filePath);
        $realBasePath = realpath($basePath);

        if ($realFilePath === false || $realBasePath === false) {
            $this->logger->warning('Invalid file path', [
                'file_path' => $filePath,
                'base_path' => $basePath
            ]);
            return false;
        }

        // Ensure the file is within the allowed base path
        if (!str_starts_with($realFilePath, $realBasePath)) {
            $this->logger->warning('File path outside allowed directory', [
                'file_path' => $realFilePath,
                'base_path' => $realBasePath
            ]);
            return false;
        }

        // Check for dangerous patterns
        $dangerousPatterns = [
            '../',
            '..',
            '.env',
            '.git',
            '.ssh',
            'composer.json',
            'composer.lock',
            'package.json',
            'package-lock.json'
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (str_contains($filePath, $pattern)) {
                $this->logger->warning('Dangerous pattern in file path', [
                    'file_path' => $filePath,
                    'pattern' => $pattern
                ]);
                return false;
            }
        }

        return true;
    }

    /**
     * Stream a large file efficiently
     *
     * @param string $filePath Path to the file
     * @param int $chunkSize Size of chunks to read (default 8KB)
     */
    protected function streamFile(string $filePath, int $chunkSize = 8192): void
    {
        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            $this->logger->error('Failed to open file for streaming', ['file' => $filePath]);
            return;
        }

        try {
            while (!feof($handle)) {
                echo fread($handle, $chunkSize);
                ob_flush();
                flush();
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Serve SPA application
     *
     * @param array $app SPA application configuration
     * @return void
     */
    /**
     * @param array{path_prefix: string, build_path: string, options: array<string, mixed>, registered_at: int} $app
     */
    protected function serveSpaApp(array $app): void
    {
        // Set appropriate headers
        header('Content-Type: text/html; charset=utf-8');

        // Add comprehensive security headers
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header(
            'Content-Security-Policy: default-src \'self\'; ' .
            'script-src \'self\' \'unsafe-inline\'; ' .
            'style-src \'self\' \'unsafe-inline\';'
        );

        // Serve the SPA
        readfile($app['build_path']);

        $this->logger->debug("Served SPA application", [
            'name' => $app['options']['name'] ?? 'Unknown',
            'path_prefix' => $app['path_prefix'],
            'build_path' => $app['build_path']
        ]);
    }

    /**
     * Serve a static asset file
     *
     * @param string $filePath Full path to the asset file
     * @param string $requestPath Original request path
     */
    protected function serveAsset(string $filePath, string $requestPath): void
    {
        // Determine content type
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $contentTypes = [
            'js' => 'application/javascript',
            'css' => 'text/css',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'map' => 'application/json'
        ];

        $contentType = $contentTypes[$extension] ?? 'application/octet-stream';

        // Validate file path before serving
        if (!file_exists($filePath)) {
            $this->logger->error('Asset file not found', ['file' => $filePath]);
            http_response_code(404);
            return;
        }

        $fileSize = filesize($filePath);

        // Set headers
        header("Content-Type: {$contentType}");

        // Use more reasonable cache times based on file type
        $cacheTime = match ($extension) {
            'html', 'json' => 3600,        // 1 hour for dynamic content
            'css', 'js' => 86400 * 7,      // 1 week for styles/scripts
            'woff', 'woff2', 'ttf' => 86400 * 30, // 1 month for fonts
            default => 86400                // 1 day for other assets
        };

        header("Cache-Control: public, max-age={$cacheTime}");
        header('Content-Length: ' . $fileSize);

        // Add security headers for certain file types
        if (in_array($extension, ['js', 'css'], true)) {
            header('X-Content-Type-Options: nosniff');
        }

        // Stream large files, read small ones directly
        if ($fileSize > self::MAX_DIRECT_READ_SIZE) {
            $this->streamFile($filePath);
        } else {
            readfile($filePath);
        }

        $this->logger->debug("Served SPA asset", [
            'file_path' => $filePath,
            'request_path' => $requestPath,
            'content_type' => $contentType
        ]);
    }

    /**
     * Get all registered SPA applications
     *
     * @return array Registered SPA apps
     */
    /**
     * @return array<int, array{
     *     path_prefix: string,
     *     build_path: string,
     *     options: array<string, mixed>,
     *     registered_at: int
     * }>
     */
    public function getRegisteredApps(): array
    {
        return $this->spaApps;
    }

    /**
     * Get SPA statistics
     *
     * @return array SPA statistics
     */
    /**
     * @return array{total_apps: int, frameworks: array<string, int>, auth_required: int, paths: array<int, string>}
     */
    public function getStatistics(): array
    {
        $stats = [
            'total_apps' => count($this->spaApps),
            'frameworks' => [],
            'auth_required' => 0,
            'paths' => []
        ];

        foreach ($this->spaApps as $app) {
            // Count frameworks
            $framework = $app['options']['framework'] ?? 'unknown';
            $stats['frameworks'][$framework] = ($stats['frameworks'][$framework] ?? 0) + 1;

            // Count auth required
            if (isset($app['options']['auth_required']) && (bool) $app['options']['auth_required']) {
                $stats['auth_required']++;
            }

            // Collect paths
            $stats['paths'][] = $app['path_prefix'];
        }

        return $stats;
    }

    /**
     * Clear all registered SPAs
     *
     * @return void
     */
    public function clear(): void
    {
        $this->spaApps = [];
        $this->logger->debug("Cleared all registered SPA applications");
    }
}

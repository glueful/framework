<?php

declare(strict_types=1);

namespace Glueful\Extensions;

use Psr\Container\ContainerInterface;
use Glueful\Database\Migrations\MigrationManager;
use Glueful\Routing\Router;
use Glueful\Bootstrap\ApplicationContext;
use Symfony\Component\Console\Attribute\AsCommand;
use ReflectionClass;

/**
 * API-first base provider (PSR-11 compliant).
 */
abstract class ServiceProvider
{
    protected ContainerInterface $app;

    public function __construct(ContainerInterface $app)
    {
        $this->app = $app;
    }

    /**
     * Register services in DI container (called during compilation).
     * Returns service definitions that get compiled into the container.
     * @return array<string, mixed>
     */
    public static function services(): array
    {
        return [];
    }

    /** Register runtime configuration and setup. */
    public function register(ApplicationContext $context): void
    {
        /*
         * Extensions can safely do config merging, route registration, etc. here.
         * Service mutation blocking is enforced at the container level via
         * bind/singleton/alias APIs when container is frozen.
         */
    }

    /** Boot after all providers are registered. */
    public function boot(ApplicationContext $context): void
    {
 /* optional */
    }

    /** Load routes from a file; file will use $router from the container. */
    protected function loadRoutesFrom(string $path): void
    {
        // Router must exist to register routes
        if (!$this->app->has(Router::class)) {
            return;
        }

        // Resolve realpath and ensure file exists
        $real = realpath($path);
        if ($real === false || !is_file($real)) {
            return;
        }

        // Idempotency: avoid loading the same routes file more than once
        static $loaded = [];
        if (isset($loaded[$real])) {
            return;
        }

        try {
            // Expose $router to the included file scope for route definitions
            /** @var Router $router */
            $router = $this->app->get(Router::class);
            require $real; // executes the routes file
            $loaded[$real] = true;
        } catch (\Throwable $e) {
            // Log and continue in production; rethrow in non‑production for fast feedback
            error_log('[Extensions] Failed to load routes from ' . $real . ': ' . $e->getMessage());
            $env = (string) ($_ENV['APP_ENV'] ?? (getenv('APP_ENV') !== false ? getenv('APP_ENV') : 'production'));
            if ($env !== 'production') {
                throw $e;
            }
        }
    }

    /** Register migrations directory. */
    protected function loadMigrationsFrom(string $dir): void
    {
        if (!is_dir($dir) || !$this->app->has(MigrationManager::class)) {
            return;
        }
        /** @var MigrationManager $mm */
        $mm = $this->app->get(MigrationManager::class);
        $mm->addMigrationPath($dir);
    }

    /**
     * Merge default config (app overrides always win).
     * @param array<string, mixed> $defaults
     */
    protected function mergeConfig(string $key, array $defaults): void
    {
        if (!$this->app->has('config.manager')) {
            return;
        }
        $this->app->get('config.manager')->merge($key, $defaults);
    }

    /**
     * Optional: register translation catalogs for API messages only.
     * Expects files like messages.en.php returning ['key' => 'Message'].
     */
    protected function loadMessageCatalogs(string $dir, string $domain = 'messages'): void
    {
        if (!is_dir($dir) || !$this->app->has('translation.manager')) {
            return;
        }
        $translator = $this->app->get('translation.manager');

        $files = glob($dir . '/messages.*.php');
        foreach ($files !== false ? $files : [] as $file) {
            $base = pathinfo($file, PATHINFO_FILENAME); // messages.en
            $parts = explode('.', $base);
            $locale = $parts[1] ?? 'en';
            $messages = require $file;
            if (is_array($messages)) {
                $translator->addMessages($locale, $domain, $messages);
            }
        }
    }

    /**
     * Serves prebuilt static assets via Symfony HttpFoundation (no templating).
     * Mounted at /extensions/{mount} with strict path and caching guards.
     * For public production assets prefer a CDN; mountStatic() is ideal for
     * dev/private UIs and immutable bundles.
     */
    protected function mountStatic(string $mount, string $dir): void
    {
        // Validate mount name to prevent route collisions and ensure URL safety
        if (!preg_match('/^[a-z0-9\-]+$/', $mount)) {
            throw new \InvalidArgumentException(
                "Invalid mount name '{$mount}'. Only lowercase letters, numbers, and hyphens allowed."
            );
        }

        if (!$this->app->has(\Glueful\Routing\Router::class) || !is_dir($dir)) {
            return;
        }
        $realDir = realpath($dir);
        if ($realDir === false) {
            return;
        }

        /** @var \Glueful\Routing\Router $router */
        $router = $this->app->get(\Glueful\Routing\Router::class);

        // Shared file serving logic for both routes
        $serveFile = function (
            \Symfony\Component\HttpFoundation\Request $request,
            string $path
        ) use ($realDir) {
            if (headers_sent()) {
                return new \Symfony\Component\HttpFoundation\Response('', 404);
            }

            // deny dotfiles and PHP by policy (guard against empty basename)
            $basename = basename($path);
            if ($basename === '' || $basename[0] === '.' || str_ends_with(strtolower($basename), '.php')) {
                return new \Symfony\Component\HttpFoundation\Response('', 404);
            }

            $requested = realpath($realDir . DIRECTORY_SEPARATOR . $path);
            if (
                $requested === false
                || !str_starts_with($requested, $realDir . DIRECTORY_SEPARATOR)
                || !is_file($requested)
            ) {
                return new \Symfony\Component\HttpFoundation\Response('', 404);
            }

            $mtime = filemtime($requested) !== false ? filemtime($requested) : time();
            $etag  = md5_file($requested) !== false ? md5_file($requested) : sha1($requested);

            // mime (prefer Symfony MimeTypes; fallback to mime_content_type)
            $guesser = \Symfony\Component\Mime\MimeTypes::getDefault();
            $mimeGuess = mime_content_type($requested);
            $mime = $guesser->guessMimeType($requested) ??
                ($mimeGuess !== false ? $mimeGuess : 'application/octet-stream');

            $resp = new \Symfony\Component\HttpFoundation\BinaryFileResponse($requested);
            $resp->headers->set('Content-Type', $mime);
            // Basic hardening headers (safe defaults; override in app as needed)
            foreach (\Glueful\Security\SecurityHeaders::defaultStaticAssetHeaders() as $header => $value) {
                $resp->headers->set($header, $value);
            }
            $resp->setPublic();
            $resp->headers->set(
                'Cache-Control',
                'public, max-age=31536000, immutable'
            );
            $resp->setEtag('"' . $etag . '"'); // strong ETag; consider weak (W/"...") if upstream transforms
            $resp->setLastModified((new \DateTimeImmutable())->setTimestamp($mtime));
            $resp->setContentDisposition(
                \Symfony\Component\HttpFoundation\ResponseHeaderBag::DISPOSITION_INLINE,
                $basename
            );

            if ($resp->isNotModified($request)) {
                return $resp;
            }
            return $resp;
        };

        // Serve index.html for SPA root route
        $indexCallback = function () use ($realDir) {
            $index = $realDir . DIRECTORY_SEPARATOR . 'index.html';
            if (!is_file($index)) {
                return new \Symfony\Component\HttpFoundation\Response('', 404);
            }
            $resp = new \Symfony\Component\HttpFoundation\BinaryFileResponse($index);
            // Apply the same hardening headers to index as to asset responses
            $resp->headers->set('X-Content-Type-Options', 'nosniff');
            $resp->headers->set('Cross-Origin-Resource-Policy', 'same-origin');
            $resp->headers->set(
                'Content-Security-Policy',
                "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:;"
            );
            $resp->headers->set('Referrer-Policy', 'no-referrer');
            $resp->headers->set('X-Frame-Options', 'SAMEORIGIN');
            $resp->headers->set('X-XSS-Protection', '0');
            return $resp;
        };

        // Asset serving routes (GET only - HEAD handled by framework)
        $router->get("/extensions/{$mount}/{path}", $serveFile)->where('path', '.+');

        // Index serving routes (GET only - HEAD handled by framework)
        $router->get("/extensions/{$mount}", $indexCallback);
    }


    /**
     * Register console commands.
     * @param array<string, class-string> $commands
     */
    protected function commands(array $commands): void
    {
        if (!$this->runningInConsole()) {
            return;
        }

        if ($this->app->has('console.application')) {
            $console = $this->app->get('console.application');
            foreach ($commands as $class) {
                $console->add($this->app->get($class));
            }
            return;
        }

        // Console app not yet created — defer command classes for later pickup
        $this->deferCommandClasses($commands);
    }

    /**
     * Auto-discover and register console commands from a directory.
     *
     * Scans the given directory recursively for command classes that:
     * - Have the #[AsCommand] Symfony attribute
     * - Are not abstract classes
     *
     * This provides zero-config command registration for extensions,
     * matching the framework's command discovery behavior.
     *
     * Usage in extension provider:
     * ```php
     * public function boot(ApplicationContext $context): void
     * {
     *     $this->discoverCommands(
     *         'Glueful\\Extensions\\Meilisearch\\Console',
     *         __DIR__ . '/Console'
     *     );
     * }
     * ```
     *
     * @param string $namespace Base namespace for command classes (e.g., 'Vendor\\Extension\\Console')
     * @param string $directory Directory path to scan for command classes
     */
    protected function discoverCommands(string $namespace, string $directory): void
    {
        if (!$this->runningInConsole()) {
            return;
        }

        $realDir = realpath($directory);
        if ($realDir === false || !is_dir($realDir)) {
            return;
        }

        $commands = $this->scanForCommands($namespace, $realDir);

        if ($commands === []) {
            return;
        }

        if ($this->app->has('console.application')) {
            $console = $this->app->get('console.application');

            foreach ($commands as $class) {
                try {
                    if ($this->app->has($class)) {
                        $command = $this->app->get($class);
                    } else {
                        $command = new $class();
                    }
                    $console->add($command);
                } catch (\Throwable $e) {
                    error_log("[Extensions] Failed to register command {$class}: " . $e->getMessage());
                }
            }
            return;
        }

        // Console app not yet created — defer command classes for later pickup
        $this->deferCommandClasses($commands);
    }

    /**
     * Scan a directory recursively for valid command classes.
     *
     * @param string $namespace Base namespace for the directory
     * @param string $directory Resolved directory path
     * @return array<string> List of fully qualified command class names
     */
    private function scanForCommands(string $namespace, string $directory): array
    {
        $commands = [];

        // Normalize namespace (ensure no trailing backslash)
        $namespace = rtrim($namespace, '\\');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $className = $this->fileToClassName($file->getPathname(), $directory, $namespace);

            if ($className === null || !class_exists($className)) {
                continue;
            }

            if (!$this->isValidCommand($className)) {
                continue;
            }

            $commands[] = $className;
        }

        // Sort for consistent ordering
        sort($commands);

        return $commands;
    }

    /**
     * Convert a file path to a fully qualified class name.
     *
     * @param string $filePath Full path to the PHP file
     * @param string $baseDir Base directory being scanned
     * @param string $namespace Base namespace for the directory
     * @return string|null Fully qualified class name or null if invalid
     */
    private function fileToClassName(string $filePath, string $baseDir, string $namespace): ?string
    {
        $relativePath = str_replace($baseDir . DIRECTORY_SEPARATOR, '', $filePath);
        $relativePath = str_replace(DIRECTORY_SEPARATOR, '\\', $relativePath);
        $relativePath = preg_replace('/\.php$/', '', $relativePath);

        if ($relativePath === null || $relativePath === '') {
            return null;
        }

        return $namespace . '\\' . $relativePath;
    }

    /**
     * Check if a class is a valid console command.
     *
     * A valid command must:
     * - Not be abstract
     * - Have the #[AsCommand] Symfony attribute
     *
     * @param string $className Fully qualified class name to check
     * @return bool True if valid command, false otherwise
     */
    private function isValidCommand(string $className): bool
    {
        try {
            $reflection = new ReflectionClass($className);

            // Skip abstract classes
            if ($reflection->isAbstract()) {
                return false;
            }

            // Must have #[AsCommand] attribute
            if ($reflection->getAttributes(AsCommand::class) === []) {
                return false;
            }

            return true;
        } catch (\ReflectionException) {
            return false;
        }
    }

    /**
     * Store command classes for deferred registration.
     *
     * When extensions boot before the ConsoleApplication is created,
     * command classes are stored here and picked up later by
     * ConsoleApplication::registerDeferredExtensionCommands().
     *
     * @param array<string> $classes
     */
    private function deferCommandClasses(array $classes): void
    {
        self::$deferredCommands = array_merge(self::$deferredCommands, $classes);
    }

    /** @var array<string> Command class names waiting for ConsoleApplication */
    private static array $deferredCommands = [];

    /**
     * Get and clear all deferred extension command classes.
     *
     * Called by ConsoleApplication to register commands that were
     * discovered during extension boot before the console app existed.
     *
     * @return array<string>
     */
    public static function flushDeferredCommands(): array
    {
        $commands = self::$deferredCommands;
        self::$deferredCommands = [];
        return $commands;
    }

    protected function runningInConsole(): bool
    {
        return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
    }
}

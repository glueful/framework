<?php

declare(strict_types=1);

namespace Glueful\Extensions;

use Psr\Container\ContainerInterface;
use Glueful\Database\Migrations\MigrationManager;
use Glueful\Notifications\Contracts\NotificationChannel;
use Glueful\Notifications\Contracts\NotificationExtension;
use Glueful\Notifications\Services\ChannelManager;
use Glueful\Notifications\Services\NotificationDispatcher;
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

    /**
     * Declare permissions contributed by this provider.
     * Collected by ExtensionManager::aggregatePermissionCatalog() into the PermissionRegistry.
     *
     * @return list<\Glueful\Permissions\Catalog\Permission>
     */
    public function permissions(): array
    {
        return [];
    }

    /**
     * Declare roles contributed by this provider.
     *
     * @return list<\Glueful\Permissions\Catalog\Role>
     */
    public function roles(): array
    {
        return [];
    }

    /**
     * Declare Gate voters contributed by this provider. Registered onto the shared Gate.
     *
     * @return list<\Glueful\Permissions\VoterInterface>
     */
    public function voters(): array
    {
        return [];
    }

    /**
     * Declare resource policies contributed by this provider.
     * Map of resource slug or FQCN => PolicyInterface class-string.
     *
     * @return array<string, class-string<\Glueful\Permissions\PolicyInterface>>
     */
    public function policies(): array
    {
        return [];
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

    /**
     * Register a notification channel into the shared {@see ChannelManager}.
     *
     * Call from `boot()`. No-ops when the notification subsystem isn't present in the container.
     * Throws {@see \Glueful\Notifications\Exceptions\ChannelAlreadyRegisteredException} if a
     * *different* channel class already holds this channel's name (a real package conflict) — use
     * `ChannelManager::replaceChannel()` for intentional overrides.
     */
    protected function registerNotificationChannel(NotificationChannel $channel): void
    {
        if (!$this->app->has(ChannelManager::class)) {
            return;
        }

        /** @var ChannelManager $manager */
        $manager = $this->app->get(ChannelManager::class);
        $manager->registerChannel($channel);
    }

    /**
     * Register a {@see NotificationExtension} (before/after-send hooks) on the shared dispatcher.
     *
     * Call from `boot()`. No-ops when the dispatcher isn't present in the container.
     */
    protected function registerNotificationExtension(NotificationExtension $extension): void
    {
        if (!$this->app->has(NotificationDispatcher::class)) {
            return;
        }

        /** @var NotificationDispatcher $dispatcher */
        $dispatcher = $this->app->get(NotificationDispatcher::class);
        $dispatcher->registerExtension($extension);
    }

    /**
     * Register a migrations directory.
     *
     * @param string      $dir      Migration directory.
     * @param int         $priority Lower runs first (see MigrationPriority). Default DEFAULT (app tier).
     * @param string|null $source   Composer package name (e.g. "glueful/users"); defaults to the
     *                              directory's last segment for back-compat.
     */
    protected function loadMigrationsFrom(
        string $dir,
        int $priority = \Glueful\Database\Migrations\MigrationPriority::DEFAULT,
        ?string $source = null
    ): void {
        if (!is_dir($dir) || !$this->app->has(MigrationManager::class)) {
            return;
        }
        /** @var MigrationManager $mm */
        $mm = $this->app->get(MigrationManager::class);
        $mm->addMigrationPath($dir, $priority, $source);
    }

    /**
     * Merge default config (app overrides always win).
     * @param array<string, mixed> $defaults
     */
    protected function mergeConfig(string $key, array $defaults): void
    {
        if (!$this->app->has(ApplicationContext::class)) {
            return;
        }
        $context = $this->app->get(ApplicationContext::class);
        if ($context instanceof ApplicationContext) {
            $context->mergeConfigDefaults($key, $defaults);
        }
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
     * Serve a prebuilt SPA (or static bundle) at a literal path, with safe asset
     * serving and an optional index.html deep-link fallback for client-side routing.
     *
     * @param string $path  Literal mount path, e.g. '/admin' or '/app/console'. Strict:
     *                      a leading '/', lowercase [a-z0-9-] segments, no trailing slash.
     * @param string $dir   Filesystem directory of the built bundle.
     * @param array{spaFallback?: bool, name?: string} $options
     */
    protected function serveFrontend(string $path, string $dir, array $options = []): void
    {
        if (preg_match('#^/[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*$#', $path) !== 1) {
            throw new \InvalidArgumentException(
                "Invalid mount path '{$path}'. Use a leading slash and lowercase "
                . "[a-z0-9-] segments with no trailing slash (e.g. '/admin')."
            );
        }

        $spaFallback = (bool) ($options['spaFallback'] ?? true);
        $name = (string) ($options['name'] ?? $path);

        if (!$this->app->has(\Glueful\Routing\Router::class) || !is_dir($dir)) {
            return;
        }
        $realDir = realpath($dir);
        if ($realDir === false) {
            return;
        }
        if ($spaFallback && !is_file($realDir . DIRECTORY_SEPARATOR . 'index.html')) {
            $this->logFrontendWarning(
                "serveFrontend('{$path}') skipped: {$name} bundle at {$realDir} has no index.html."
            );
            return;
        }

        /** @var \Glueful\Routing\Router $router */
        $router = $this->app->get(\Glueful\Routing\Router::class);

        $serveAsset = $this->frontendAssetServer($realDir);
        $serveIndex = $this->frontendIndexServer($realDir);

        $router->get($path, function (
            \Symfony\Component\HttpFoundation\Request $request
        ) use (
            $spaFallback,
            $serveIndex
) {
            return $spaFallback
                ? $serveIndex($request)
                : new \Symfony\Component\HttpFoundation\Response('', 404);
        });

        $router->get($path . '/{rest}', function (
            \Symfony\Component\HttpFoundation\Request $request,
            string $rest
        ) use (
            $realDir,
            $spaFallback,
            $serveAsset,
            $serveIndex
) {
            if (headers_sent()) {
                return new \Symfony\Component\HttpFoundation\Response('', 404);
            }

            $basename = basename($rest);
            if ($basename === '' || $basename[0] === '.' || str_ends_with(strtolower($basename), '.php')) {
                return new \Symfony\Component\HttpFoundation\Response('', 404);
            }

            // Reject path-traversal sequences outright — a `..` segment must 404, never
            // fall through to the SPA shell (the realpath check below also rejects an
            // escaped *file*, but an extension-less traversal path would otherwise reach
            // the index.html fallback).
            if (preg_match('#(^|/)\.\.(/|$)#', $rest) === 1) {
                return new \Symfony\Component\HttpFoundation\Response('', 404);
            }

            $requested = realpath($realDir . DIRECTORY_SEPARATOR . $rest);
            if (
                $requested !== false
                && str_starts_with($requested, $realDir . DIRECTORY_SEPARATOR)
                && is_file($requested)
            ) {
                return $serveAsset($request, $requested, $basename);
            }

            if (!$spaFallback) {
                return new \Symfony\Component\HttpFoundation\Response('', 404);
            }
            // "A dot means an asset": a missing asset is a 404, never the HTML shell.
            if (pathinfo($rest, PATHINFO_EXTENSION) !== '') {
                return new \Symfony\Component\HttpFoundation\Response('', 404);
            }
            return $serveIndex($request);
        })->where('rest', '.+');
    }

    /**
     * Closure that streams a built asset with mime, security headers, the cache
     * split, ETag/Last-Modified and 304 handling.
     *
     * @return \Closure(\Symfony\Component\HttpFoundation\Request, string, string):
     *     \Symfony\Component\HttpFoundation\Response
     */
    private function frontendAssetServer(string $realDir): \Closure
    {
        return function (
            \Symfony\Component\HttpFoundation\Request $request,
            string $realPath,
            string $basename
        ): \Symfony\Component\HttpFoundation\Response {
            $mtime = filemtime($realPath) !== false ? filemtime($realPath) : time();
            $etag = md5_file($realPath) !== false ? md5_file($realPath) : sha1($realPath);

            $guesser = \Symfony\Component\Mime\MimeTypes::getDefault();
            $mimeGuess = mime_content_type($realPath);
            $mime = $guesser->guessMimeType($realPath)
                ?? ($mimeGuess !== false ? $mimeGuess : 'application/octet-stream');

            $resp = new \Symfony\Component\HttpFoundation\BinaryFileResponse($realPath);
            $resp->headers->set('Content-Type', $mime);
            foreach (\Glueful\Security\SecurityHeaders::defaultStaticAssetHeaders() as $header => $value) {
                $resp->headers->set($header, $value);
            }
            $resp->headers->set('Cache-Control', $this->frontendCacheControl($basename));
            $resp->setEtag('"' . $etag . '"');
            $resp->setLastModified((new \DateTimeImmutable())->setTimestamp($mtime));
            $resp->setContentDisposition(
                \Symfony\Component\HttpFoundation\ResponseHeaderBag::DISPOSITION_INLINE,
                $basename
            );
            $resp->isNotModified($request);
            return $resp;
        };
    }

    /**
     * Cache-Control for a served file: content-hashed assets are immutable;
     * everything else (incl. index.html) revalidates so deploys are seen.
     */
    private function frontendCacheControl(string $basename): string
    {
        return preg_match('/[.\-_][A-Za-z0-9]{8,}\.[A-Za-z0-9]+$/', $basename) === 1
            ? 'public, max-age=31536000, immutable'
            : 'no-cache';
    }

    /**
     * Closure that serves index.html (200, no-cache, hardened headers, revalidatable).
     *
     * @return \Closure(\Symfony\Component\HttpFoundation\Request): \Symfony\Component\HttpFoundation\Response
     */
    private function frontendIndexServer(string $realDir): \Closure
    {
        return function (
            \Symfony\Component\HttpFoundation\Request $request
        ) use ($realDir): \Symfony\Component\HttpFoundation\Response {
            $index = $realDir . DIRECTORY_SEPARATOR . 'index.html';
            if (!is_file($index)) {
                return new \Symfony\Component\HttpFoundation\Response('', 404);
            }
            $resp = new \Symfony\Component\HttpFoundation\BinaryFileResponse($index);
            $resp->headers->set('Content-Type', 'text/html; charset=UTF-8');
            foreach (\Glueful\Security\SecurityHeaders::defaultStaticAssetHeaders() as $header => $value) {
                $resp->headers->set($header, $value);
            }
            $resp->headers->set('Cache-Control', 'no-cache');
            $mtime = filemtime($index) !== false ? filemtime($index) : time();
            $etag = md5_file($index) !== false ? md5_file($index) : sha1($index);
            $resp->setEtag('"' . $etag . '"');
            $resp->setLastModified((new \DateTimeImmutable())->setTimestamp($mtime));
            $resp->isNotModified($request);
            return $resp;
        };
    }

    /** Emit a boot-time warning through the container's logger when available. */
    private function logFrontendWarning(string $message): void
    {
        if ($this->app->has(\Psr\Log\LoggerInterface::class)) {
            $this->app->get(\Psr\Log\LoggerInterface::class)->warning($message);
        }
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

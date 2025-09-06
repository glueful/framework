<?php

declare(strict_types=1);

namespace Glueful\Extensions;

use Psr\Container\ContainerInterface;
use Glueful\Database\Migrations\MigrationManager;
use Glueful\Routing\Router;

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
    public function register(): void
    {
        /*
         * Extensions can safely do config merging, route registration, etc. here.
         * Service mutation blocking is enforced at the container level via
         * bind/singleton/alias APIs when container is frozen.
         */
    }

    /** Boot after all providers are registered. */
    public function boot(): void
    {
 /* optional */
    }

    /** Load routes from a file; file will use $router from the container. */
    protected function loadRoutesFrom(string $path): void
    {
        if (!is_file($path) || !$this->app->has(Router::class)) {
            return;
        }
        // Expose $router to the included file scope for route definitions
        /** @var Router $router */
        $router = $this->app->get(Router::class);
        require $path;
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
            $resp->headers->set('X-Content-Type-Options', 'nosniff');
            $resp->headers->set('Cross-Origin-Resource-Policy', 'same-origin'); // Prevent passive leaks via <img src>
            // Basic hardening headers (safe defaults; override in app as needed)
            $resp->headers->set(
                'Content-Security-Policy',
                "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:;"
            );
            $resp->headers->set('Referrer-Policy', 'no-referrer');
            $resp->headers->set('X-Frame-Options', 'SAMEORIGIN');
            $resp->headers->set('X-XSS-Protection', '0'); // Modern browsers rely on CSP
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
        if (!$this->runningInConsole() || !$this->app->has('console.application')) {
            return;
        }
        $console = $this->app->get('console.application');
        foreach ($commands as $class) {
            $console->add($this->app->get($class));
        }
    }

    protected function runningInConsole(): bool
    {
        return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
    }
}

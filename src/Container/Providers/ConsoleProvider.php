<?php

declare(strict_types=1);

namespace Glueful\Container\Providers;

use Glueful\Container\Definition\DefinitionInterface;
use Glueful\Support\Version;
use Symfony\Component\Console\Attribute\AsCommand;
use ReflectionClass;

/**
 * Console Command Service Provider
 *
 * Registers all console commands with the DI container using auto-discovery.
 *
 * - Development: Scans Commands directory for classes with #[AsCommand]
 * - Production: Uses cached manifest for fast startup
 *
 * Cache is auto-generated on first production run or via:
 *   php glueful commands:cache
 */
final class ConsoleProvider extends BaseServiceProvider
{
    private const CACHE_FILE = 'glueful_commands_manifest.php';

    /**
     * @return array<string, DefinitionInterface|callable|mixed>
     */
    public function defs(): array
    {
        $defs = [];

        foreach ($this->getCommands() as $class) {
            $defs[$class] = $this->autowire($class);
            $this->tag($class, 'console.commands', 0);
        }

        return $defs;
    }

    /**
     * Get command classes - from cache in production, discovery in development
     *
     * @return array<string>
     */
    private function getCommands(): array
    {
        $isProduction = $this->isProduction();
        $cacheFile = $this->getCacheFilePath();

        // Production: use the cache if it is present AND every entry still exists. A manifest
        // written by an older framework (or, before 1.81.2, by another user on the same host)
        // can name classes that are gone; trusting it fed phantom definitions into the
        // container, failed compilation, and threw out of the console when the tagged commands
        // were resolved. Any stale entry means rediscover and rewrite.
        if ($isProduction && file_exists($cacheFile)) {
            $cached = require $cacheFile;
            if (is_array($cached)) {
                $valid = array_values(array_filter($cached, static fn ($c): bool => is_string($c) && class_exists($c)));
                if ($valid !== [] && count($valid) === count($cached)) {
                    return $valid;
                }
            }
        }

        // Discover commands
        $commands = $this->discoverCommands();

        // Production: write cache for next time
        if ($isProduction) {
            $this->writeCache($cacheFile, $commands);
        }

        return $commands;
    }

    /**
     * Auto-discover command classes from the Commands directory
     *
     * Scans recursively for PHP files and includes classes that:
     * - Are not abstract
     * - Have the #[AsCommand] attribute
     *
     * @return array<string>
     */
    private function discoverCommands(): array
    {
        $commands = [];
        $commandsDir = dirname(__DIR__, 2) . '/Console/Commands';

        if (!is_dir($commandsDir)) {
            return $commands;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($commandsDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $className = $this->fileToClassName($file->getPathname(), $commandsDir);

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
     * Convert file path to fully qualified class name
     */
    private function fileToClassName(string $filePath, string $baseDir): ?string
    {
        $relativePath = str_replace($baseDir . DIRECTORY_SEPARATOR, '', $filePath);
        $relativePath = str_replace(DIRECTORY_SEPARATOR, '\\', $relativePath);
        $relativePath = preg_replace('/\.php$/', '', $relativePath);

        if ($relativePath === null) {
            return null;
        }

        return 'Glueful\\Console\\Commands\\' . $relativePath;
    }

    /**
     * Check if a class is a valid command (not abstract, has #[AsCommand])
     */
    private function isValidCommand(string $className): bool
    {
        if (!class_exists($className)) {
            return false;
        }

        try {
            $reflection = new ReflectionClass($className);

            // Skip abstract classes (BaseCommand, BaseSecurityCommand, etc.)
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
     * Check if running in production mode
     */
    private function isProduction(): bool
    {
        $env = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? getenv('APP_ENV') ?: 'production';
        return $env === 'production' || $env === 'prod';
    }

    /**
     * The manifest belongs to the APP (base path from the context), never to the framework
     * package dir — which does not exist in a dist install — and never to a host-wide temp
     * file shared across users, sites and framework versions.
     */
    public function getCacheFilePath(): string
    {
        return self::cacheFilePathFor($this->context->getBasePath());
    }

    public static function cacheFilePathFor(?string $basePath): string
    {
        if ($basePath !== null) {
            $storageCache = rtrim($basePath, '/') . '/storage/cache';
            if (is_dir($storageCache) && is_writable($storageCache)) {
                return $storageCache . '/' . self::CACHE_FILE;
            }
        }

        return self::temporaryCachePath();
    }

    /** Per-user AND per-framework-version, so two sites (or two upgrades) never share one. */
    private static function temporaryCachePath(): string
    {
        return sys_get_temp_dir() . '/glueful-commands-manifest-' . Version::VERSION . '-' . getmyuid() . '.php';
    }

    /**
     * Discover from disk and (re)write the manifest. Used by `commands:cache`.
     *
     * @return array<string>
     */
    public function rebuildCache(): array
    {
        $commands = $this->discoverCommands();
        $this->writeCache($this->getCacheFilePath(), $commands);

        return $commands;
    }

    /**
     * Write commands to cache file
     *
     * @param string $cacheFile
     * @param array<string> $commands
     */
    private function writeCache(string $cacheFile, array $commands): void
    {
        $content = "<?php\n\n// Auto-generated by ConsoleProvider - do not edit\n// Generated: "
            . date('Y-m-d H:i:s') . "\n\nreturn " . var_export($commands, true) . ";\n";

        file_put_contents($cacheFile, $content, LOCK_EX);
    }

    /**
     * Clear the command cache (called by commands:clear)
     */
    public static function clearCache(?string $basePath = null): bool
    {
        $cleared = false;
        foreach (self::candidateLocations($basePath) as $file) {
            if (file_exists($file) && @unlink($file)) {
                $cleared = true;
            }
        }

        return $cleared;
    }

    /**
     * Get cache file location (for status/debugging)
     */
    public static function getCacheLocation(?string $basePath = null): ?string
    {
        foreach (self::candidateLocations($basePath) as $file) {
            if (file_exists($file)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Every place a manifest may live: the app's storage/cache, the per-user temp file, and the
     * two pre-1.81.2 locations (framework package dir, host-shared temp file) so `commands:clear`
     * can retire a stale legacy manifest.
     *
     * @return list<string>
     */
    private static function candidateLocations(?string $basePath): array
    {
        $files = [];
        if ($basePath !== null) {
            $files[] = rtrim($basePath, '/') . '/storage/cache/' . self::CACHE_FILE;
        }
        $files[] = self::temporaryCachePath();
        $files[] = dirname(__DIR__, 3) . '/storage/cache/' . self::CACHE_FILE;
        $files[] = sys_get_temp_dir() . '/' . self::CACHE_FILE;

        return array_values(array_unique($files));
    }
}

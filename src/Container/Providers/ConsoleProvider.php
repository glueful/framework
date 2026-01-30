<?php

declare(strict_types=1);

namespace Glueful\Container\Providers;

use Glueful\Container\Definition\DefinitionInterface;
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

        // Production: use cache if available
        if ($isProduction && file_exists($cacheFile)) {
            $cached = require $cacheFile;
            if (is_array($cached)) {
                return $cached;
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
     * Get the cache file path
     */
    private function getCacheFilePath(): string
    {
        // Use framework's storage/cache if available, otherwise sys_get_temp_dir
        $storageCache = dirname(__DIR__, 3) . '/storage/cache';

        if (is_dir($storageCache) && is_writable($storageCache)) {
            return $storageCache . '/' . self::CACHE_FILE;
        }

        return sys_get_temp_dir() . '/' . self::CACHE_FILE;
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
    public static function clearCache(): bool
    {
        $storageCache = dirname(__DIR__, 3) . '/storage/cache/' . self::CACHE_FILE;
        $tempCache = sys_get_temp_dir() . '/' . self::CACHE_FILE;

        $cleared = false;

        if (file_exists($storageCache)) {
            unlink($storageCache);
            $cleared = true;
        }

        if (file_exists($tempCache)) {
            unlink($tempCache);
            $cleared = true;
        }

        return $cleared;
    }

    /**
     * Get cache file location (for status/debugging)
     */
    public static function getCacheLocation(): ?string
    {
        $storageCache = dirname(__DIR__, 3) . '/storage/cache/' . self::CACHE_FILE;
        $tempCache = sys_get_temp_dir() . '/' . self::CACHE_FILE;

        if (file_exists($storageCache)) {
            return $storageCache;
        }

        if (file_exists($tempCache)) {
            return $tempCache;
        }

        return null;
    }
}

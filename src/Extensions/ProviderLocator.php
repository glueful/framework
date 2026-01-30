<?php

declare(strict_types=1);

namespace Glueful\Extensions;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Unified provider discovery for both compile-time and runtime phases.
 * Prevents mismatches where config-enabled extensions work in dev but break in prod.
 */
final class ProviderLocator
{
    /**
     * Get all extension providers in deterministic discovery order.
     * Supports enterprise features: allow-list mode and blacklisting.
     * @return list<class-string>
     */
    public static function all(ApplicationContext $context): array
    {
        // Exclusive allow-list mode (highest priority)
        // Prefer app service providers allow-list if defined; otherwise fall back to extensions.
        $appOnly = config($context, 'serviceproviders.only');
        if ($appOnly !== null) {
            return array_values((array) $appOnly);
        }
        $extOnly = config($context, 'extensions.only');
        if ($extOnly !== null) {
            return array_values((array) $extOnly);
        }

        $providers = [];

        // 1) enabled (preserve order) — app providers first, then extensions
        foreach ((array) config($context, 'serviceproviders.enabled', []) as $cls) {
            $providers[] = $cls;
        }
        foreach ((array) config($context, 'extensions.enabled', []) as $cls) {
            $providers[] = $cls;
        }

        // 2) dev_only (preserve order)
        $appEnv = $context->getEnvironment();
        if ($appEnv !== 'production') {
            // 2) dev_only — app providers first, then extensions
            foreach ((array) config($context, 'serviceproviders.dev_only', []) as $cls) {
                $providers[] = $cls;
            }
            foreach ((array) config($context, 'extensions.dev_only', []) as $cls) {
                $providers[] = $cls;
            }

            // 3) local scan (sort by folder name for stability)
            $localPath = config($context, 'extensions.local_path');
            if ($localPath !== null) {
                $local = self::scanLocalExtensions($context, $localPath);
                sort($local, SORT_STRING);
                array_push($providers, ...$local);
            }
        }

        // 4) composer scan (already deterministic in PackageManifest)
        $scanComposer = config($context, 'extensions.scan_composer', true);
        if ($scanComposer === true) {
            $providers = array_merge(
                $providers,
                array_values((new PackageManifest($context))->getGluefulProviders())
            );
        }

        // dedupe while preserving first occurrence
        $providers = array_values(array_unique($providers, SORT_STRING));

        // Apply blacklist filter with strict comparison and normalization
        // Union of app-level disabled and extensions-level disabled
        $disabled = array_merge(
            (array) config($context, 'serviceproviders.disabled', []),
            (array) config($context, 'extensions.disabled', [])
        );
        $disabled = array_values(array_unique(array_map('strval', $disabled)));

        if (count($disabled) > 0) {
            $providers = array_values(array_filter($providers, fn($cls) => !in_array($cls, $disabled, true)));
        }

        return $providers;
    }

    /**
     * Scan local extensions with same rules as ExtensionManager.
     * Duplicated intentionally to keep compile-time/runtime parity; update both together.
     * @return list<class-string>
     */
    private static function scanLocalExtensions(ApplicationContext $context, string $path): array
    {
        $providers = [];
        $extensionsPath = base_path($context, $path);

        if (!is_dir($extensionsPath)) {
            return [];
        }

        // include only immediate subdirs; glob() excludes dot-dirs by default
        $pattern = $extensionsPath . '/*/composer.json';
        $files = glob($pattern) !== false ? glob($pattern) : [];

        // Same limits to prevent pathological folders
        $maxProjects = 200;
        if (count($files) > $maxProjects) {
            error_log("[ProviderLocator] Too many local extensions found, limiting to {$maxProjects}");
            $files = array_slice($files, 0, $maxProjects);
        }

        foreach ($files as $file) {
            // Skip symlinks and check file readability
            if (is_link($file) || !is_readable($file)) {
                continue;
            }

            // Safe filesize check to prevent warnings on unreadable files
            $filesize = @filesize($file);
            if ($filesize === false || $filesize > 1024 * 100) {
                continue; // Skip unreadable or oversized files
            }

            try {
                $json = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

                // Register PSR-4 mappings so local providers autoload correctly
                if (isset($json['autoload']['psr-4']) && is_array($json['autoload']['psr-4'])) {
                    $baseDir = realpath(dirname($file)) !== false ? realpath(dirname($file)) : dirname($file);

                    static $composerLoader = null;
                    if ($composerLoader === null) {
                        $composerLoader = require base_path($context, 'vendor/autoload.php');
                    }

                    if ($composerLoader instanceof \Composer\Autoload\ClassLoader) {
                        foreach ($json['autoload']['psr-4'] as $ns => $relPath) {
                            $fullPath = rtrim($baseDir, '/\\') . '/' . ltrim((string) $relPath, '/\\') . '/';
                            if (is_dir($fullPath) && is_readable($fullPath)) {
                                $composerLoader->addPsr4((string) $ns, $fullPath, true); // prepend=true so local wins
                            }
                        }
                        $composerLoader->register(true);
                    }
                }

                if (isset($json['extra']['glueful']['provider'])) {
                    $providers[] = $json['extra']['glueful']['provider'];
                }
            } catch (\JsonException $e) {
                error_log("[ProviderLocator] Invalid composer.json in {$file}: " . $e->getMessage());
            }
        }

        return $providers;
    }
}

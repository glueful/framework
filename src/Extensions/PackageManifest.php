<?php

declare(strict_types=1);

namespace Glueful\Extensions;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Discovers Glueful extensions from Composer's installed metadata.
 * Supports Composer 2 installed.php and installed.json (both shapes).
 */
final class PackageManifest
{
    /** @var array<string, class-string> package name => provider FQCN */
    private array $providers;

    public function __construct(private ApplicationContext $context)
    {
        $this->providers = $this->discover();
    }

    /** @return array<string, class-string> */
    public function getGluefulProviders(): array
    {
        return $this->providers;
    }

    /** @return array<string, class-string> */
    private function discover(): array
    {
        // Prefer installed.php — normalized and fast
        $installedPhp = base_path($this->context, 'vendor/composer/installed.php');
        if (is_file($installedPhp)) {
            try {
                /** @var array<string, mixed> $installed */
                $installed = require $installedPhp;
                return $this->extractFromInstalledPhp($installed);
            } catch (\Throwable $e) {
                error_log('[Extensions] installed.php load failed: ' . $e->getMessage());
            }
        }

        // Fallback to installed.json (may be array-of-packages or {packages: [...]})
        $installedJson = base_path($this->context, 'vendor/composer/installed.json');
        if (!is_file($installedJson)) {
            return [];
        }

        try {
            $data = json_decode((string) file_get_contents($installedJson), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            error_log('[Extensions] installed.json parse failed: ' . $e->getMessage());
            return [];
        }

        $packages = $data['packages'] ?? (is_array($data) ? $data : []);
        return $this->extractFromPackagesArray($packages);
    }

    /**
     * @param array<string, mixed> $installed
     * @return array<string, class-string>
     * @phpstan-return array<string, class-string>
     */
    private function extractFromInstalledPhp(array $installed): array
    {
        $out = [];

        // Common Composer 2 shape
        if (isset($installed['versions']) && is_array($installed['versions'])) {
            foreach ($installed['versions'] as $name => $pkg) {
                if (($pkg['type'] ?? '') !== 'glueful-extension') {
                    continue;
                }
                $provider = $pkg['extra']['glueful']['provider'] ?? null;
                if (is_string($provider) && str_contains($provider, '\\')) {
                    // Optional compatibility check
                    $currentVersion = \defined('Glueful\\Framework\\GLUEFUL_VERSION') ?
                        \constant('Glueful\\Framework\\GLUEFUL_VERSION') : '0.0.0';
                    $min = $pkg['extra']['glueful']['minVersion'] ?? null;
                    if (\is_string($min) && \version_compare($currentVersion, $min, '<')) {
                        error_log("[Extensions] {$name} requires Glueful {$min}, current {$currentVersion}");
                        // Still allow registration - just warn
                    }
                    $out[$name] = $provider;
                }
            }
            return $out;
        }

        // Multi-vendor datasets (less common)
        foreach ($installed as $entry) {
            if (!is_array($entry) || !isset($entry['versions'])) {
                continue;
            }
            foreach ($entry['versions'] as $name => $pkg) {
                if (($pkg['type'] ?? '') !== 'glueful-extension') {
                    continue;
                }
                $provider = $pkg['extra']['glueful']['provider'] ?? null;
                if (is_string($provider) && str_contains($provider, '\\')) {
                    $out[$name] = $provider;
                }
            }
        }

        return $out;
    }

    /**
     * @param array<int, array<string,mixed>> $packages
     * @return array<string, class-string>
     * @phpstan-return array<string, class-string>
     */
    private function extractFromPackagesArray(array $packages): array
    {
        $out = [];
        foreach ($packages as $pkg) {
            if (!is_array($pkg)) {
                continue;
            }
            if (($pkg['type'] ?? '') !== 'glueful-extension') {
                continue;
            }

            $provider = $pkg['extra']['glueful']['provider'] ?? null;
            if (is_string($provider) && str_contains($provider, '\\')) {
                $name = $pkg['name'] ?? 'unknown';
                $out[$name] = $provider;
            }
        }
        ksort($out); // deterministic order by package name
        return $out;
    }
}

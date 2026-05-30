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
    public function __construct(private ApplicationContext $context)
    {
    }

    /** @return array<string, ExtensionCandidate> package name => candidate */
    public function getCandidates(): array
    {
        $out = [];
        foreach ($this->rawPackages() as $name => $pkg) {
            if (($pkg['type'] ?? '') !== 'glueful-extension') {
                continue;
            }
            $glueful = is_array($pkg['extra']['glueful'] ?? null) ? $pkg['extra']['glueful'] : [];
            $provider = $glueful['provider'] ?? null;
            if (!is_string($provider) || !str_contains($provider, '\\')) {
                continue;
            }
            $requires = is_array($glueful['requires'] ?? null) ? $glueful['requires'] : [];
            // Prefer Composer's pretty version; installed.json exposes it as `version`,
            // installed.php as `pretty_version`. Fall back to the author-declared
            // extra.glueful.version.
            $version = $pkg['pretty_version'] ?? $pkg['version'] ?? ($glueful['version'] ?? null);
            $out[(string) $name] = new ExtensionCandidate(
                name: (string) $name,
                provider: ltrim($provider, '\\'),
                requiresGlueful: is_string($requires['glueful'] ?? null) ? $requires['glueful'] : null,
                requiresExtensions: array_values(array_filter(
                    (array) ($requires['extensions'] ?? []),
                    'is_string'
                )),
                version: is_string($version) ? $version : null,
            );
        }
        ksort($out);
        return $out;
    }

    /**
     * Normalized "package name => package array" map of installed packages.
     *
     * Prefers vendor/composer/installed.json because it carries the full package
     * metadata — crucially `extra` (and therefore `extra.glueful.provider` /
     * `requires`), which getCandidates() needs. Composer's optimized installed.php
     * OMITS `extra`, so reading it for extension discovery yields nothing; it is
     * only a fallback for the rare case where installed.json is absent (e.g. a
     * hand-built fixture).
     *
     * @return array<string, array<string, mixed>>
     */
    private function rawPackages(): array
    {
        $installedJson = base_path($this->context, 'vendor/composer/installed.json');
        if (is_file($installedJson)) {
            $json = json_decode((string) file_get_contents($installedJson), true);
            if (is_array($json)) {
                $packages = is_array($json['packages'] ?? null) ? $json['packages'] : $json;
                $byName = [];
                foreach ($packages as $pkg) {
                    if (is_array($pkg) && isset($pkg['name'])) {
                        $byName[(string) $pkg['name']] = $pkg;
                    }
                }
                if ($byName !== []) {
                    return $byName;
                }
            }
        }

        // Fallback: installed.php (versions shape or multi-vendor dataset). Note this
        // omits `extra` in real Composer installs, so extension discovery here is
        // best-effort — installed.json is the expected source.
        $installedPhp = base_path($this->context, 'vendor/composer/installed.php');
        if (is_file($installedPhp)) {
            /** @var array<string, mixed> $installed */
            $installed = require $installedPhp;
            if (isset($installed['versions']) && is_array($installed['versions'])) {
                /** @var array<string, array<string, mixed>> $versions */
                $versions = $installed['versions'];
                return $versions;
            }
            $merged = [];
            foreach ($installed as $entry) {
                if (is_array($entry) && isset($entry['versions']) && is_array($entry['versions'])) {
                    foreach ($entry['versions'] as $name => $pkg) {
                        if (is_array($pkg)) {
                            $merged[(string) $name] = $pkg;
                        }
                    }
                }
            }
            if ($merged !== []) {
                return $merged;
            }
        }

        return [];
    }
}

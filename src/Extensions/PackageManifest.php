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

    /**
     * Provider FQCN → owning composer package, from `extra.glueful.provider` across ALL
     * installed packages REGARDLESS of type. Extension candidacy stays type-filtered
     * ({@see getCandidates()}); ownership deliberately does not — a host may ship
     * library-typed provider packages (app-integrated modules) that still deserve stable
     * `managed_by` attribution. Two packages claiming one provider is a fatal
     * configuration error, never a silent last-one-wins.
     *
     * @return array<string, string>
     */
    public function providerOwnership(): array
    {
        $owners = [];
        foreach ($this->rawPackages() as $name => $pkg) {
            $glueful = is_array($pkg['extra']['glueful'] ?? null) ? $pkg['extra']['glueful'] : [];
            $provider = $glueful['provider'] ?? null;
            if (!is_string($provider)) {
                continue;
            }
            $provider = ltrim($provider, '\\');
            if (!str_contains($provider, '\\')) {
                continue;
            }
            if (isset($owners[$provider])) {
                throw new \RuntimeException(sprintf(
                    'Provider %s is declared by two packages: %s and %s.',
                    $provider,
                    $owners[$provider],
                    $name
                ));
            }
            $owners[$provider] = (string) $name;
        }

        return $owners;
    }

    /**
     * Declared migration descriptors, all packages (schema policy spec B1): any package whose
     * extra.glueful block declares a `migrations` key participates — a list of descriptor rows
     * or the explicit string "none" (empty list). Absence is NOT fatal here (legacy packages
     * stay bootable); it is reported by undeclaredGluefulPackages() and the schema-on-enable
     * operations fail closed on it. Malformed declarations throw.
     *
     * @return array<string, list<Schema\MigrationDescriptor>>
     */
    public function migrationDescriptors(): array
    {
        $priorities = [
            'foundation' => \Glueful\Database\Migrations\MigrationPriority::FOUNDATION,
            'identity' => \Glueful\Database\Migrations\MigrationPriority::IDENTITY,
            'platform' => \Glueful\Database\Migrations\MigrationPriority::PLATFORM,
            'default' => \Glueful\Database\Migrations\MigrationPriority::DEFAULT,
            'dependent' => \Glueful\Database\Migrations\MigrationPriority::DEPENDENT,
        ];
        $out = [];
        foreach ($this->rawPackages() as $name => $pkg) {
            $glueful = $pkg['extra']['glueful'] ?? null;
            if (!is_array($glueful) || !array_key_exists('migrations', $glueful)) {
                continue;
            }
            $migrations = $glueful['migrations'];
            if ($migrations === 'none') {
                $out[(string) $name] = [];
                continue;
            }
            if (!is_array($migrations) || $migrations === [] || !array_is_list($migrations)) {
                throw new Schema\DescriptorValidationException(
                    "Package {$name}: 'migrations' must be a non-empty list of descriptor rows or the "
                    . 'explicit string "none" (an empty schema declares "none").'
                );
            }
            $list = [];
            foreach ($migrations as $row) {
                if (!is_array($row)) {
                    throw new Schema\DescriptorValidationException(
                        "Package {$name}: every migrations row must be an object/array."
                    );
                }
                $priorityKey = (string) ($row['priority'] ?? '');
                $mode = Schema\DescriptorMode::tryFrom((string) ($row['mode'] ?? ''));
                if (!isset($priorities[$priorityKey]) || $mode === null) {
                    throw new Schema\DescriptorValidationException(
                        "Package {$name}: descriptor priority/mode must use the closed enums "
                        . '(foundation|identity|platform|default|dependent, core|on_enable).'
                    );
                }
                $verifier = $row['verifier'] ?? null;
                $list[] = new Schema\MigrationDescriptor(
                    id: (string) ($row['id'] ?? ''),
                    package: (string) $name,
                    packageType: (string) ($pkg['type'] ?? 'library'),
                    relativePath: (string) ($row['path'] ?? ''),
                    priority: $priorities[$priorityKey],
                    mode: $mode,
                    legacyAliases: array_values((array) ($row['legacyAliases'] ?? [])),
                    verifierClass: is_string($verifier) ? $verifier : null,
                );
            }
            $out[(string) $name] = $list;
        }
        ksort($out);
        return $out;
    }

    /**
     * Glueful packages (extra.glueful present) that declare no `migrations` key. They remain
     * bootable; the schema-on-enable operations refuse them with UndeclaredSchemaException.
     *
     * @return list<string>
     */
    public function undeclaredGluefulPackages(): array
    {
        $out = [];
        foreach ($this->rawPackages() as $name => $pkg) {
            $glueful = $pkg['extra']['glueful'] ?? null;
            if (is_array($glueful) && !array_key_exists('migrations', $glueful)) {
                $out[] = (string) $name;
            }
        }
        sort($out);
        return $out;
    }

    /**
     * Provider FQCN => package name, for EVERY package with an extra.glueful.provider — extension
     * AND library types alike (the inventory's provider-ownership fast path needs both).
     *
     * @return array<string, string>
     */
    public function providerPackages(): array
    {
        $out = [];
        foreach ($this->rawPackages() as $name => $pkg) {
            $provider = $pkg['extra']['glueful']['provider'] ?? null;
            if (is_string($provider) && str_contains($provider, '\\')) {
                $out[ltrim($provider, '\\')] = (string) $name;
            }
        }
        return $out;
    }

    /**
     * Absolute install dir per package, resolved from installed.json's `install-path`
     * (relative to vendor/composer/). Textually normalized, not realpath'd — descriptor path
     * containment does its own canonical checks against the live filesystem.
     *
     * @return array<string, string>
     */
    public function installPaths(): array
    {
        $composerDir = base_path($this->context, 'vendor/composer');
        $out = [];
        foreach ($this->rawPackages() as $name => $pkg) {
            $rel = $pkg['install-path'] ?? null;
            if (!is_string($rel) || $rel === '') {
                continue;
            }
            $joined = str_starts_with($rel, '/') ? $rel : $composerDir . '/' . $rel;
            $parts = [];
            foreach (explode('/', $joined) as $seg) {
                if ($seg === '' || $seg === '.') {
                    continue;
                }
                if ($seg === '..') {
                    array_pop($parts);
                    continue;
                }
                $parts[] = $seg;
            }
            $out[(string) $name] = '/' . implode('/', $parts);
        }
        ksort($out);
        return $out;
    }
}

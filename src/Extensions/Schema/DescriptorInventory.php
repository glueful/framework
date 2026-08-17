<?php

declare(strict_types=1);

namespace Glueful\Extensions\Schema;

use Glueful\Extensions\PackageManifest;
use Glueful\Services\FileFinder;

/**
 * The validated global migration inventory (schema policy spec B1): manifest-declared descriptors
 * merged with the framework's built-in leaves. Construction fails closed on every collision the
 * ledger identity model cannot tolerate — duplicate sources, duplicate or nested canonical paths,
 * contested aliases, unresolvable or empty paths, and duplicate basenames within a descriptor
 * (receipt identity is (source, basename) and discovery is recursive).
 */
final class DescriptorInventory
{
    /** @var array<string, MigrationDescriptor> source => descriptor */
    private array $bySource = [];
    /** @var array<string, list<MigrationDescriptor>> package => descriptors */
    private array $byPackage = [];
    /** @var array<string, string> source => canonical absolute path */
    private array $paths = [];
    /** @var array<string, list<string>> source => discovered migration files (basename-sorted) */
    private array $files = [];
    /** @var array<string, string> alias => source */
    private array $aliases = [];
    /** @var array<string, true> declared package names */
    private array $declared = [];
    /** @var array<string, string> provider FQCN => package */
    private array $providers = [];
    /** @var array<string, string> package => canonical (realpath) install root */
    private array $installRoots = [];

    private function __construct()
    {
    }

    public static function fromManifest(PackageManifest $manifest, string $frameworkRoot, FileFinder $files): self
    {
        $inv = new self();
        $installPaths = $manifest->installPaths();
        $inv->providers = $manifest->providerPackages();
        foreach ($installPaths as $package => $dir) {
            $real = realpath($dir);
            if ($real !== false) {
                $inv->installRoots[$package] = $real;
            }
        }

        foreach (FrameworkDescriptors::all($frameworkRoot) as $descriptor) {
            $inv->add($descriptor, $frameworkRoot, $files);
        }
        $inv->declared[FrameworkDescriptors::PACKAGE] = true;

        foreach ($manifest->migrationDescriptors() as $package => $descriptors) {
            $inv->declared[$package] = true;
            foreach ($descriptors as $descriptor) {
                $dir = $installPaths[$package] ?? null;
                if ($dir === null) {
                    throw new DescriptorValidationException(
                        "Package {$package} declares descriptors but has no resolvable install path."
                    );
                }
                $inv->add($descriptor, $dir, $files);
            }
        }

        $inv->assertNoNestedPaths();
        $inv->assertAliasIntegrity();
        return $inv;
    }

    private function add(MigrationDescriptor $descriptor, string $packageDir, FileFinder $finder): void
    {
        $source = $descriptor->source();
        if (isset($this->bySource[$source])) {
            throw new DescriptorValidationException(
                "Descriptor source '{$source}' is declared more than once "
                . "({$this->bySource[$source]->package} vs {$descriptor->package})."
            );
        }
        $path = $descriptor->absolutePath($packageDir);
        $pathOwner = array_search($path, $this->paths, true);
        if ($pathOwner !== false) {
            throw new DescriptorValidationException(
                "Descriptors '{$pathOwner}' and '{$source}' declare the same canonical path {$path}."
            );
        }

        $discovered = [];
        foreach ($finder->findMigrations($path) as $file) {
            $discovered[] = $file->getPathname();
        }
        if ($discovered === []) {
            throw new DescriptorValidationException(
                "Descriptor '{$source}' path {$path} contains no migrations; "
                . 'an empty schema declares migrations: none instead.'
            );
        }
        usort($discovered, static fn(string $a, string $b): int => strcmp(basename($a), basename($b)));
        $seen = [];
        foreach ($discovered as $file) {
            $basename = basename($file);
            if (isset($seen[$basename])) {
                throw new DescriptorValidationException(
                    "Descriptor '{$source}' contains duplicate migration basename '{$basename}' "
                    . '(receipt identity is (source, basename); discovery is recursive).'
                );
            }
            $seen[$basename] = true;
        }

        $this->bySource[$source] = $descriptor;
        $this->byPackage[$descriptor->package][] = $descriptor;
        $this->paths[$source] = $path;
        $this->files[$source] = $discovered;
        foreach ($descriptor->legacyAliases as $alias) {
            if (isset($this->aliases[$alias])) {
                throw new DescriptorValidationException(
                    "Legacy alias '{$alias}' is claimed by both '{$this->aliases[$alias]}' and '{$source}'."
                );
            }
            $this->aliases[$alias] = $source;
        }
    }

    private function assertNoNestedPaths(): void
    {
        $entries = $this->paths;
        asort($entries);
        $previousSource = null;
        $previousPath = null;
        foreach ($entries as $source => $path) {
            if ($previousPath !== null && str_starts_with($path, $previousPath . '/')) {
                throw new DescriptorValidationException(
                    "Descriptor '{$source}' path is nested inside '{$previousSource}''s path; recursive "
                    . 'discovery would batch its files twice under two sources.'
                );
            }
            $previousSource = $source;
            $previousPath = $path;
        }
    }

    private function assertAliasIntegrity(): void
    {
        foreach ($this->aliases as $alias => $source) {
            if (isset($this->bySource[$alias])) {
                throw new DescriptorValidationException(
                    "Legacy alias '{$alias}' (of '{$source}') collides with a live descriptor source."
                );
            }
        }
    }

    /** @return list<MigrationDescriptor> */
    public function all(): array
    {
        return array_values($this->bySource);
    }

    public function bySource(string $source): ?MigrationDescriptor
    {
        return $this->bySource[$source] ?? null;
    }

    /** @return list<MigrationDescriptor> */
    public function forPackage(string $package): array
    {
        return $this->byPackage[$package] ?? [];
    }

    public function isDeclared(string $package): bool
    {
        return isset($this->declared[$package]);
    }

    public function packageOfProvider(string $providerClass): ?string
    {
        return $this->providers[ltrim($providerClass, '\\')] ?? null;
    }

    public function pathOf(MigrationDescriptor $descriptor): string
    {
        return $this->paths[$descriptor->source()];
    }

    /** @return list<string> */
    public function filesOf(MigrationDescriptor $descriptor): array
    {
        return $this->files[$descriptor->source()];
    }

    /** @return array<string, string> alias => source */
    public function aliasIndex(): array
    {
        return $this->aliases;
    }

    /**
     * Package ownership of an arbitrary class by FILE containment: the class file's realpath is
     * matched against the canonical install roots (longest prefix wins). This is the slow path
     * behind packageOfProvider() — a second, undeclared provider class shipped inside a declared
     * package still answers to that package's manifest. Returns null for app-local classes.
     */
    public function packageOfClassFile(string $class): ?string
    {
        if (!class_exists($class)) {
            return null;
        }
        $file = (new \ReflectionClass($class))->getFileName();
        if ($file === false) {
            return null;
        }
        $real = realpath($file);
        if ($real === false) {
            return null;
        }
        $bestPackage = null;
        $bestLength = -1;
        foreach ($this->installRoots as $package => $root) {
            if (str_starts_with($real, $root . '/') && strlen($root) > $bestLength) {
                $bestPackage = $package;
                $bestLength = strlen($root);
            }
        }
        return $bestPackage;
    }
}

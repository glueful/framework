<?php

declare(strict_types=1);

namespace Glueful\Extensions\Install;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Support\Process\ComposerBinaryResolver;

/**
 * Preflight: can this host actually perform the write-heavy install / toggle?
 *
 * Install runs `composer require` and recompiles the extension cache, so it needs
 * every file it mutates to be writable. Enable/disable don't run composer but do
 * rewrite config/extensions.php and recompile the cache, so they check that subset.
 * On a read-only/immutable deploy this reports a reason the controller maps to 409
 * BEFORE anything is spawned — instead of failing halfway through.
 */
final class HostCapability
{
    public function __construct(
        private ApplicationContext $context,
        private ComposerBinaryResolver $composer,
    ) {
    }

    /** @return array{reason:string,detail:string}|null null = installable */
    public function forInstall(): ?array
    {
        if ($this->composerBinary() === null) {
            return ['reason' => 'composer_missing', 'detail' => 'composer binary not found on PATH'];
        }
        return $this->firstUnwritable($this->installPaths());
    }

    /** @return array{reason:string,detail:string}|null null = toggleable */
    public function forToggle(): ?array
    {
        return $this->firstUnwritable($this->togglePaths());
    }

    public function composerBinary(): ?string
    {
        return $this->composer->resolve();
    }

    /** @return list<string> */
    private function installPaths(): array
    {
        return [
            base_path($this->context, 'vendor'),
            base_path($this->context, 'composer.json'),
            base_path($this->context, 'composer.lock'),
            config_path($this->context, 'extensions.php'),
            base_path($this->context, 'bootstrap/cache'),
        ];
    }

    /** @return list<string> */
    private function togglePaths(): array
    {
        return [
            config_path($this->context, 'extensions.php'),
            base_path($this->context, 'bootstrap/cache'),
        ];
    }

    /**
     * @param list<string> $paths
     * @return array{reason:string,detail:string}|null
     */
    private function firstUnwritable(array $paths): ?array
    {
        foreach ($paths as $path) {
            // Check the path itself when it exists; otherwise the nearest existing
            // ancestor — the directory the tool will create the file/dir in. A
            // missing vendor/composer.lock under a read-only base MUST fail here.
            if (!is_writable($this->writabilityTarget($path))) {
                return ['reason' => 'read_only_filesystem', 'detail' => $path];
            }
        }
        return null;
    }

    private function writabilityTarget(string $path): string
    {
        $current = $path;
        while (!file_exists($current)) {
            $parent = dirname($current);
            if ($parent === $current) {
                return $current; // reached filesystem root
            }
            $current = $parent;
        }
        return $current;
    }
}

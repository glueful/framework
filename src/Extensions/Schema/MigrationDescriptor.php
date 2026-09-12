<?php

declare(strict_types=1);

namespace Glueful\Extensions\Schema;

/**
 * One manifest-declared migration source (schema policy spec B1). Identity is (package, id) — a
 * package may declare several descriptors (multiple tracks); the ledger `source` derives from
 * that identity, with the 'default' id collapsing to the bare package name so existing
 * single-track receipts keep their identity.
 */
final class MigrationDescriptor
{
    /**
     * @param string|null $verifierClass Structural-verifier FQCN — manifest metadata (not a
     *        provider contribution) so adoption can discover it while the owning extension is
     *        disabled. Syntax-validated here; existence/conformance checked at use.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $package,
        public readonly string $packageType,
        public readonly string $relativePath,
        public readonly int $priority,
        public readonly DescriptorMode $mode,
        public readonly ?string $verifierClass = null,
        /**
         * Source names this lane's files were recorded under before (an application that became a
         * package, a renamed package, a lane split out of a package). Rows under any of them count
         * as applied for this lane, and the next run adopts them under {@see source()}.
         *
         * @var list<string>
         */
        public readonly array $previousSources = [],
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $id) !== 1) {
            throw new DescriptorValidationException("Descriptor id '{$id}' is not a lowercase slug.");
        }
        if ($mode === DescriptorMode::OnEnable && $packageType !== 'glueful-extension') {
            throw new DescriptorValidationException(
                "Descriptor '{$package}:{$id}' declares on_enable but package type is '{$packageType}'; "
                . 'only glueful-extension packages have an enable event (library schemas are core).'
            );
        }
        if ($relativePath === '' || str_starts_with($relativePath, '/') || str_contains($relativePath, '..')) {
            throw new DescriptorValidationException(
                "Descriptor '{$package}:{$id}' path '{$relativePath}' must be relative and traversal-free."
            );
        }
        if (
            $verifierClass !== null
            && preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)+[A-Za-z_][A-Za-z0-9_]*$/D', $verifierClass) !== 1
        ) {
            throw new DescriptorValidationException(
                "Descriptor '{$package}:{$id}' verifier must be a canonical, non-leading-slash FQCN."
            );
        }
        $this->assertPreviousSources();
    }

    /** Validates {@see $previousSources}; called by the constructor after the other checks. */
    private function assertPreviousSources(): void
    {
        foreach ($this->previousSources as $previous) {
            if (!is_string($previous) || preg_match('/^[a-z0-9][a-z0-9_\-\/.:]*$/', $previous) !== 1) {
                throw new DescriptorValidationException(
                    "Descriptor '{$this->package}:{$this->id}' previous_sources must be source names "
                    . "(e.g. 'app', 'vendor/package', 'vendor/package:lane')."
                );
            }
            if ($previous === $this->source()) {
                throw new DescriptorValidationException(
                    "Descriptor '{$this->package}:{$this->id}' lists its own source '{$previous}' as a previous source."
                );
            }
        }
    }

    public function source(): string
    {
        return $this->id === 'default' ? $this->package : $this->package . ':' . $this->id;
    }

    /**
     * Canonical containment: the joined path must resolve (realpath) INSIDE the resolved package
     * directory — a missing dir or a symlink pointing outside is rejected.
     */
    public function absolutePath(string $packageDir): string
    {
        $baseReal = realpath($packageDir);
        $joinedReal = realpath(rtrim($packageDir, '/') . '/' . $this->relativePath);
        if ($baseReal === false || $joinedReal === false) {
            throw new DescriptorValidationException(
                "Descriptor '{$this->source()}' path '{$this->relativePath}' does not resolve under {$packageDir}."
            );
        }
        if ($joinedReal !== $baseReal && !str_starts_with($joinedReal, $baseReal . '/')) {
            throw new DescriptorValidationException(
                "Descriptor '{$this->source()}' path resolves outside its package directory (symlink escape)."
            );
        }
        return $joinedReal;
    }
}

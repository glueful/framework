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
     * @param list<string> $legacyAliases Ledger sources this descriptor also answers for.
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
        public readonly array $legacyAliases = [],
        public readonly ?string $verifierClass = null,
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
        $seen = [];
        foreach ($legacyAliases as $alias) {
            if (!is_string($alias) || $alias === '' || isset($seen[$alias])) {
                throw new DescriptorValidationException(
                    "Descriptor '{$package}:{$id}' legacy aliases must be unique non-empty strings."
                );
            }
            $seen[$alias] = true;
        }
        if (
            $verifierClass !== null
            && preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)+[A-Za-z_][A-Za-z0-9_]*$/D', $verifierClass) !== 1
        ) {
            throw new DescriptorValidationException(
                "Descriptor '{$package}:{$id}' verifier must be a canonical, non-leading-slash FQCN."
            );
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

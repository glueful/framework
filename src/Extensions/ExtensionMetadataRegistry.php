<?php

declare(strict_types=1);

namespace Glueful\Extensions;

final class ExtensionMetadataRegistry
{
    /** @var array<class-string, array<string, mixed>> */
    private array $meta = [];

    /**
     * @param array<string, mixed> $data
     */
    public function set(string $providerClass, array $data): void
    {
        $this->meta[$providerClass] = $data;
    }

    /**
     * @return array<class-string, array<string, mixed>>
     */
    public function all(): array
    {
        // deterministic by provider FQCN
        ksort($this->meta);
        return $this->meta;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $providerClass): ?array
    {
        return $this->meta[$providerClass] ?? null;
    }
}

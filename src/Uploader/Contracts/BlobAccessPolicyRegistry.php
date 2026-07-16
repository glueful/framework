<?php

declare(strict_types=1);

namespace Glueful\Uploader\Contracts;

/**
 * Shared, framework-bound registry of named BlobAccessPolicy contributors.
 *
 * Registered as a normal shared service by StorageProvider — available before
 * any extension's boot() runs, with no static accessor and no process-global
 * fallback. Extensions register their policy under a stable id during boot();
 * CompositeBlobAccessPolicy holds this registry object (not a copy) and reads
 * all() fresh on every authorization call, so registration order relative to
 * controller construction never matters.
 */
final class BlobAccessPolicyRegistry
{
    /** @var array<string, BlobAccessPolicy> */
    private array $policies = [];

    /**
     * @throws \LogicException when $id is already registered
     */
    public function register(string $id, BlobAccessPolicy $policy): void
    {
        if (isset($this->policies[$id])) {
            throw new \LogicException(
                sprintf('A blob access policy is already registered under id "%s".', $id)
            );
        }

        $this->policies[$id] = $policy;
    }

    public function has(string $id): bool
    {
        return isset($this->policies[$id]);
    }

    /**
     * @return array<string, BlobAccessPolicy> insertion-ordered
     */
    public function all(): array
    {
        return $this->policies;
    }
}

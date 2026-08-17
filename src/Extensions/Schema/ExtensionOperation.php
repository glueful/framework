<?php

declare(strict_types=1);

namespace Glueful\Extensions\Schema;

/**
 * One row of the core-owned extension_operations record (schema policy spec B5).
 */
final class ExtensionOperation
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_MANUAL_REPAIR = 'manual_repair';
    public const STATUS_CACHE_STALE = 'enabled_cache_stale';

    public function __construct(
        public readonly int $id,
        public readonly string $package,
        public readonly string $operation,
        public readonly string $step,
        public readonly string $status,
        public readonly string $actor,
        public readonly ?string $failedMigration = null,
        public readonly ?string $error = null,
    ) {
    }

    public function with(string $step, string $status, ?string $failedMigration = null, ?string $error = null): self
    {
        return new self(
            $this->id,
            $this->package,
            $this->operation,
            $step,
            $status,
            $this->actor,
            $failedMigration ?? $this->failedMigration,
            $error ?? $this->error,
        );
    }
}

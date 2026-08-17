<?php

declare(strict_types=1);

namespace Glueful\Extensions\Schema;

/**
 * Custody over a set of acquired migration-lock sources. release() frees them in reverse
 * acquisition order and is idempotent. Callers wrap work in try/finally around it.
 */
final class MigrationLockHandle
{
    /** @param list<callable(): void> $releasers */
    public function __construct(private array $releasers)
    {
    }

    public function release(): void
    {
        foreach (array_reverse($this->releasers) as $release) {
            $release();
        }
        $this->releasers = [];
    }
}

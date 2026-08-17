<?php

declare(strict_types=1);

namespace Glueful\Extensions\Schema;

/**
 * Serializes schema operations per migration source (schema policy spec B4). Implementations must
 * be schema-independent — they may never depend on a table this program could still be migrating
 * (which rules out the configured LockManager's database store).
 */
interface MigrationLockInterface
{
    /**
     * Acquire all sources in deterministic sorted order. Bounded wait: each source is retried
     * (100ms sleep) until the shared deadline; on failure at source N, sources 1..N-1 already
     * acquired are RELEASED before LockContentionException is thrown (no partial custody).
     * The returned handle holds until release() — there is no TTL and no expiry mid-migration.
     *
     * @param list<string> $sources
     * @throws \Glueful\Database\Exceptions\LockContentionException
     */
    public function acquireAll(array $sources, int $waitSeconds = 10): MigrationLockHandle;
}

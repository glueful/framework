<?php

declare(strict_types=1);

namespace Glueful\Extensions\Schema;

use Glueful\Database\Connection;

/**
 * PostgreSQL advisory-lock backend: pg_try_advisory_lock over hashtext('schema:'||source) in the
 * bounded retry loop (NEVER the blocking pg_advisory_lock, which cannot honor the wait window).
 * Session-scoped — the lock survives exactly as long as the connection, so it cannot expire
 * mid-migration and vanishes on crash. Schema-independent and cross-host.
 */
final class PgsqlAdvisoryMigrationLock extends AbstractTryMigrationLock
{
    public function __construct(private readonly Connection $connection)
    {
    }

    protected function tryAcquire(string $source): bool
    {
        $stmt = $this->connection->getPDO()->prepare('SELECT pg_try_advisory_lock(hashtext(?))');
        $stmt->execute(['schema:' . $source]);
        return (bool) $stmt->fetchColumn();
    }

    protected function releaseSource(string $source): void
    {
        $stmt = $this->connection->getPDO()->prepare('SELECT pg_advisory_unlock(hashtext(?))');
        $stmt->execute(['schema:' . $source]);
    }
}

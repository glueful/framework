<?php

declare(strict_types=1);

namespace Glueful\Extensions\Schema;

use Glueful\Database\Connection;

/**
 * MySQL named-lock backend: GET_LOCK('schema:'+sha1(source), 0) in the bounded retry loop,
 * RELEASE_LOCK on release. Session-scoped, schema-independent, and serializes across hosts —
 * which a host-local flock cannot.
 */
final class MysqlNamedMigrationLock extends AbstractTryMigrationLock
{
    public function __construct(private readonly Connection $connection)
    {
    }

    protected function tryAcquire(string $source): bool
    {
        $stmt = $this->connection->getPDO()->prepare('SELECT GET_LOCK(?, 0)');
        $stmt->execute([$this->name($source)]);
        return (int) $stmt->fetchColumn() === 1;
    }

    protected function releaseSource(string $source): void
    {
        $stmt = $this->connection->getPDO()->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute([$this->name($source)]);
    }

    private function name(string $source): string
    {
        // MySQL lock names are limited to 64 chars; sha1 keeps arbitrary sources within it.
        return 'schema:' . sha1($source);
    }
}

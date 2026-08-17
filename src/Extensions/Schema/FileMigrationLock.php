<?php

declare(strict_types=1);

namespace Glueful\Extensions\Schema;

/**
 * flock()-based migration lock for SQLite/local operation and tests. Process/host-local only —
 * multi-node deployments use the pgsql advisory or mysql named-lock backends.
 */
class FileMigrationLock extends AbstractTryMigrationLock
{
    /** @var array<string, resource> */
    private array $handles = [];

    public function __construct(private readonly string $lockDir)
    {
    }

    protected function tryAcquire(string $source): bool
    {
        if (!is_dir($this->lockDir)) {
            mkdir($this->lockDir, 0777, true);
        }
        $path = $this->lockDir . '/schema-' . sha1($source) . '.lock';
        $handle = fopen($path, 'c');
        if ($handle === false) {
            return false;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }
        $this->handles[$source] = $handle;
        return true;
    }

    protected function releaseSource(string $source): void
    {
        $handle = $this->handles[$source] ?? null;
        if ($handle !== null) {
            flock($handle, LOCK_UN);
            fclose($handle);
            unset($this->handles[$source]);
        }
    }
}

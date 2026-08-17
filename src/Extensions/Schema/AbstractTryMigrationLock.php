<?php

declare(strict_types=1);

namespace Glueful\Extensions\Schema;

use Glueful\Database\Exceptions\LockContentionException;

/**
 * Template for try-acquire lock backends: sorted acquisition, bounded 100ms-retry waits against a
 * shared deadline, and rollback of partial custody on contention.
 */
abstract class AbstractTryMigrationLock implements MigrationLockInterface
{
    public function acquireAll(array $sources, int $waitSeconds = 10): MigrationLockHandle
    {
        $sources = array_values(array_unique($sources));
        sort($sources, SORT_STRING);
        $deadline = microtime(true) + $waitSeconds;
        /** @var list<callable(): void> $releasers */
        $releasers = [];
        foreach ($sources as $source) {
            while (true) {
                if ($this->tryAcquire($source)) {
                    $releasers[] = fn() => $this->releaseSource($source);
                    break;
                }
                if (microtime(true) >= $deadline) {
                    foreach (array_reverse($releasers) as $release) {
                        $release();
                    }
                    throw new LockContentionException(
                        "Another schema operation holds the migration lock for '{$source}'."
                    );
                }
                usleep(100_000);
            }
        }
        return new MigrationLockHandle($releasers);
    }

    abstract protected function tryAcquire(string $source): bool;

    abstract protected function releaseSource(string $source): void;
}

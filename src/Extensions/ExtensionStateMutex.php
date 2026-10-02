<?php

declare(strict_types=1);

namespace Glueful\Extensions;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;

/**
 * The one lock every change to the enabled extension list holds, from reading the list (or
 * resolving providers) through rebuilding the extension cache. The state writer rewrites the
 * whole list, so two changes that each read before the other wrote would drop one of them; and a
 * cache rebuilt from a list resolved before another change lands would undo it.
 *
 * PostgreSQL: a session-level advisory lock on hashtext('glueful:extension-state'). Otherwise an
 * exclusive flock on storage/framework/locks/extension-state.lock. The key is public: an
 * application that writes the list itself takes the same lock. The wait is bounded by
 * `extensions.state_lock_wait` (seconds, default 30).
 */
final class ExtensionStateMutex
{
    public const KEY = 'glueful:extension-state';

    /**
     * @internal test seam: called with the context right after the lock is acquired, before $fn.
     * @var (\Closure(ApplicationContext): void)|null
     */
    public static ?\Closure $afterAcquire = null;

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function within(ApplicationContext $context, callable $fn, ?Connection $db = null): mixed
    {
        $wait = max(1, (int) $context->getConfig('extensions.state_lock_wait', 30));
        if ($db !== null && $db->getDriverName() === 'pgsql') {
            return self::withinAdvisoryLock($context, $db, $wait, $fn);
        }
        return self::withinFileLock($context, $wait, $fn);
    }

    private static function withinAdvisoryLock(
        ApplicationContext $context,
        Connection $db,
        int $wait,
        callable $fn,
    ): mixed {
        $pdo = $db->getPDO();
        $try = $pdo->prepare('SELECT pg_try_advisory_lock(hashtext(?))');
        $deadline = microtime(true) + $wait;
        while (true) {
            $try->execute([self::KEY]);
            if ((bool) $try->fetchColumn()) {
                break;
            }
            if (microtime(true) >= $deadline) {
                throw self::timedOut($wait);
            }
            usleep(100_000);
        }
        try {
            self::acquired($context);
            return $fn();
        } finally {
            $pdo->prepare('SELECT pg_advisory_unlock(hashtext(?))')->execute([self::KEY]);
        }
    }

    private static function withinFileLock(ApplicationContext $context, int $wait, callable $fn): mixed
    {
        $dir = $context->getBasePath() . '/storage/framework/locks';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $handle = fopen($dir . '/extension-state.lock', 'c');
        if ($handle === false) {
            throw new \RuntimeException("Can't open the extension-state lock file in {$dir}.");
        }
        $deadline = microtime(true) + $wait;
        try {
            while (!flock($handle, LOCK_EX | LOCK_NB)) {
                if (microtime(true) >= $deadline) {
                    throw self::timedOut($wait);
                }
                usleep(100_000);
            }
            try {
                self::acquired($context);
                return $fn();
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    private static function acquired(ApplicationContext $context): void
    {
        if (self::$afterAcquire !== null) {
            (self::$afterAcquire)($context);
        }
    }

    private static function timedOut(int $wait): \RuntimeException
    {
        return new \RuntimeException("Another change to the extension list is still running (waited {$wait}s).");
    }
}

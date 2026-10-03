<?php

declare(strict_types=1);

namespace Glueful\Extensions;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Database\Exceptions\LockContentionException;

/**
 * The one lock every change to the enabled extension list holds, from reading the list (or
 * resolving providers) through rebuilding the extension cache. The state writer rewrites the
 * whole list, so two changes that each read before the other wrote would drop one of them; and a
 * cache rebuilt from a list resolved before another change lands would undo it.
 *
 * What it guards are files (config/extensions.php and bootstrap/cache/extensions.php), so it is an
 * exclusive flock on storage/framework/locks/extension-state.lock, on every database driver and
 * with no database at all (a fresh project runs extensions:cache before .env is filled in). The
 * operating system releases it when the process exits. It is re-entrant within a process: a holder
 * that reaches another within() for the same application runs it inline instead of waiting on
 * itself. An application that writes the list itself takes the same lock through within(). The
 * wait is bounded by `extensions.state_lock_wait` (seconds, default 30).
 */
final class ExtensionStateMutex
{
    /** The lock's name, kept for callers that referenced it; the lock itself is the file. */
    public const KEY = 'glueful:extension-state';

    /**
     * @internal test seam: called with the context right after the lock is acquired, before $fn.
     * @var (\Closure(ApplicationContext): void)|null
     */
    public static ?\Closure $afterAcquire = null;

    /** @var array<string, int> lock file => how deeply this process holds it */
    private static array $held = [];

    /**
     * @template T
     * @param callable(): T $fn
     * @param Connection|null $db ignored since 1.88.2: the lock is a file lock on every driver
     * @return T
     * @throws LockContentionException when another change still holds it after the wait
     */
    public static function within(ApplicationContext $context, callable $fn, ?Connection $db = null): mixed
    {
        $dir = $context->getBasePath() . '/storage/framework/locks';
        $path = $dir . '/extension-state.lock';
        if ((self::$held[$path] ?? 0) > 0) {
            return self::holding($path, $fn);
        }

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $handle = fopen($path, 'c');
        if ($handle === false) {
            throw new \RuntimeException("Can't open the extension-state lock file in {$dir}.");
        }
        $wait = max(1, (int) $context->getConfig('extensions.state_lock_wait', 30));
        $deadline = microtime(true) + $wait;
        try {
            while (!flock($handle, LOCK_EX | LOCK_NB)) {
                if (microtime(true) >= $deadline) {
                    throw self::timedOut($wait);
                }
                usleep(100_000);
            }
            try {
                if (self::$afterAcquire !== null) {
                    (self::$afterAcquire)($context);
                }
                return self::holding($path, $fn);
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private static function holding(string $path, callable $fn): mixed
    {
        self::$held[$path] = (self::$held[$path] ?? 0) + 1;
        try {
            return $fn();
        } finally {
            if (--self::$held[$path] === 0) {
                unset(self::$held[$path]);
            }
        }
    }

    /** Contention, like a migration lock held elsewhere: a caller can tell it apart and retry later. */
    private static function timedOut(int $wait): LockContentionException
    {
        return new LockContentionException("Another change to the extension list is still running (waited {$wait}s).");
    }
}

<?php

declare(strict_types=1);

namespace Glueful\Support\Process;

use Glueful\Bootstrap\ApplicationContext;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Fire-and-forget spawn for the extensions install runner.
 *
 * The install POST must return immediately while the composer run (30s–minutes)
 * continues in the background. Two properties, from two mechanisms:
 *  - NON-BLOCKING comes from proc_open — we never wait/proc_close, and proc_open
 *    children are not reaped by PHP at shutdown (unlike Symfony Process, whose
 *    __destruct SIGKILLs the child).
 *  - SURVIVAL across an FPM worker recycle comes from `setsid` (POSIX) — the child
 *    runs in a new session, so a signal to the worker's process group misses it.
 *
 * Always pure argv (no shell), so there is no injection surface. The escaped
 * string form (toShellCommand) exists only as a guarded fallback and is tested.
 */
final class DetachedRunner
{
    private \Closure $spawn;

    public function __construct(private ApplicationContext $context, ?\Closure $spawn = null)
    {
        $this->spawn = $spawn ?? self::defaultSpawn();
    }

    public function spawnInstall(string $jobId, string $package): void
    {
        $argv = $this->buildArgv($jobId, $package);
        $log = storage_path($this->context, "logs/ext-install-{$jobId}.log");
        ($this->spawn)($argv, base_path($this->context), $log);
    }

    /** @return list<string> */
    public function buildArgv(string $jobId, string $package): array
    {
        $argv = [
            PHP_BINARY,
            base_path($this->context, 'glueful'),
            'extensions:install-run',
            $jobId,
            $package,
        ];
        if ($this->hasSetsid()) {
            array_unshift($argv, 'setsid');
        }
        return $argv;
    }

    /**
     * Guarded string fallback — only for a platform that cannot take array argv.
     * Every segment is escapeshellarg'd, so a hostile package/job value cannot break
     * out. Kept covered by a unit test even though array argv is the live path.
     *
     * @param list<string> $argv
     */
    public static function toShellCommand(array $argv): string
    {
        return implode(' ', array_map('escapeshellarg', $argv));
    }

    private function hasSetsid(): bool
    {
        return (new ExecutableFinder())->find('setsid') !== null;
    }

    private static function defaultSpawn(): \Closure
    {
        return static fn(array $argv, string $cwd, string $log) => self::detachedSpawn($argv, $cwd, $log);
    }

    /**
     * The real detached spawn: proc_open with array argv (no shell), stdio to a
     * file, and NO proc_close() — so it returns immediately and the child is not
     * reaped by PHP at shutdown. Public + static so the real path is testable.
     *
     * @param list<string> $argv
     */
    public static function detachedSpawn(array $argv, string $cwd, string $log): void
    {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $log, 'w'],
            2 => ['file', $log, 'a'],
        ];
        $pipes = [];
        $proc = proc_open($argv, $descriptors, $pipes, $cwd); // array argv → no shell
        if (is_resource($proc)) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            // Deliberately NO proc_close() — that would wait. Detach and return.
        }
    }
}

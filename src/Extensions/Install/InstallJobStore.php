<?php

declare(strict_types=1);

namespace Glueful\Extensions\Install;

use Glueful\Cache\CacheStore;

/**
 * Transient status for a detached extension install, keyed by job id in the cache.
 *
 * The detached runner (a separate OS process) and the web request share this store,
 * so it must be backed by a shared driver (file/redis, not array) at runtime. The
 * admin page polls get() until a terminal status.
 *
 * Status values:
 *  - queued                — created, not yet started
 *  - running               — composer require in progress
 *  - succeeded             — installed AND enabled (or auto-enable off, no error)
 *  - installed_not_enabled — installed but auto-enable failed (enableError set)
 *  - failed                — composer/spawn failed
 */
final class InstallJobStore
{
    private const PREFIX = 'ext_install:';
    private const TTL = 3600;
    private const OUTPUT_CAP = 65536; // ~64KB tail

    /** @param CacheStore<mixed> $cache */
    public function __construct(private CacheStore $cache)
    {
    }

    public function create(string $package): string
    {
        $id = bin2hex(random_bytes(12));
        $this->put($id, [
            'id' => $id,
            'package' => $package,
            'status' => 'queued',
            'output' => '',
            'exitCode' => null,
            'error' => null,
            'enableError' => null,
            'startedAt' => date(DATE_ATOM),
            'finishedAt' => null,
        ]);
        return $id;
    }

    /** @return array<string,mixed>|null */
    public function get(string $jobId): ?array
    {
        $rec = $this->cache->get(self::PREFIX . $jobId);
        return is_array($rec) ? $rec : null;
    }

    public function markRunning(string $jobId): void
    {
        $this->patch($jobId, ['status' => 'running']);
    }

    public function appendOutput(string $jobId, string $chunk): void
    {
        $rec = $this->get($jobId);
        if ($rec === null) {
            return;
        }
        $out = (string) $rec['output'] . $chunk;
        if (strlen($out) > self::OUTPUT_CAP) {
            $out = substr($out, -self::OUTPUT_CAP);
        }
        $this->patch($jobId, ['output' => $out]);
    }

    public function finish(
        string $jobId,
        string $status,
        ?int $exitCode = null,
        ?string $error = null,
        ?string $enableError = null,
    ): void {
        $this->patch($jobId, [
            'status' => $status,
            'exitCode' => $exitCode,
            'error' => $error,
            'enableError' => $enableError,
            'finishedAt' => date(DATE_ATOM),
        ]);
    }

    /** @param array<string,mixed> $changes */
    private function patch(string $jobId, array $changes): void
    {
        $rec = $this->get($jobId);
        if ($rec === null) {
            return;
        }
        $this->put($jobId, array_merge($rec, $changes));
    }

    /** @param array<string,mixed> $rec */
    private function put(string $jobId, array $rec): void
    {
        $this->cache->set(self::PREFIX . $jobId, $rec, self::TTL);
    }
}

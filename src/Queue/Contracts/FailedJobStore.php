<?php

declare(strict_types=1);

namespace Glueful\Queue\Contracts;

/**
 * A queue driver that keeps the jobs it failed, and can list, retry and remove them. The
 * queue:failed / queue:retry / queue:forget / queue:flush commands work on any driver that
 * implements it (the database and Redis drivers do).
 */
interface FailedJobStore
{
    /**
     * Failed jobs, newest first.
     *
     * @return list<array{uuid: string, queue: string, job: string, exception: string, failed_at: string}>
     *         `failed_at` as `Y-m-d H:i:s`
     */
    public function failedJobs(?string $queue = null, int $limit = 50, int $offset = 0): array;

    /**
     * Put a failed job back on the queue it failed on, as a new job with fresh attempts, and
     * drop the failure. The stored payload's signature is verified first: an altered payload is
     * refused, never re-signed.
     *
     * @return string|null the new job's uuid, or null for an unknown failed-job uuid
     * @throws \RuntimeException when the payload is unreadable, unsigned or altered
     */
    public function retryFailed(string $uuid): ?string;

    /** Delete one failed job. */
    public function forgetFailed(string $uuid): bool;

    /** Delete every failed job, or one queue's. Returns how many were removed. */
    public function flushFailed(?string $queue = null): int;
}

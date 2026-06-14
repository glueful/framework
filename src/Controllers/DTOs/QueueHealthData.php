<?php

declare(strict_types=1);

namespace Glueful\Controllers\DTOs;

/**
 * Documentation-only 200 `data` payload for
 * {@see \Glueful\Controllers\HealthController::queue()}.
 *
 * Mirrors the documented shape from routes/health.php `@response 200` for
 * GET /health/queue: `status`, `queues`, `workers`, `reserved`, `issues`.
 *
 * The `queues` and `workers` sub-maps are dynamic metric blobs; they are typed
 * as `array<string,mixed>` so the DTO never reshapes them. The `issues` array
 * is a flat list of string issue identifiers.
 *
 * This DTO is NEVER constructed at runtime — it is reflected by
 * {@see \Glueful\Support\Documentation\ClassSchemaReflector} purely to document
 * the `data` payload the route returns (via `#[ApiResponse(200, ...)]`), mirroring
 * the `@response 200` shape in routes/health.php.
 */
final class QueueHealthData
{
    /** Overall queue status: healthy, degraded, or error. */
    public string $status = '';

    /**
     * Aggregated queue stats (pending, delayed, reserved, failed).
     *
     * @var array<string,mixed>
     */
    public array $queues = [];

    /**
     * Active worker information: active count and worker details.
     *
     * @var array<string,mixed>
     */
    public array $workers = [];

    /** Number of currently reserved jobs. */
    public int $reserved = 0;

    /**
     * List of issue identifiers (e.g. 'no_active_workers_with_pending_jobs').
     *
     * @var string[]
     */
    public array $issues = [];
}

<?php

declare(strict_types=1);

namespace Glueful\Controllers\DTOs;

/**
 * Documentation-only 200 `data` payload for
 * {@see \Glueful\Controllers\HealthController::database()}.
 *
 * Mirrors — by exact name AND declaration order — the `data` keys returned by
 * {@see \Glueful\Services\HealthService::checkDatabase()} on the success branch:
 * `status`, `message`, `driver`, `database`, `migrations_applied`, `connectivity_test`.
 *
 * This DTO is NEVER constructed at runtime — it is reflected by
 * {@see \Glueful\Support\Documentation\ClassSchemaReflector} purely to document
 * the `data` payload the route returns (via `#[ApiResponse(200, ...)]`), mirroring
 * the `@response 200 data` shape in routes/health.php.
 */
final class DatabaseHealthData
{
    /** Database status: ok, warning, or error. */
    public string $status = '';

    /** Human-readable database status message. */
    public string $message = '';

    /** Database driver name (e.g. sqlite, mysql, pgsql). */
    public string $driver = '';

    /** Current database name (null when it cannot be resolved). */
    public ?string $database = null;

    /** Number of applied migrations. */
    public int $migrations_applied = 0;

    /** Whether the connectivity test passed. */
    public bool $connectivity_test = false;
}

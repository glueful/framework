<?php

declare(strict_types=1);

namespace Glueful\Controllers\DTOs;

/**
 * Documentation-only 200 `data` payload for
 * {@see \Glueful\Controllers\HealthController::cache()}.
 *
 * Mirrors — by exact name AND declaration order — the `data` keys returned by
 * {@see \Glueful\Services\HealthService::checkCache()} on the success branch:
 * `status`, `message`, `driver`, `operations`.
 *
 * This DTO is NEVER constructed at runtime — it is reflected by
 * {@see \Glueful\Support\Documentation\ClassSchemaReflector} purely to document
 * the `data` payload the route returns (via `#[ApiResponse(200, ...)]`), mirroring
 * the `@response 200 data` shape in routes/health.php.
 */
final class CacheHealthData
{
    /** Cache status: ok, disabled, or error. */
    public string $status = '';

    /** Human-readable cache status message. */
    public string $message = '';

    /** Cache driver name (e.g. redis, array, file). */
    public string $driver = '';

    /** Operations status (e.g. read/write/delete functional). */
    public string $operations = '';
}

<?php

declare(strict_types=1);

namespace Glueful\Controllers\DTOs;

/**
 * Documentation-only bare-response schema for
 * {@see \Glueful\Controllers\HealthController::startup()}.
 *
 * The startup handler returns `new Response(['status' => 'started'])` — a BARE
 * response that bypasses Glueful's `{success,message,data}` envelope entirely.
 * This DTO is NEVER constructed at runtime; it is reflected by
 * {@see \Glueful\Support\Documentation\ClassSchemaReflector} purely to document
 * the bare 200 body (via `#[ApiResponse(200, ..., envelope: false)]`), mirroring
 * the `@response 200` shape in routes/health.php.
 *
 * Using `envelope: false` on the attribute means the generated schema is the raw
 * DTO object (`{status: string}`), not wrapped in `{success, message, data}`.
 */
final class StartupData
{
    /** Always "started" once framework initialization has completed. */
    public string $status = 'started';
}

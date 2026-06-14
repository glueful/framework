<?php

declare(strict_types=1);

namespace Glueful\Controllers\DTOs;

/**
 * Documentation-only bare-response schema for
 * {@see \Glueful\Controllers\HealthController::liveness()}.
 *
 * The liveness handler returns `new Response(['status' => 'ok'])` — a BARE
 * response that bypasses Glueful's `{success,message,data}` envelope entirely.
 * This DTO is NEVER constructed at runtime; it is reflected by
 * {@see \Glueful\Support\Documentation\ClassSchemaReflector} purely to document
 * the bare 200 body (via `#[ApiResponse(200, ..., envelope: false)]`), mirroring
 * the `@response 200` shape in routes/health.php.
 *
 * Using `envelope: false` on the attribute means the generated schema is the raw
 * DTO object (`{status: string}`), not wrapped in `{success, message, data}`.
 */
final class LivenessData
{
    /** Always "ok" for a live process. */
    public string $status = 'ok';
}

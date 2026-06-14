<?php

declare(strict_types=1);

namespace Glueful\Controllers\DTOs;

/**
 * Documentation-only 200 `data` payload for
 * {@see \Glueful\Controllers\ResourceController::update()}.
 *
 * Mirrors the exact `data` shape the endpoint returns via
 * `Response::success(['affected' => 1, 'success' => true, 'message' => 'Record
 * updated successfully'])`. NEVER constructed at runtime — reflected by
 * {@see \Glueful\Support\Documentation\ClassSchemaReflector} purely to document
 * the `data` payload (via #[ApiResponse(200, ...)]).
 */
final class ResourceUpdatedData
{
    /** Number of records affected by the update. */
    public int $affected = 0;

    /** Operation outcome flag. */
    public bool $success = false;

    /** Human-readable result message. */
    public string $message = '';
}

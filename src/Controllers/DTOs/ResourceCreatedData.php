<?php

declare(strict_types=1);

namespace Glueful\Controllers\DTOs;

/**
 * Documentation-only 200 `data` payload for
 * {@see \Glueful\Controllers\ResourceController::store()}.
 *
 * Mirrors the exact `data` shape the endpoint returns via
 * `Response::success(['uuid' => $uuid, 'success' => true, 'message' => 'Record
 * created successfully'])`. NEVER constructed at runtime — reflected by
 * {@see \Glueful\Support\Documentation\ClassSchemaReflector} purely to document
 * the `data` payload (via #[ApiResponse(200, ...)]).
 */
final class ResourceCreatedData
{
    /** UUID of the newly created record. */
    public string $uuid = '';

    /** Operation outcome flag. */
    public bool $success = false;

    /** Human-readable result message. */
    public string $message = '';
}

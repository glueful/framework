<?php

declare(strict_types=1);

namespace Glueful\Controllers\DTOs;

/**
 * Documentation-only request body for
 * {@see \Glueful\Controllers\ResourceController::updateBulk()}.
 *
 * NEVER hydrated or validated at runtime — reflected by
 * {@see \Glueful\Support\Documentation\ClassSchemaReflector} purely to document
 * (via #[ApiRequestBody]) the JSON body the bulk-update route accepts. The legacy
 * comment docblock documented this generically (`@requestBody data:object="Bulk
 * update data with UUIDs and fields"`); this models the actual runtime shape the
 * handler reads — an `updates` array of `{uuid, data}` objects.
 */
final class BulkUpdateData
{
    /**
     * Per-record update instructions, each an object of `{uuid, data}`.
     *
     * @var array<int,mixed>
     */
    public array $updates = [];
}

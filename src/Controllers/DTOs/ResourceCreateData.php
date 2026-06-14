<?php

declare(strict_types=1);

namespace Glueful\Controllers\DTOs;

/**
 * Documentation-only request body for {@see \Glueful\Controllers\ResourceController::store()}.
 *
 * The resource endpoints are polymorphic: a single handler creates a record in
 * ANY table, so the columns are dynamic and cannot be modelled as fixed typed
 * properties. This DTO is NEVER hydrated or validated at runtime — it is reflected
 * by {@see \Glueful\Support\Documentation\ClassSchemaReflector} purely to document
 * (via #[ApiRequestBody]) the generic JSON object the route accepts, mirroring the
 * legacy comment docblock's `@requestBody data:object="Resource data to create"`.
 */
final class ResourceCreateData
{
    /**
     * Arbitrary column/value pairs for the new record (shape depends on the table).
     *
     * @var array<string,mixed>
     */
    public array $data = [];
}

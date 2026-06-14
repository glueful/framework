<?php

declare(strict_types=1);

namespace Glueful\Controllers\DTOs;

/**
 * Documentation-only record shape for the polymorphic resource reads
 * ({@see \Glueful\Controllers\ResourceController::show()} and the list items of
 * {@see \Glueful\Controllers\ResourceController::index()}).
 *
 * A single record can come from ANY table, so the columns are dynamic and cannot
 * be modelled as fixed typed properties. This DTO is NEVER constructed at runtime
 * — it is reflected by {@see \Glueful\Support\Documentation\ClassSchemaReflector}
 * purely to document the generic JSON object a record is, mirroring the legacy
 * comment docblock's untyped single-record/array responses.
 */
final class ResourceRecordData
{
    /**
     * Arbitrary column/value pairs of the record (shape depends on the table).
     *
     * @var array<string,mixed>
     */
    public array $attributes = [];
}

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
 * purely to document the generic JSON object a record is (a flat object of arbitrary
 * columns, e.g. `{id, uuid, name, …}`), mirroring the legacy comment docblock's
 * untyped single-record/array responses.
 *
 * Intentionally has NO properties: the record's keys are the table's columns, so
 * the faithful schema is a generic open object (`{type: object}`), not an object
 * with a fixed `attributes` wrapper. Declaring any property here would document a
 * key the wire response does not have.
 */
final class ResourceRecordData
{
}

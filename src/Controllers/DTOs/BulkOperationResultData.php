<?php

declare(strict_types=1);

namespace Glueful\Controllers\DTOs;

/**
 * Documentation-only 200 `data` payload for the resource bulk operations
 * ({@see \Glueful\Controllers\ResourceController::destroyBulk()} and
 * {@see \Glueful\Controllers\ResourceController::updateBulk()}).
 *
 * Mirrors the shared shape both bulk handlers return via `Response::success([...])`:
 * a per-operation count (`deleted`/`updated`), a `failed` list of
 * `{uuid, reason}` objects, the `total_requested` count, plus `success`/`message`.
 * The count key differs per operation; this DTO documents both via the two
 * nullable count properties. NEVER constructed at runtime — reflected by
 * {@see \Glueful\Support\Documentation\ClassSchemaReflector} purely for docs.
 */
final class BulkOperationResultData
{
    /** Number of records deleted (bulk-delete only; null on bulk-update). */
    public ?int $deleted = null;

    /** Number of records updated (bulk-update only; null on bulk-delete). */
    public ?int $updated = null;

    /**
     * Records that could not be processed, each `{uuid, reason}`.
     *
     * @var array<int,mixed>
     */
    public array $failed = [];

    /** Total number of records the request asked to process. */
    public int $total_requested = 0;

    /** True when at least one record was processed successfully. */
    public bool $success = false;

    /** Human-readable summary message. */
    public string $message = '';
}

<?php

declare(strict_types=1);

namespace Glueful\Http\Responses;

use Glueful\Http\Contracts\ResponseData;

/**
 * A typed, paginated list of response items. Returning one from a controller
 * renders Glueful's flat pagination envelope (mirroring
 * {@see \Glueful\Http\Response::paginated()}):
 * `{success, message, data: [...], current_page, per_page, total, total_pages,
 * has_next_page, has_previous_page}`.
 *
 * Items are typically {@see ResponseData} DTOs. For precise OpenAPI docs,
 * annotate the handler return with the item type:
 * `@return PaginatedResponse<PostData>`.
 *
 * Constructor validation fails loud on invalid pagination metadata: `perPage`
 * must be >= 1 (the downstream {@see \Glueful\Http\Response::paginated()} does
 * `ceil($total / $perPage)`, so `perPage: 0` would be a division-by-zero),
 * `page` must be >= 1, and `total` must be >= 0.
 */
final class PaginatedResponse
{
    /** @param list<mixed> $items */
    public function __construct(
        public readonly array $items,
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $total,
    ) {
        if ($page < 1) {
            throw new \InvalidArgumentException("PaginatedResponse page must be >= 1; got {$page}.");
        }
        if ($perPage < 1) {
            throw new \InvalidArgumentException("PaginatedResponse perPage must be >= 1; got {$perPage}.");
        }
        if ($total < 0) {
            throw new \InvalidArgumentException("PaginatedResponse total must be >= 0; got {$total}.");
        }
    }
}

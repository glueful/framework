<?php

declare(strict_types=1);

namespace Glueful\Api\Webhooks\DTOs\Responses;

/**
 * Documentation-only pagination block shared by the webhook list responses. Doc-only — reflected for
 * #[ApiResponse], never constructed at runtime.
 */
final class PaginationData
{
    /** Current page number. */
    public int $current_page = 1;

    /** Items per page. */
    public int $per_page = 25;

    /** Total matching rows. */
    public int $total = 0;

    /** Total number of pages. */
    public int $total_pages = 1;
}

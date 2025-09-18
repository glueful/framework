<?php

declare(strict_types=1);

namespace Glueful\DTOs;


/**
 * Pagination Metadata DTO
 */
class PaginationMetaDTO
{
    public int $currentPage;
    public int $perPage;
    public int $total;
    public int $totalPages;
    public bool $hasMorePages;
    public int $from;
    public int $to;

    public function __construct(
        int $currentPage,
        int $perPage,
        int $total,
        int $totalPages
    ) {
        $this->currentPage = $currentPage;
        $this->perPage = $perPage;
        $this->total = $total;
        $this->totalPages = $totalPages;
        $this->hasMorePages = $currentPage < $totalPages;
        $this->from = ($currentPage - 1) * $perPage + 1;
        $this->to = min($currentPage * $perPage, $total);
    }
}

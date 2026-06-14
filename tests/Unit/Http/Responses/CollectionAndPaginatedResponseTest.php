<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Http\Responses;

use Glueful\Http\Responses\CollectionResponse;
use Glueful\Http\Responses\PaginatedResponse;
use PHPUnit\Framework\TestCase;

final class CollectionAndPaginatedResponseTest extends TestCase
{
    public function testCollectionResponseHoldsItems(): void
    {
        $c = new CollectionResponse([['id' => 1], ['id' => 2]]);
        self::assertSame([['id' => 1], ['id' => 2]], $c->items);
    }

    public function testPaginatedResponseHoldsItemsAndMeta(): void
    {
        $p = new PaginatedResponse([['id' => 1]], page: 2, perPage: 10, total: 25);
        self::assertSame([['id' => 1]], $p->items);
        self::assertSame(2, $p->page);
        self::assertSame(10, $p->perPage);
        self::assertSame(25, $p->total);
    }

    public function testPaginatedResponseRejectsZeroPerPage(): void
    {
        // perPage 0 would cause a division-by-zero in Response::paginated()'s
        // ceil($total / $perPage) — fail loud at construction instead.
        $this->expectException(\InvalidArgumentException::class);
        new PaginatedResponse([], page: 1, perPage: 0, total: 0);
    }

    public function testPaginatedResponseRejectsZeroPage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PaginatedResponse([], page: 0, perPage: 10, total: 0);
    }

    public function testPaginatedResponseRejectsNegativeTotal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PaginatedResponse([], page: 1, perPage: 10, total: -1);
    }
}

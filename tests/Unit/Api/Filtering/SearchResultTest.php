<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Api\Filtering;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Glueful\Api\Filtering\SearchResult;

class SearchResultTest extends TestCase
{
    #[Test]
    public function createReturnsNewInstance(): void
    {
        $result = SearchResult::create(
            hits: [['id' => 1, 'name' => 'Test']],
            total: 1,
            took: 10.5,
            offset: 0,
            limit: 20
        );

        $this->assertCount(1, $result->hits);
        $this->assertEquals(1, $result->total);
        $this->assertEquals(10.5, $result->took);
        $this->assertEquals(0, $result->offset);
        $this->assertEquals(20, $result->limit);
    }

    #[Test]
    public function emptyCreatesEmptyResult(): void
    {
        $result = SearchResult::empty();

        $this->assertCount(0, $result->hits);
        $this->assertEquals(0, $result->total);
        $this->assertTrue($result->isEmpty());
        $this->assertFalse($result->hasHits());
    }

    #[Test]
    public function hasHitsReturnsTrueWhenHitsExist(): void
    {
        $result = SearchResult::create(
            hits: [['id' => 1]],
            total: 1
        );

        $this->assertTrue($result->hasHits());
        $this->assertFalse($result->isEmpty());
    }

    #[Test]
    public function countReturnsNumberOfHits(): void
    {
        $result = SearchResult::create(
            hits: [['id' => 1], ['id' => 2], ['id' => 3]],
            total: 10
        );

        $this->assertEquals(3, $result->count());
    }

    #[Test]
    public function hasMoreReturnsTrueWhenMoreResultsExist(): void
    {
        $result = SearchResult::create(
            hits: [['id' => 1], ['id' => 2]],
            total: 10,
            offset: 0,
            limit: 2
        );

        $this->assertTrue($result->hasMore());
    }

    #[Test]
    public function hasMoreReturnsFalseWhenNoMoreResults(): void
    {
        $result = SearchResult::create(
            hits: [['id' => 1], ['id' => 2]],
            total: 2,
            offset: 0,
            limit: 20
        );

        $this->assertFalse($result->hasMore());
    }

    #[Test]
    public function currentPageReturnsCorrectPage(): void
    {
        $result1 = SearchResult::create(hits: [], total: 100, offset: 0, limit: 20);
        $result2 = SearchResult::create(hits: [], total: 100, offset: 20, limit: 20);
        $result3 = SearchResult::create(hits: [], total: 100, offset: 40, limit: 20);

        $this->assertEquals(1, $result1->currentPage());
        $this->assertEquals(2, $result2->currentPage());
        $this->assertEquals(3, $result3->currentPage());
    }

    #[Test]
    public function totalPagesReturnsCorrectCount(): void
    {
        $result1 = SearchResult::create(hits: [], total: 100, limit: 20);
        $result2 = SearchResult::create(hits: [], total: 95, limit: 20);
        $result3 = SearchResult::create(hits: [], total: 0, limit: 20);

        $this->assertEquals(5, $result1->totalPages());
        $this->assertEquals(5, $result2->totalPages());
        $this->assertEquals(1, $result3->totalPages());
    }

    #[Test]
    public function getIdsExtractsIdsFromHits(): void
    {
        $result = SearchResult::create(
            hits: [
                ['id' => 1, 'name' => 'First'],
                ['id' => 2, 'name' => 'Second'],
                ['id' => 3, 'name' => 'Third'],
            ],
            total: 3
        );

        $this->assertEquals([1, 2, 3], $result->getIds());
    }

    #[Test]
    public function getIdsUsesCustomIdField(): void
    {
        $result = SearchResult::create(
            hits: [
                ['uuid' => 'a1', 'name' => 'First'],
                ['uuid' => 'b2', 'name' => 'Second'],
            ],
            total: 2
        );

        $this->assertEquals(['a1', 'b2'], $result->getIds('uuid'));
    }

    #[Test]
    public function pluckExtractsFieldFromHits(): void
    {
        $result = SearchResult::create(
            hits: [
                ['id' => 1, 'name' => 'Alice'],
                ['id' => 2, 'name' => 'Bob'],
                ['id' => 3, 'name' => 'Charlie'],
            ],
            total: 3
        );

        $this->assertEquals(['Alice', 'Bob', 'Charlie'], $result->pluck('name'));
    }

    #[Test]
    public function toArrayReturnsCompleteStructure(): void
    {
        $result = SearchResult::create(
            hits: [['id' => 1]],
            total: 100,
            took: 15.5,
            offset: 20,
            limit: 10,
            meta: ['adapter' => 'test']
        );

        $array = $result->toArray();

        $this->assertArrayHasKey('hits', $array);
        $this->assertArrayHasKey('total', $array);
        $this->assertArrayHasKey('took', $array);
        $this->assertArrayHasKey('pagination', $array);
        $this->assertArrayHasKey('meta', $array);

        $this->assertEquals(100, $array['total']);
        $this->assertEquals(15.5, $array['took']);
        $this->assertEquals(['adapter' => 'test'], $array['meta']);

        $pagination = $array['pagination'];
        $this->assertEquals(20, $pagination['offset']);
        $this->assertEquals(10, $pagination['limit']);
        $this->assertEquals(3, $pagination['current_page']);
        $this->assertEquals(10, $pagination['total_pages']);
        $this->assertTrue($pagination['has_more']);
    }

    #[Test]
    public function constructorAcceptsMetadata(): void
    {
        $result = new SearchResult(
            hits: [],
            total: 0,
            meta: ['source' => 'elasticsearch', 'max_score' => 1.5]
        );

        $this->assertEquals(['source' => 'elasticsearch', 'max_score' => 1.5], $result->meta);
    }
}

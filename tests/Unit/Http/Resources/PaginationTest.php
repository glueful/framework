<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Http\Resources;

use Glueful\Http\Resources\JsonResource;
use Glueful\Http\Resources\PaginatedResourceResponse;
use Glueful\Http\Resources\ResourceCollection;
use Glueful\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for pagination features
 */
class PaginationTest extends TestCase
{
    public function testPaginatedResourceResponseFromQueryResult(): void
    {
        $queryResult = [
            'data' => [
                ['id' => 1, 'name' => 'John'],
                ['id' => 2, 'name' => 'Jane'],
            ],
            'current_page' => 2,
            'per_page' => 2,
            'total' => 10,
            'last_page' => 5,
            'from' => 3,
            'to' => 4,
        ];

        $paginated = PaginatedResourceResponse::fromQueryResult($queryResult);
        $meta = $paginated->getMeta();

        $this->assertEquals(2, $meta['current_page']);
        $this->assertEquals(2, $meta['per_page']);
        $this->assertEquals(10, $meta['total']);
        $this->assertEquals(5, $meta['total_pages']);
        $this->assertTrue($meta['has_next_page']);
        $this->assertTrue($meta['has_previous_page']);
    }

    public function testPaginatedResourceResponseFromOrmResult(): void
    {
        $ormResult = [
            'data' => [
                ['id' => 1, 'name' => 'John'],
            ],
            'meta' => [
                'current_page' => 1,
                'per_page' => 15,
                'total' => 30,
                'total_pages' => 2,
            ],
        ];

        $paginated = PaginatedResourceResponse::fromOrmResult($ormResult);
        $meta = $paginated->getMeta();

        $this->assertEquals(1, $meta['current_page']);
        $this->assertEquals(15, $meta['per_page']);
        $this->assertEquals(30, $meta['total']);
        $this->assertEquals(2, $meta['total_pages']);
        $this->assertTrue($meta['has_next_page']);
        $this->assertFalse($meta['has_previous_page']);
    }

    public function testPaginatedResourceResponseLinks(): void
    {
        $queryResult = [
            'data' => [['id' => 1]],
            'current_page' => 3,
            'per_page' => 10,
            'total' => 50,
            'last_page' => 5,
        ];

        $paginated = PaginatedResourceResponse::fromQueryResult($queryResult)
            ->withBaseUrl('/api/users');

        $links = $paginated->getLinks();

        $this->assertEquals('/api/users?page=1&per_page=10', $links['first']);
        $this->assertEquals('/api/users?page=5&per_page=10', $links['last']);
        $this->assertEquals('/api/users?page=2&per_page=10', $links['prev']);
        $this->assertEquals('/api/users?page=4&per_page=10', $links['next']);
    }

    public function testPaginatedResourceResponseLinksWithQueryParams(): void
    {
        $queryResult = [
            'data' => [['id' => 1]],
            'current_page' => 1,
            'per_page' => 15,
            'total' => 30,
            'last_page' => 2,
        ];

        $paginated = PaginatedResourceResponse::fromQueryResult($queryResult)
            ->withBaseUrl('/api/users')
            ->withQueryParams(['status' => 'active', 'sort' => 'name']);

        $links = $paginated->getLinks();

        $this->assertStringContainsString('status=active', $links['first']);
        $this->assertStringContainsString('sort=name', $links['first']);
    }

    public function testPaginatedResourceResponseWithoutLinks(): void
    {
        $queryResult = [
            'data' => [['id' => 1]],
            'current_page' => 1,
            'per_page' => 15,
            'total' => 15,
            'last_page' => 1,
        ];

        $paginated = PaginatedResourceResponse::fromQueryResult($queryResult)
            ->withBaseUrl('/api/users')
            ->withoutLinks();

        $links = $paginated->getLinks();

        $this->assertEquals([], $links);
    }

    public function testPaginatedResourceResponseToResponse(): void
    {
        $queryResult = [
            'data' => [
                ['id' => 1, 'name' => 'John'],
            ],
            'current_page' => 1,
            'per_page' => 15,
            'total' => 1,
            'last_page' => 1,
        ];

        $paginated = PaginatedResourceResponse::fromQueryResult($queryResult);
        $response = $paginated->toResponse();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertTrue($content['success']);
        $this->assertArrayHasKey('data', $content);
        $this->assertArrayHasKey('current_page', $content);
        $this->assertArrayHasKey('total', $content);
    }

    public function testPaginatedResourceResponseWithAdditional(): void
    {
        $queryResult = [
            'data' => [['id' => 1]],
            'current_page' => 1,
            'per_page' => 15,
            'total' => 1,
            'last_page' => 1,
        ];

        $paginated = PaginatedResourceResponse::fromQueryResult($queryResult)
            ->additional(['meta' => ['version' => '1.0']]);

        $response = $paginated->toResponse();
        $content = json_decode($response->getContent(), true);

        $this->assertEquals(['version' => '1.0'], $content['meta']);
    }

    public function testPaginatedResourceResponseWithResourceClass(): void
    {
        $queryResult = [
            'data' => [
                ['id' => 1, 'name' => 'John'],
            ],
            'current_page' => 1,
            'per_page' => 15,
            'total' => 1,
            'last_page' => 1,
        ];

        $paginated = PaginatedResourceResponse::fromQueryResult(
            $queryResult,
            TestPaginatedUserResource::class
        );

        $array = $paginated->toArray();

        $this->assertTrue($array['data'][0]['transformed']);
    }

    public function testResourceCollectionWithPaginationFromQueryBuilder(): void
    {
        $queryResult = [
            'data' => [['id' => 1, 'name' => 'John']],
            'current_page' => 2,
            'per_page' => 10,
            'total' => 50,
            'last_page' => 5,
            'has_more' => true,
            'from' => 11,
            'to' => 20,
        ];

        $collection = ResourceCollection::make($queryResult['data'])
            ->withPaginationFrom($queryResult);

        $response = $collection->toResponse();
        $content = json_decode($response->getContent(), true);

        $this->assertEquals(2, $content['current_page']);
        $this->assertEquals(10, $content['per_page']);
        $this->assertEquals(50, $content['total']);
        $this->assertEquals(5, $content['total_pages']);
        $this->assertTrue($content['has_next_page']);
        $this->assertTrue($content['has_previous_page']);
    }

    public function testResourceCollectionWithPaginationFromOrm(): void
    {
        $ormResult = [
            'data' => [['id' => 1, 'name' => 'John']],
            'meta' => [
                'current_page' => 1,
                'per_page' => 25,
                'total' => 100,
                'total_pages' => 4,
            ],
        ];

        $collection = ResourceCollection::make($ormResult['data'])
            ->withPaginationFrom($ormResult);

        $response = $collection->toResponse();
        $content = json_decode($response->getContent(), true);

        $this->assertEquals(1, $content['current_page']);
        $this->assertEquals(25, $content['per_page']);
        $this->assertEquals(100, $content['total']);
        $this->assertEquals(4, $content['total_pages']);
    }

    public function testResourceCollectionWithLinks(): void
    {
        $items = [['id' => 1, 'name' => 'John']];
        $pagination = [
            'current_page' => 2,
            'per_page' => 10,
            'total' => 50,
            'total_pages' => 5,
        ];

        $collection = ResourceCollection::make($items)
            ->withPagination($pagination)
            ->withLinks('/api/users');

        $response = $collection->toResponse();
        $content = json_decode($response->getContent(), true);

        $this->assertArrayHasKey('links', $content);
        $this->assertEquals('/api/users?page=1&per_page=10', $content['links']['first']);
        $this->assertEquals('/api/users?page=5&per_page=10', $content['links']['last']);
        $this->assertEquals('/api/users?page=1&per_page=10', $content['links']['prev']);
        $this->assertEquals('/api/users?page=3&per_page=10', $content['links']['next']);
    }

    public function testResourceCollectionLinksWithQueryParams(): void
    {
        $items = [['id' => 1]];
        $pagination = [
            'current_page' => 1,
            'per_page' => 15,
            'total' => 30,
            'total_pages' => 2,
        ];

        $collection = ResourceCollection::make($items)
            ->withPagination($pagination)
            ->withLinks('/api/users', ['filter' => 'active']);

        $response = $collection->toResponse();
        $content = json_decode($response->getContent(), true);

        $this->assertStringContainsString('filter=active', $content['links']['first']);
    }

    public function testFirstPageHasNoPrevLink(): void
    {
        $queryResult = [
            'data' => [['id' => 1]],
            'current_page' => 1,
            'per_page' => 15,
            'total' => 30,
            'last_page' => 2,
        ];

        $paginated = PaginatedResourceResponse::fromQueryResult($queryResult)
            ->withBaseUrl('/api/users');

        $links = $paginated->getLinks();

        $this->assertNull($links['prev']);
        $this->assertNotNull($links['next']);
    }

    public function testLastPageHasNoNextLink(): void
    {
        $queryResult = [
            'data' => [['id' => 1]],
            'current_page' => 3,
            'per_page' => 10,
            'total' => 30,
            'last_page' => 3,
        ];

        $paginated = PaginatedResourceResponse::fromQueryResult($queryResult)
            ->withBaseUrl('/api/users');

        $links = $paginated->getLinks();

        $this->assertNotNull($links['prev']);
        $this->assertNull($links['next']);
    }

    public function testManualPaginationConfiguration(): void
    {
        $items = [['id' => 1], ['id' => 2]];

        $paginated = PaginatedResourceResponse::make($items)
            ->setPage(2)
            ->setPerPage(2)
            ->setTotal(10)
            ->withBaseUrl('/api/items');

        $meta = $paginated->getMeta();

        $this->assertEquals(2, $meta['current_page']);
        $this->assertEquals(2, $meta['per_page']);
        $this->assertEquals(10, $meta['total']);
        $this->assertEquals(5, $meta['total_pages']);
        $this->assertEquals(3, $meta['from']);
        $this->assertEquals(4, $meta['to']);
    }
}

/**
 * Test resource for pagination tests
 */
// phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
class TestPaginatedUserResource extends JsonResource
{
    public function toArray(): array
    {
        return [
            'id' => $this->resource['id'],
            'name' => $this->resource['name'],
            'transformed' => true,
        ];
    }
}

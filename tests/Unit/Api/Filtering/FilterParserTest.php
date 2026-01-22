<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Api\Filtering;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Glueful\Api\Filtering\FilterParser;
use Glueful\Api\Filtering\Exceptions\InvalidFilterException;
use Symfony\Component\HttpFoundation\Request;

class FilterParserTest extends TestCase
{
    private FilterParser $parser;

    protected function setUp(): void
    {
        $this->parser = new FilterParser();
    }

    #[Test]
    public function parsesSimpleEqualityFilter(): void
    {
        $request = Request::create('/users', 'GET', [
            'filter' => ['status' => 'active'],
        ]);

        $filters = $this->parser->parseFilters($request);

        $this->assertCount(1, $filters);
        $this->assertEquals('status', $filters[0]->field);
        $this->assertEquals('eq', $filters[0]->operator);
        $this->assertEquals('active', $filters[0]->value);
    }

    #[Test]
    public function parsesOperatorFilter(): void
    {
        $request = Request::create('/users', 'GET', [
            'filter' => ['age' => ['gte' => '18']],
        ]);

        $filters = $this->parser->parseFilters($request);

        $this->assertCount(1, $filters);
        $this->assertEquals('age', $filters[0]->field);
        $this->assertEquals('gte', $filters[0]->operator);
        $this->assertEquals('18', $filters[0]->value);
    }

    #[Test]
    public function parsesMultipleFilters(): void
    {
        $request = Request::create('/users', 'GET', [
            'filter' => [
                'status' => 'active',
                'role' => 'admin',
            ],
        ]);

        $filters = $this->parser->parseFilters($request);

        $this->assertCount(2, $filters);
    }

    #[Test]
    public function parsesMultipleOperatorsOnSameField(): void
    {
        $request = Request::create('/users', 'GET', [
            'filter' => [
                'age' => [
                    'gte' => '18',
                    'lte' => '65',
                ],
            ],
        ]);

        $filters = $this->parser->parseFilters($request);

        $this->assertCount(2, $filters);
        $this->assertEquals('gte', $filters[0]->operator);
        $this->assertEquals('lte', $filters[1]->operator);
    }

    #[Test]
    public function returnsEmptyArrayWhenNoFilters(): void
    {
        $request = Request::create('/users', 'GET');

        $filters = $this->parser->parseFilters($request);

        $this->assertCount(0, $filters);
    }

    #[Test]
    public function throwsExceptionWhenMaxFiltersExceeded(): void
    {
        $parser = new FilterParser(3, 2);

        $request = Request::create('/users', 'GET', [
            'filter' => [
                'status' => 'active',
                'role' => 'admin',
                'age' => '25',
            ],
        ]);

        $this->expectException(InvalidFilterException::class);
        $parser->parseFilters($request);
    }

    #[Test]
    public function throwsExceptionWhenMaxDepthExceeded(): void
    {
        $parser = new FilterParser(1, 20);

        $request = Request::create('/users', 'GET', [
            'filter' => [
                'user' => [
                    'profile' => [
                        'name' => 'John',
                    ],
                ],
            ],
        ]);

        $this->expectException(InvalidFilterException::class);
        $parser->parseFilters($request);
    }

    #[Test]
    public function parsesSortString(): void
    {
        $request = Request::create('/users', 'GET', [
            'sort' => '-created_at,name',
        ]);

        $sorts = $this->parser->parseSorts($request);

        $this->assertCount(2, $sorts);
        $this->assertEquals('created_at', $sorts[0]->field);
        $this->assertEquals('DESC', $sorts[0]->direction);
        $this->assertEquals('name', $sorts[1]->field);
        $this->assertEquals('ASC', $sorts[1]->direction);
    }

    #[Test]
    public function returnsEmptyArrayWhenNoSorts(): void
    {
        $request = Request::create('/users', 'GET');

        $sorts = $this->parser->parseSorts($request);

        $this->assertCount(0, $sorts);
    }

    #[Test]
    public function parsesSearchQuery(): void
    {
        $request = Request::create('/users', 'GET', [
            'search' => 'john doe',
        ]);

        $search = $this->parser->parseSearch($request);

        $this->assertEquals('john doe', $search);
    }

    #[Test]
    public function returnsNullForEmptySearch(): void
    {
        $request = Request::create('/users', 'GET', [
            'search' => '  ',
        ]);

        $search = $this->parser->parseSearch($request);

        $this->assertNull($search);
    }

    #[Test]
    public function parsesSearchFields(): void
    {
        $request = Request::create('/users', 'GET', [
            'search_fields' => 'name,email,bio',
        ]);

        $fields = $this->parser->parseSearchFields($request);

        $this->assertEquals(['name', 'email', 'bio'], $fields);
    }

    #[Test]
    public function returnsNullWhenNoSearchFields(): void
    {
        $request = Request::create('/users', 'GET');

        $fields = $this->parser->parseSearchFields($request);

        $this->assertNull($fields);
    }

    #[Test]
    public function parseSortStringTrimsWhitespace(): void
    {
        $sorts = $this->parser->parseSortString('  -created_at  ,  name  ');

        $this->assertCount(2, $sorts);
        $this->assertEquals('created_at', $sorts[0]->field);
        $this->assertEquals('name', $sorts[1]->field);
    }

    #[Test]
    public function parseSortStringIgnoresEmptyFields(): void
    {
        $sorts = $this->parser->parseSortString('name,,created_at');

        $this->assertCount(2, $sorts);
    }
}

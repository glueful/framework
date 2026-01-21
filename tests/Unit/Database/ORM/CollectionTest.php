<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\ORM;

use Glueful\Database\ORM\Collection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the ORM Collection class
 */
class CollectionTest extends TestCase
{
    public function testMakeCreatesCollection(): void
    {
        $collection = Collection::make([1, 2, 3]);

        $this->assertInstanceOf(Collection::class, $collection);
        $this->assertSame([1, 2, 3], $collection->all());
    }

    public function testAllReturnsItems(): void
    {
        $items = ['a' => 1, 'b' => 2];
        $collection = new Collection($items);

        $this->assertSame($items, $collection->all());
    }

    public function testFirstReturnsFirstItem(): void
    {
        $collection = new Collection([1, 2, 3]);

        $this->assertSame(1, $collection->first());
    }

    public function testFirstReturnsDefaultWhenEmpty(): void
    {
        $collection = new Collection([]);

        $this->assertNull($collection->first());
        $this->assertSame('default', $collection->first(null, 'default'));
    }

    public function testFirstWithCallback(): void
    {
        $collection = new Collection([1, 2, 3, 4, 5]);

        $result = $collection->first(fn ($item) => $item > 3);

        $this->assertSame(4, $result);
    }

    public function testLastReturnsLastItem(): void
    {
        $collection = new Collection([1, 2, 3]);

        $this->assertSame(3, $collection->last());
    }

    public function testGetReturnsItemByKey(): void
    {
        $collection = new Collection(['a' => 1, 'b' => 2]);

        $this->assertSame(1, $collection->get('a'));
        $this->assertNull($collection->get('c'));
        $this->assertSame('default', $collection->get('c', 'default'));
    }

    public function testHasChecksForKey(): void
    {
        $collection = new Collection(['a' => 1, 'b' => null]);

        $this->assertTrue($collection->has('a'));
        $this->assertTrue($collection->has('b'));
        $this->assertFalse($collection->has('c'));
    }

    public function testIsEmptyAndIsNotEmpty(): void
    {
        $empty = new Collection([]);
        $notEmpty = new Collection([1]);

        $this->assertTrue($empty->isEmpty());
        $this->assertFalse($empty->isNotEmpty());
        $this->assertFalse($notEmpty->isEmpty());
        $this->assertTrue($notEmpty->isNotEmpty());
    }

    public function testCountReturnsItemCount(): void
    {
        $collection = new Collection([1, 2, 3]);

        $this->assertSame(3, $collection->count());
        $this->assertCount(3, $collection);
    }

    public function testMapTransformsItems(): void
    {
        $collection = new Collection([1, 2, 3]);

        $result = $collection->map(fn ($item) => $item * 2);

        $this->assertSame([2, 4, 6], $result->all());
    }

    public function testFilterRemovesItems(): void
    {
        $collection = new Collection([1, 2, 3, 4, 5]);

        $result = $collection->filter(fn ($item) => $item > 2);

        $this->assertSame([2 => 3, 3 => 4, 4 => 5], $result->all());
    }

    public function testWhereFiltersItems(): void
    {
        $collection = new Collection([
            ['name' => 'John', 'age' => 30],
            ['name' => 'Jane', 'age' => 25],
            ['name' => 'Bob', 'age' => 30],
        ]);

        $result = $collection->where('age', 30);

        $this->assertCount(2, $result);
    }

    public function testWhereWithOperator(): void
    {
        $collection = new Collection([
            ['value' => 10],
            ['value' => 20],
            ['value' => 30],
        ]);

        $result = $collection->where('value', '>', 15);

        $this->assertCount(2, $result);
    }

    public function testWhereIn(): void
    {
        $collection = new Collection([
            ['id' => 1],
            ['id' => 2],
            ['id' => 3],
        ]);

        $result = $collection->whereIn('id', [1, 3]);

        $this->assertCount(2, $result);
    }

    public function testPluckExtractsValues(): void
    {
        $collection = new Collection([
            ['id' => 1, 'name' => 'John'],
            ['id' => 2, 'name' => 'Jane'],
        ]);

        $result = $collection->pluck('name');

        $this->assertSame(['John', 'Jane'], $result->all());
    }

    public function testPluckWithKey(): void
    {
        $collection = new Collection([
            ['id' => 1, 'name' => 'John'],
            ['id' => 2, 'name' => 'Jane'],
        ]);

        $result = $collection->pluck('name', 'id');

        $this->assertSame([1 => 'John', 2 => 'Jane'], $result->all());
    }

    public function testKeyByReindexesCollection(): void
    {
        $collection = new Collection([
            ['id' => 1, 'name' => 'John'],
            ['id' => 2, 'name' => 'Jane'],
        ]);

        $result = $collection->keyBy('id');

        $this->assertArrayHasKey(1, $result->all());
        $this->assertArrayHasKey(2, $result->all());
    }

    public function testGroupByGroupsItems(): void
    {
        $collection = new Collection([
            ['type' => 'a', 'value' => 1],
            ['type' => 'b', 'value' => 2],
            ['type' => 'a', 'value' => 3],
        ]);

        $result = $collection->groupBy('type');

        $this->assertArrayHasKey('a', $result->all());
        $this->assertArrayHasKey('b', $result->all());
        $this->assertCount(2, $result->get('a'));
        $this->assertCount(1, $result->get('b'));
    }

    public function testSortBySortsCollection(): void
    {
        $collection = new Collection([
            ['name' => 'Charlie'],
            ['name' => 'Alice'],
            ['name' => 'Bob'],
        ]);

        $result = $collection->sortBy('name');

        $names = $result->pluck('name')->all();
        $this->assertSame(['Alice', 'Bob', 'Charlie'], $names);
    }

    public function testSortByDescSortsDescending(): void
    {
        $collection = new Collection([1, 3, 2]);

        $result = $collection->sortByDesc(fn ($item) => $item);

        $this->assertSame([3, 2, 1], $result->values()->all());
    }

    public function testReverseReversesOrder(): void
    {
        $collection = new Collection([1, 2, 3]);

        $result = $collection->reverse();

        $this->assertSame([2 => 3, 1 => 2, 0 => 1], $result->all());
    }

    public function testValuesResetsKeys(): void
    {
        $collection = new Collection(['a' => 1, 'b' => 2]);

        $result = $collection->values();

        $this->assertSame([1, 2], $result->all());
    }

    public function testKeysReturnsKeys(): void
    {
        $collection = new Collection(['a' => 1, 'b' => 2]);

        $result = $collection->keys();

        $this->assertSame(['a', 'b'], $result->all());
    }

    public function testSliceReturnsSubset(): void
    {
        $collection = new Collection([1, 2, 3, 4, 5]);

        $result = $collection->slice(2, 2);

        $this->assertSame([2 => 3, 3 => 4], $result->all());
    }

    public function testTakeLimitsItems(): void
    {
        $collection = new Collection([1, 2, 3, 4, 5]);

        $result = $collection->take(3);

        $this->assertSame([1, 2, 3], $result->all());
    }

    public function testEachIteratesItems(): void
    {
        $collection = new Collection([1, 2, 3]);
        $sum = 0;

        $collection->each(function ($item) use (&$sum) {
            $sum += $item;
        });

        $this->assertSame(6, $sum);
    }

    public function testReduceAccumulatesValue(): void
    {
        $collection = new Collection([1, 2, 3, 4]);

        $result = $collection->reduce(fn ($carry, $item) => $carry + $item, 0);

        $this->assertSame(10, $result);
    }

    public function testPushAddsItems(): void
    {
        $collection = new Collection([1, 2]);

        $collection->push(3, 4);

        $this->assertSame([1, 2, 3, 4], $collection->all());
    }

    public function testPutSetsItem(): void
    {
        $collection = new Collection(['a' => 1]);

        $collection->put('b', 2);

        $this->assertSame(['a' => 1, 'b' => 2], $collection->all());
    }

    public function testMergeCombinesCollections(): void
    {
        $collection1 = new Collection([1, 2]);
        $collection2 = new Collection([3, 4]);

        $result = $collection1->merge($collection2);

        $this->assertSame([1, 2, 3, 4], $result->all());
    }

    public function testContainsChecksForItem(): void
    {
        $collection = new Collection([1, 2, 3]);

        $this->assertTrue($collection->contains(2));
        $this->assertFalse($collection->contains(5));
    }

    public function testContainsWithCallback(): void
    {
        $collection = new Collection([1, 2, 3]);

        $this->assertTrue($collection->contains(fn ($item) => $item > 2));
        $this->assertFalse($collection->contains(fn ($item) => $item > 10));
    }

    public function testSumCalculatesTotal(): void
    {
        $collection = new Collection([1, 2, 3, 4]);

        $this->assertSame(10, $collection->sum());
    }

    public function testSumWithKey(): void
    {
        $collection = new Collection([
            ['amount' => 10],
            ['amount' => 20],
            ['amount' => 30],
        ]);

        $this->assertSame(60, $collection->sum('amount'));
    }

    public function testAvgCalculatesAverage(): void
    {
        $collection = new Collection([10, 20, 30]);

        $this->assertEqualsWithDelta(20.0, $collection->avg(), 0.001);
    }

    public function testMaxReturnsMaximum(): void
    {
        $collection = new Collection([1, 5, 3]);

        $this->assertSame(5, $collection->max());
    }

    public function testMinReturnsMinimum(): void
    {
        $collection = new Collection([5, 1, 3]);

        $this->assertSame(1, $collection->min());
    }

    public function testUniqueRemovesDuplicates(): void
    {
        $collection = new Collection([1, 2, 2, 3, 3, 3]);

        $result = $collection->unique();

        $this->assertSame([1, 2, 3], $result->values()->all());
    }

    public function testToArrayConvertsItems(): void
    {
        $collection = new Collection([1, 2, 3]);

        $this->assertSame([1, 2, 3], $collection->toArray());
    }

    public function testToJsonConvertsToJson(): void
    {
        $collection = new Collection(['a' => 1, 'b' => 2]);

        $this->assertSame('{"a":1,"b":2}', $collection->toJson());
    }

    public function testIterator(): void
    {
        $collection = new Collection([1, 2, 3]);
        $items = [];

        foreach ($collection as $item) {
            $items[] = $item;
        }

        $this->assertSame([1, 2, 3], $items);
    }

    public function testArrayAccess(): void
    {
        $collection = new Collection(['a' => 1]);

        $this->assertTrue(isset($collection['a']));
        $this->assertSame(1, $collection['a']);

        $collection['b'] = 2;
        $this->assertSame(2, $collection['b']);

        unset($collection['a']);
        $this->assertFalse(isset($collection['a']));
    }
}

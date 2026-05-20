<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support\Documentation;

use Glueful\Support\Documentation\ExampleDeriver;
use PHPUnit\Framework\TestCase;

final class ExampleDeriverTest extends TestCase
{
    public function testDerivesExampleFromValidatorRules(): void
    {
        $deriver = new ExampleDeriver();
        $example = $deriver->fromValidationRules([
            'email' => 'required|string|email',
            'age' => 'required|integer|min:18|max:120',
            'name' => 'required|string|min:1|max:255',
            'is_active' => 'boolean',
        ]);

        self::assertSame('user@example.com', $example['email']);
        self::assertIsInt($example['age']);
        self::assertGreaterThanOrEqual(18, $example['age']);
        self::assertLessThanOrEqual(120, $example['age']);
        self::assertIsString($example['name']);
        self::assertIsBool($example['is_active']);
    }

    public function testRecognizesUuidUrlDatetimeAndDate(): void
    {
        $deriver = new ExampleDeriver();
        $example = $deriver->fromValidationRules([
            'id' => 'required|uuid',
            'website' => 'url',
            'born_on' => 'date',
            'last_seen' => 'datetime',
        ]);

        self::assertSame('550e8400-e29b-41d4-a716-446655440000', $example['id']);
        self::assertSame('https://example.com', $example['website']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $example['born_on']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $example['last_seen']);
    }

    public function testFieldNameHeuristicsProduceReadableStrings(): void
    {
        $deriver = new ExampleDeriver();
        $example = $deriver->fromValidationRules([
            'first_name' => 'string',
            'last_name' => 'string',
            'title' => 'string',
            'slug' => 'string',
            'description' => 'string',
            'comment' => 'string',
        ]);

        self::assertSame('Jane', $example['first_name']);
        self::assertSame('Doe', $example['last_name']);
        self::assertSame('Example title', $example['title']);
        self::assertSame('example-slug', $example['slug']);
        self::assertSame('A short description.', $example['description']);
        self::assertSame('example', $example['comment']);
    }

    public function testDerivesExampleFromSchemaProperties(): void
    {
        $deriver = new ExampleDeriver();
        $example = $deriver->fromSchemaProperties([
            'name' => ['type' => 'string'],
            'age' => ['type' => 'integer'],
            'is_active' => ['type' => 'boolean'],
            'role' => ['type' => 'string', 'enum' => ['admin', 'user', 'guest']],
            'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
        ]);

        self::assertIsString($example['name']);
        self::assertIsInt($example['age']);
        self::assertTrue($example['is_active']);
        self::assertSame('admin', $example['role']);
        self::assertIsArray($example['tags']);
        self::assertCount(1, $example['tags']);
        self::assertIsString($example['tags'][0]);
    }
}

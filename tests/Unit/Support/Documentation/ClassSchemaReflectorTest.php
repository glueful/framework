<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support\Documentation;

use Glueful\Support\Documentation\ClassSchemaReflector;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Glueful\Support\Documentation\ClassSchemaReflector
 */
final class ClassSchemaReflectorTest extends TestCase
{
    public function testScalarPropertiesMapToOpenApiTypes(): void
    {
        $schema = ClassSchemaReflector::toSchema(ScalarDto::class);

        self::assertSame('object', $schema['type']);
        $props = $schema['properties'];
        self::assertSame(['type' => 'string'], $this->stripDescription($props['name']));
        self::assertSame('integer', $props['age']['type']);
        self::assertSame('number', $props['score']['type']);
        self::assertSame('boolean', $props['active']['type']);
    }

    public function testStaticPropertiesAreSkipped(): void
    {
        $props = ClassSchemaReflector::toSchema(ScalarDto::class)['properties'];

        self::assertArrayNotHasKey('shared', $props);
    }

    public function testNullablePropertyEmitsNullableFlag(): void
    {
        $props = ClassSchemaReflector::toSchema(NullableDto::class)['properties'];

        self::assertTrue($props['nickname']['nullable']);
        self::assertSame('string', $props['nickname']['type']);
        // Non-nullable sibling carries no nullable flag.
        self::assertArrayNotHasKey('nullable', $props['id']);
    }

    public function testNestedDtoRecurses(): void
    {
        $props = ClassSchemaReflector::toSchema(ParentDto::class)['properties'];

        $child = $props['child'];
        self::assertSame('object', $child['type']);
        self::assertSame('string', $child['properties']['label']['type']);
    }

    public function testArrayOfDtoViaDocblock(): void
    {
        $props = ClassSchemaReflector::toSchema(ParentDto::class)['properties'];

        $children = $props['children'];
        self::assertSame('array', $children['type']);
        self::assertSame('object', $children['items']['type']);
        self::assertSame('string', $children['items']['properties']['label']['type']);
    }

    public function testArrayWithoutDocblockHasAnyItems(): void
    {
        $props = ClassSchemaReflector::toSchema(ParentDto::class)['properties'];

        self::assertSame('array', $props['tags']['type']);
        // No resolvable item type -> any (empty schema object).
        self::assertEquals(new \stdClass(), $props['tags']['items']);
    }

    public function testArrayOfScalarViaDocblock(): void
    {
        $props = ClassSchemaReflector::toSchema(ParentDto::class)['properties'];

        self::assertSame('array', $props['scores']['type']);
        self::assertSame(['type' => 'integer'], $props['scores']['items']);
    }

    public function testBackedStringEnumMapsToEnumValues(): void
    {
        $props = ClassSchemaReflector::toSchema(EnumDto::class)['properties'];

        self::assertSame('string', $props['status']['type']);
        self::assertSame(['active', 'inactive'], $props['status']['enum']);
    }

    public function testBackedIntEnumMapsToIntegerEnum(): void
    {
        $props = ClassSchemaReflector::toSchema(EnumDto::class)['properties'];

        self::assertSame('integer', $props['priority']['type']);
        self::assertSame([1, 2], $props['priority']['enum']);
    }

    public function testPureEnumMapsToCaseNames(): void
    {
        $props = ClassSchemaReflector::toSchema(EnumDto::class)['properties'];

        self::assertSame('string', $props['shape']['type']);
        self::assertSame(['Circle', 'Square'], $props['shape']['enum']);
    }

    public function testDateTimePropertyMapsToDateTimeFormat(): void
    {
        $props = ClassSchemaReflector::toSchema(DateDto::class)['properties'];

        self::assertSame('string', $props['createdAt']['type']);
        self::assertSame('date-time', $props['createdAt']['format']);
    }

    public function testSelfReferentialDtoTerminates(): void
    {
        // Must not infinite-loop; the recursive property bottoms out as object.
        $schema = ClassSchemaReflector::toSchema(NodeDto::class);

        self::assertSame('object', $schema['type']);
        self::assertSame('string', $schema['properties']['value']['type']);
        // The self-referential `next` eventually collapses to a bare object.
        self::assertSame('object', $schema['properties']['next']['type']);
    }

    public function testMissingClassReturnsObjectWithoutThrowing(): void
    {
        /** @var class-string $missing */
        $missing = 'Glueful\\Tests\\Does\\Not\\Exist';

        self::assertSame(['type' => 'object'], ClassSchemaReflector::toSchema($missing));
    }

    public function testDescriptionDerivedFromDocblock(): void
    {
        $props = ClassSchemaReflector::toSchema(DescribedDto::class)['properties'];

        self::assertSame('The display title', $props['title']['description']);
    }

    /**
     * @param  array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function stripDescription(array $schema): array
    {
        unset($schema['description']);
        return $schema;
    }
}

// --- Fixtures -------------------------------------------------------------

final class ScalarDto
{
    public static string $shared = 'x';
    public string $name = '';
    public int $age = 0;
    public float $score = 0.0;
    public bool $active = false;
}

final class NullableDto
{
    public int $id = 0;
    public ?string $nickname = null;
}

final class ChildDto
{
    public string $label = '';
}

final class ParentDto
{
    public ChildDto $child;

    /** @var ChildDto[] */
    public array $children = [];

    /** @var int[] */
    public array $scores = [];

    public array $tags = [];
}

enum DtoStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}

enum DtoPriority: int
{
    case Low = 1;
    case High = 2;
}

enum DtoShape
{
    case Circle;
    case Square;
}

final class EnumDto
{
    public DtoStatus $status = DtoStatus::Active;
    public DtoPriority $priority = DtoPriority::Low;
    public DtoShape $shape = DtoShape::Circle;
}

final class DateDto
{
    public \DateTimeInterface $createdAt;
}

final class NodeDto
{
    public string $value = '';
    public ?NodeDto $next = null;
}

final class DescribedDto
{
    /** @var string The display title */
    public string $title = '';
}

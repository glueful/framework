<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support\Documentation;

use Glueful\Support\Documentation\ClassSchemaReflector;
use Glueful\Tests\Support\Fixtures\RequestData\NestedArrayFixture; // slug + #[ArrayOf(FieldDefFixture)] schema
use Glueful\Tests\Support\Fixtures\RequestData\SourcedFixture;     // #[FromRoute] uuid + #[FromQuery] status + title
use PHPUnit\Framework\TestCase;

final class ClassSchemaReflectorRequestModeTest extends TestCase
{
    public function testRequestModeUsesArrayOfForItems(): void
    {
        $schema = ClassSchemaReflector::toSchema(NestedArrayFixture::class, requestMode: true);
        $items = $schema['properties']['schema']['items'];
        self::assertSame('object', $items['type']);
        self::assertArrayHasKey('name', $items['properties']);
    }

    public function testRequestModeExcludesSourceAttributedFields(): void
    {
        $schema = ClassSchemaReflector::toSchema(SourcedFixture::class, requestMode: true);
        self::assertArrayNotHasKey('uuid', $schema['properties']);   // #[FromRoute]
        self::assertArrayNotHasKey('status', $schema['properties']); // #[FromQuery]
        self::assertArrayHasKey('title', $schema['properties']);     // body
    }
}

<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support\Documentation;

use Glueful\Support\Documentation\PaginationSchemaBuilder;
use PHPUnit\Framework\TestCase;

final class PaginationSchemaBuilderTest extends TestCase
{
    public function testBuildsEnvelopeWithDataItemRef(): void
    {
        $builder = new PaginationSchemaBuilder();
        $schema = $builder->envelopeFor('#/components/schemas/User');

        self::assertSame('object', $schema['type']);
        self::assertSame(
            '#/components/schemas/User',
            $schema['properties']['data']['items']['$ref'],
        );
        self::assertSame(['data', 'current_page', 'per_page', 'total', 'total_pages'], $schema['required']);
    }

    public function testProducesPaginationMetaAndLinksComponents(): void
    {
        $builder = new PaginationSchemaBuilder();
        $components = $builder->components();

        self::assertArrayHasKey('PaginationMeta', $components);
        self::assertArrayHasKey('PaginationLinks', $components);
        self::assertSame('integer', $components['PaginationMeta']['properties']['current_page']['type']);
        self::assertSame('string', $components['PaginationLinks']['properties']['first']['type']);
    }
}

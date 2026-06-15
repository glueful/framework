<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Validation\Attributes;

use Glueful\Validation\Attributes\FromQuery;
use Glueful\Validation\Attributes\FromRoute;
use PHPUnit\Framework\TestCase;

final class SourceAttributesTest extends TestCase
{
    public function testAttributesAreReadableFromAParameter(): void
    {
        $fn = function (#[FromRoute] string $a, #[FromQuery] string $b, string $c): void {
        };
        $params = (new \ReflectionFunction($fn))->getParameters();

        self::assertCount(1, $params[0]->getAttributes(FromRoute::class));
        self::assertCount(1, $params[1]->getAttributes(FromQuery::class));
        self::assertCount(0, $params[2]->getAttributes(FromRoute::class));
        self::assertCount(0, $params[2]->getAttributes(FromQuery::class));
    }
}

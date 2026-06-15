<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Validation\Attributes;

use Glueful\Validation\Attributes\ArrayOf;
use Glueful\Validation\Contracts\RequestData;
use PHPUnit\Framework\TestCase;

final class ArrayOfNestedFixture implements RequestData
{
    public function __construct(public string $name = '')
    {
    }
}

final class ArrayOfPlainFixture
{
    public function __construct(public string $value = '')
    {
    }
}

final class ArrayOfTest extends TestCase
{
    public function testCanonicalizesScalarSpellings(): void
    {
        self::assertSame('int', (new ArrayOf('integer'))->type);
        self::assertSame('int', (new ArrayOf('int'))->type);
        self::assertSame('float', (new ArrayOf('number'))->type);
        self::assertSame('bool', (new ArrayOf('boolean'))->type);
        self::assertSame('string', (new ArrayOf('string'))->type);
    }

    public function testScalarDetection(): void
    {
        self::assertTrue((new ArrayOf('string'))->isScalar());
        self::assertNull((new ArrayOf('string'))->dtoClass());
        self::assertFalse((new ArrayOf(ArrayOfNestedFixture::class))->isScalar());
        self::assertSame(ArrayOfNestedFixture::class, (new ArrayOf(ArrayOfNestedFixture::class))->dtoClass());
    }

    public function testRejectsUnknownScalarName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ArrayOf('text');
    }

    public function testAcceptsClassNotImplementingRequestData(): void
    {
        // Any existing class is valid — ArrayOf is usable on ResponseData DTOs too.
        $arrayOf = new ArrayOf(ArrayOfPlainFixture::class);
        self::assertSame(ArrayOfPlainFixture::class, $arrayOf->dtoClass());
        self::assertFalse($arrayOf->isScalar());
    }

    public function testRejectsNonExistentClass(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ArrayOf('Glueful\\Nope\\DoesNotExist');
    }
}

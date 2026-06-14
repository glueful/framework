<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Routing;

use Glueful\Routing\Attributes\ResponseStatus;
use PHPUnit\Framework\TestCase;

final class ResponseStatusAttributeTest extends TestCase
{
    public function testAcceptsSuccessStatus(): void
    {
        $attribute = new ResponseStatus(201);
        self::assertSame(201, $attribute->status);
    }

    public function testRejectsClientErrorStatus(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ResponseStatus(404);
    }

    public function testRejectsOutOfRangeStatus(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ResponseStatus(999);
    }

    public function testAcceptsLowerAndUpperBoundary(): void
    {
        self::assertSame(200, (new ResponseStatus(200))->status);
        self::assertSame(299, (new ResponseStatus(299))->status);
    }

    public function testRejectsJustBelowLowerBoundary(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ResponseStatus(199);
    }

    public function testRejectsJustAboveUpperBoundary(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ResponseStatus(300);
    }
}

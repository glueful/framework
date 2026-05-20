<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\ORM;

use Glueful\Database\ORM\Exceptions\LazyLoadingViolationException;
use PHPUnit\Framework\TestCase;

class LazyLoadingViolationExceptionTest extends TestCase
{
    public function testCarriesModelClassAndRelation(): void
    {
        $exception = new LazyLoadingViolationException('App\\Models\\User', 'posts');

        $this->assertSame('App\\Models\\User', $exception->modelClass);
        $this->assertSame('posts', $exception->relation);
    }

    public function testMessageMentionsBothModelAndRelation(): void
    {
        $exception = new LazyLoadingViolationException('App\\Models\\User', 'posts');

        $this->assertStringContainsString('posts', $exception->getMessage());
        $this->assertStringContainsString('App\\Models\\User', $exception->getMessage());
        $this->assertStringContainsString("->with('posts')", $exception->getMessage());
    }

    public function testExtendsLogicException(): void
    {
        $this->assertInstanceOf(
            \LogicException::class,
            new LazyLoadingViolationException('A', 'b')
        );
    }
}

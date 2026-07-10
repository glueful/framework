<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database;

use Glueful\Database\Connection;
use PHPUnit\Framework\TestCase;

final class ConnectionNewPdoTest extends TestCase
{
    public function testNewPdoReturnsAnIndependentSession(): void
    {
        $connection = new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => ':memory:'],
            'pooling' => ['enabled' => false],
        ]);

        self::assertNotSame($connection->getPDO(), $connection->newPdo());
    }
}

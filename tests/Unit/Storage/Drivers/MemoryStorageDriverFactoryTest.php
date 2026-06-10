<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Storage\Drivers;

use Glueful\Storage\Contracts\StorageDriverFactoryInterface;
use Glueful\Storage\Drivers\MemoryStorageDriverFactory;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\TestCase;

final class MemoryStorageDriverFactoryTest extends TestCase
{
    public function testCreatesMemoryFilesystem(): void
    {
        $factory = new MemoryStorageDriverFactory();

        $this->assertInstanceOf(StorageDriverFactoryInterface::class, $factory);
        $this->assertSame('memory', $factory->driver());
        $this->assertTrue($factory->available([]));
        $this->assertInstanceOf(FilesystemOperator::class, $factory->create([]));
        $this->assertSame(true, $factory->features([])['supports_atomic_move']);
    }
}

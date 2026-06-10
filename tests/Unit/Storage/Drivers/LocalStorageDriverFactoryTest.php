<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Storage\Drivers;

use Glueful\Storage\Contracts\StorageDriverFactoryInterface;
use Glueful\Storage\Drivers\LocalStorageDriverFactory;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\TestCase;

final class LocalStorageDriverFactoryTest extends TestCase
{
    public function testCreatesLocalFilesystem(): void
    {
        $factory = new LocalStorageDriverFactory();

        $this->assertInstanceOf(StorageDriverFactoryInterface::class, $factory);
        $this->assertSame('local', $factory->driver());
        $this->assertTrue($factory->available([]));

        $root = sys_get_temp_dir() . '/glueful-local-' . bin2hex(random_bytes(4));
        mkdir($root, 0777, true);

        $fs = $factory->create(['root' => $root, 'visibility' => 'private']);
        $this->assertInstanceOf(FilesystemOperator::class, $fs);
        $this->assertSame(true, $factory->features([])['supports_atomic_move']);
    }

    public function testCreateRequiresRoot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new LocalStorageDriverFactory())->create([]);
    }
}

<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Storage;

use Glueful\Storage\StorageManager;
use Glueful\Storage\Support\UrlGenerator;
use Glueful\Uploader\Storage\FlysystemStorage;
use PHPUnit\Framework\TestCase;

final class FlysystemStorageTest extends TestCase
{
    private StorageManager $storage;
    private UrlGenerator $urls;
    private string $disk = 'memory';

    protected function setUp(): void
    {
        $config = [
            'default' => $this->disk,
            'disks' => [
                'memory' => ['driver' => 'memory'],
            ],
        ];

        $this->storage = new StorageManager($config, new \Glueful\Storage\PathGuard());
        $this->urls = new UrlGenerator($config, new \Glueful\Storage\PathGuard());
    }

    public function testStoreExistsAndDelete(): void
    {
        $fs = new FlysystemStorage($this->storage, $this->urls, $this->disk);

        $tmp = tmpfile();
        fwrite($tmp, 'hello-world');
        $meta = stream_get_meta_data($tmp);
        $path = $meta['uri'];

        $dest = 'uploads/test.txt';
        $stored = $fs->store($path, $dest);
        $this->assertSame($dest, $stored);

        $this->assertTrue($fs->exists($dest));
        $this->assertNotSame('', $fs->getUrl($dest));

        $this->assertTrue($fs->delete($dest));
        $this->assertFalse($fs->exists($dest));
    }
}


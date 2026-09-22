<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Uploader;

use Glueful\Database\Connection;
use Glueful\Storage\PathGuard;
use Glueful\Storage\StorageManager;
use Glueful\Uploader\BlobPurger;
use PHPUnit\Framework\TestCase;

/**
 * A deleted blob was only marked deleted: its bytes stayed on the disk and its row in the table
 * for good, since nothing ever removed them. BlobPurger removes both once the grace period has
 * passed.
 */
final class BlobPurgerTest extends TestCase
{
    private string $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($items as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->dir);
        }
        parent::tearDown();
    }

    public function testADeletedBlobPastTheGracePeriodLosesItsFileAndRow(): void
    {
        [$db, $storage] = $this->setUpStore();
        $this->seed($db, 'old000000001', 'deleted', 'a/old.png', deletedAt: '2020-01-01 00:00:00');
        $this->seed($db, 'recent000001', 'deleted', 'a/recent.png', deletedAt: date('Y-m-d H:i:s'));
        $this->seed($db, 'active000001', 'active', 'a/active.png', deletedAt: null, updatedAt: '2020-01-01 00:00:00');
        $this->seed($db, 'legacy000001', 'deleted', 'a/legacy.png', deletedAt: null, updatedAt: '2020-01-01 00:00:00');

        $result = (new BlobPurger($db, $storage, 'uploads'))->purgeDeletedOlderThan(30);

        self::assertSame(['purged' => 2, 'failed' => 0], $result);
        self::assertFileDoesNotExist($this->dir . '/disk/a/old.png');
        self::assertFileDoesNotExist($this->dir . '/disk/a/legacy.png');
        self::assertFileExists($this->dir . '/disk/a/recent.png');
        self::assertFileExists($this->dir . '/disk/a/active.png');
        self::assertSame(['active000001', 'recent000001'], $this->uuids($db));
    }

    public function testABlobWhoseFileCannotBeDeletedKeepsItsRow(): void
    {
        [$db, $storage] = $this->setUpStore();
        $this->seed($db, 'lost00000001', 'deleted', 'a/lost.png', deletedAt: '2020-01-01 00:00:00', disk: 'gone');

        $result = (new BlobPurger($db, $storage, 'uploads'))->purgeDeletedOlderThan(30);

        self::assertSame(['purged' => 0, 'failed' => 1], $result);
        self::assertSame(['lost00000001'], $this->uuids($db));
    }

    /** @return array{0: Connection, 1: StorageManager} */
    private function setUpStore(): array
    {
        $this->dir = sys_get_temp_dir() . '/glueful-blob-purge-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/disk', 0755, true);
        $db = new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => $this->dir . '/app.sqlite'],
            'pooling' => ['enabled' => false],
        ]);
        $db->getSchemaBuilder()->createTable('blobs', function ($table): void {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('name', 255);
            $table->string('mime_type', 127);
            $table->bigInteger('size');
            $table->string('url', 2048);
            $table->string('storage_type', 20)->nullable();
            $table->string('status', 20)->default('active');
            $table->string('created_by', 12);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
        $storage = new StorageManager(
            ['default' => 'uploads', 'disks' => ['uploads' => ['driver' => 'local', 'root' => $this->dir . '/disk']]],
            new PathGuard(),
        );
        return [$db, $storage];
    }

    private function seed(
        Connection $db,
        string $uuid,
        string $status,
        string $path,
        ?string $deletedAt,
        ?string $updatedAt = null,
        ?string $disk = null,
    ): void {
        $full = $this->dir . '/disk/' . $path;
        if (!is_dir(dirname($full))) {
            mkdir(dirname($full), 0755, true);
        }
        file_put_contents($full, 'x');
        $db->getPDO()->prepare(
            'INSERT INTO blobs (uuid, name, mime_type, size, url, storage_type, status, created_by, updated_at, deleted_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$uuid, basename($path), 'image/png', 1, $path, $disk, $status, 'usr000000001', $updatedAt, $deletedAt]);
    }

    /** @return list<string> */
    private function uuids(Connection $db): array
    {
        $rows = $db->getPDO()->query('SELECT uuid FROM blobs ORDER BY uuid')->fetchAll(\PDO::FETCH_COLUMN);
        return array_values(array_map('strval', (array) $rows));
    }
}

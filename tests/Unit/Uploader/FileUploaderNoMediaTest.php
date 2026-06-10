<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Uploader;

use Glueful\Application;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Framework;
use Glueful\Container\Definition\ValueDefinition;
use Glueful\Routing\RouteManifest;
use Glueful\Uploader\FileUploader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Phase B / Task B1 — proves FileUploader works with NO media processor bound.
 *
 * Boots a real Framework against a temp app dir with a file-backed SQLite
 * database and a local `uploads` disk rooted in a temp directory, then resolves
 * FileUploader from the container. Because no MediaProcessorInterface is bound,
 * the uploader must fall back to dependency-free, type-only metadata and emit no
 * thumbnail — while still storing the file and (optionally) persisting a blob row.
 */
final class FileUploaderNoMediaTest extends TestCase
{
    private string $appPath;
    private string $uploadsRoot;
    private string $assetsRoot;
    private string $dbFile;
    private Application $app;
    private ApplicationContext $context;

    protected function setUp(): void
    {
        RouteManifest::reset();

        $this->appPath = sys_get_temp_dir() . '/glueful-uploader-' . uniqid('', true);
        $this->uploadsRoot = $this->appPath . '/disk';
        $this->assetsRoot = $this->appPath . '/assets-disk';
        $this->dbFile = $this->appPath . '/app.sqlite';
        $cfg = $this->appPath . '/config';
        mkdir($cfg, 0755, true);
        mkdir($this->uploadsRoot, 0755, true);
        mkdir($this->assetsRoot, 0755, true);

        // Force a file-backed SQLite so every Connection (migrations + repository)
        // shares one database. The phpunit env default (DB_DATABASE=:memory:) would
        // otherwise give each PDO its own empty in-memory DB.
        putenv('DB_DATABASE=' . $this->dbFile);
        putenv('DB_SQLITE_DATABASE=' . $this->dbFile);
        $_ENV['DB_DATABASE'] = $this->dbFile;
        $_ENV['DB_SQLITE_DATABASE'] = $this->dbFile;

        file_put_contents(
            $cfg . '/app.php',
            "<?php\nreturn ['name' => 'T', 'version_full' => '1.0.0', 'env' => 'testing', 'debug' => true];\n"
        );
        file_put_contents(
            $cfg . '/database.php',
            "<?php\nreturn ['engine' => 'sqlite', 'sqlite' => ['primary' => '" . $this->dbFile . "'], "
            . "'pooling' => ['enabled' => false]];\n"
        );
        file_put_contents(
            $cfg . '/cache.php',
            "<?php\nreturn ['enabled' => true, 'default' => 'array', 'stores' => ['array' => ['driver' => 'array']]];\n"
        );
        file_put_contents($cfg . '/security.php', "<?php\nreturn ['csrf' => ['enabled' => false]];\n");
        file_put_contents($cfg . '/session.php', "<?php\nreturn ['jwt_key' => 'test'];\n");

        // Local `uploads` disk rooted in a temp dir so we can assert on-disk storage.
        file_put_contents(
            $cfg . '/storage.php',
            "<?php\nreturn ['default' => 'uploads', 'disks' => ["
            . "'uploads' => ['driver' => 'local', 'root' => '" . $this->uploadsRoot . "', "
            . "'visibility' => 'private'], "
            . "'assets' => ['driver' => 'local', 'root' => '" . $this->assetsRoot . "', "
            . "'visibility' => 'private']"
            . "]];\n"
        );
        file_put_contents(
            $cfg . '/uploads.php',
            "<?php\nreturn ['enabled' => true, 'disk' => 'uploads', 'path_prefix' => '', "
            . "'allowed_types' => ['image/*', 'video/*', 'audio/*'], 'max_size' => 10485760];\n"
        );

        // BaseRepository caches a process-global shared Connection; reset it so the
        // BlobRepository rebinds to this test's file-backed context (otherwise it
        // reuses the bootstrap's :memory: connection, which has no `blobs` table).
        $this->resetSharedConnection();

        $this->app = Framework::create($this->appPath)->boot(allowReboot: true);
        $this->context = $this->app->getContext();

        $this->runBlobsMigration();

        // BlobRepository requires created_by (resolved via Utils::getUser()).
        // Register a request carrying an authenticated user so the blob row persists.
        $request = new Request();
        $request->attributes->set('user', ['uuid' => 'usr123456789']);
        /** @var \Glueful\Container\Container $container */
        $container = $this->context->getContainer();
        $container->load([
            'request' => new ValueDefinition('request', $request),
        ]);

        // The framework boot auto-runs migrations (uploads.enabled=true), which
        // creates the `blobs` table — no manual schema setup needed here.
    }

    protected function tearDown(): void
    {
        putenv('DB_DATABASE=:memory:');
        putenv('DB_SQLITE_DATABASE');
        $_ENV['DB_DATABASE'] = ':memory:';
        unset($_ENV['DB_SQLITE_DATABASE']);

        if (isset($this->appPath) && is_dir($this->appPath)) {
            $this->recursiveRemove($this->appPath);
        }
        parent::tearDown();
    }

    public function testUploadMediaWithoutProcessorStoresFileAndYieldsTypeOnlyMetadata(): void
    {
        /** @var FileUploader $uploader */
        $uploader = $this->context->getContainer()->get(FileUploader::class);

        $tmp = $this->createPngFixture();

        $result = $uploader->uploadMedia(
            [
                'name' => 'pic.png',
                'type' => 'image/png',
                'tmp_name' => $tmp,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($tmp),
            ],
            'posts/uuid123',
            ['save_to_blobs' => true]
        );

        // (a) File is stored on disk under the local disk root.
        $this->assertArrayHasKey('path', $result);
        $this->assertFileExists($this->uploadsRoot . '/' . $result['path']);

        // (b) No thumbnail — no media processor bound.
        $this->assertNull($result['thumb_url']);

        // (c) Type-only metadata: image type, but no dimensions/duration.
        $this->assertSame('image', $result['type']);
        $this->assertNull($result['width']);
        $this->assertNull($result['height']);
        $this->assertNull($result['duration_s']);

        // (d) Blob row persisted when save_to_blobs is true.
        $this->assertArrayHasKey('blob_uuid', $result);
        $this->assertNotSame('', (string) $result['blob_uuid']);

        $connection = new Connection();
        $row = $connection->table('blobs')
            ->where('uuid', $result['blob_uuid'])
            ->first();
        $this->assertIsArray($row);
        $this->assertSame('image/png', $row['mime_type']);
    }

    public function testExplicitStorageDriverIsPersistedAsBlobStorageType(): void
    {
        $uploader = new FileUploader(storageDriver: 'assets', context: $this->context);

        $tmp = $this->createPngFixture();

        $result = $uploader->uploadMedia(
            [
                'name' => 'pic.png',
                'type' => 'image/png',
                'tmp_name' => $tmp,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($tmp),
            ],
            'entries/uuid456',
            ['save_to_blobs' => true]
        );

        $this->assertArrayHasKey('path', $result);
        $this->assertFileExists($this->assetsRoot . '/' . $result['path']);
        $this->assertFileDoesNotExist($this->uploadsRoot . '/' . $result['path']);

        $connection = new Connection();
        $row = $connection->table('blobs')
            ->where('uuid', $result['blob_uuid'])
            ->first();

        $this->assertIsArray($row);
        $this->assertSame('assets', $row['storage_type']);
    }

    public function testAccessorsForMovedConcreteTypesAreRemoved(): void
    {
        $this->assertFalse(
            method_exists(FileUploader::class, 'getThumbnailGenerator'),
            'getThumbnailGenerator() must be removed — it leaks the moved ThumbnailGenerator type.'
        );
        $this->assertFalse(
            method_exists(FileUploader::class, 'getMetadataExtractor'),
            'getMetadataExtractor() must be removed — it leaks the moved MediaMetadataExtractor type.'
        );
    }

    private function resetSharedConnection(): void
    {
        $ref = new \ReflectionClass(\Glueful\Repository\BaseRepository::class);
        if ($ref->hasProperty('sharedConnection')) {
            $prop = $ref->getProperty('sharedConnection');
            $prop->setAccessible(true);
            $prop->setValue(null, null);
        }
    }

    private function runBlobsMigration(): void
    {
        // Create the blobs table on the same shared connection the repository
        // uses, mirroring migrations/uploads/001_CreateBlobsTable.php.
        $schema = \Glueful\Database\Connection::fromContext($this->context)->getSchemaBuilder();

        $schema->createTable('blobs', function ($table): void {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->string('mime_type', 127);
            $table->bigInteger('size');
            $table->string('url', 2048);
            $table->string('storage_type', 20)->default('local');
            $table->string('visibility', 10)->default('private');
            $table->string('status', 20)->default('active');
            $table->string('created_by', 12);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();

            $table->unique('uuid');
        });
    }

    private function createPngFixture(): string
    {
        $path = $this->appPath . '/fixture.png';
        // 1x1 transparent PNG.
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        );
        file_put_contents($path, $png);

        return $path;
    }

    private function recursiveRemove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->recursiveRemove($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}

<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Container\Providers;

use Glueful\Application;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Definition\ValueDefinition;
use Glueful\Framework;
use Glueful\Routing\RouteManifest;
use Glueful\Uploader\Contracts\MediaProcessorInterface;
use Glueful\Uploader\FileUploader;
use Glueful\Uploader\MediaMetadata;
use Glueful\Uploader\Storage\StorageInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Phase B / Task B2 — proves the StorageProvider FileUploader factory passes the
 * optionally-resolved MediaProcessorInterface seam into FileUploader.
 *
 * Reuses the B1 harness (real Framework boot + file-backed SQLite + local
 * `uploads` disk + BaseRepository shared-connection reset + a `request` service
 * carrying a `user` attribute for blob `created_by`).
 *
 * Two cases:
 *  1. Unbound  — no MediaProcessorInterface bound → factory resolves $media to
 *     null and uploadMedia() yields thumb_url === null with type-only metadata.
 *  2. Bound fake — a fake MediaProcessorInterface is load()-ed into the SAME
 *     booted container BEFORE FileUploader is resolved; uploadMedia() of a tiny
 *     image returns the fake's sentinel thumb_url and the fake's metadata dims.
 *     This proves the factory's `$c->has(...) ? $c->get(...) : null` resolution
 *     actually threads $media through to the FileUploader constructor.
 */
final class StorageProviderFileUploaderMediaTest extends TestCase
{
    private string $appPath;
    private string $uploadsRoot;
    private string $dbFile;
    private Application $app;
    private ApplicationContext $context;

    protected function setUp(): void
    {
        RouteManifest::reset();

        $this->appPath = sys_get_temp_dir() . '/glueful-uploader-media-' . uniqid('', true);
        $this->uploadsRoot = $this->appPath . '/disk';
        $this->dbFile = $this->appPath . '/app.sqlite';
        $cfg = $this->appPath . '/config';
        mkdir($cfg, 0755, true);
        mkdir($this->uploadsRoot, 0755, true);

        // File-backed SQLite so every Connection shares one database.
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

        file_put_contents(
            $cfg . '/storage.php',
            "<?php\nreturn ['default' => 'uploads', 'disks' => ['uploads' => "
            . "['driver' => 'local', 'root' => '" . $this->uploadsRoot . "', 'visibility' => 'private']]];\n"
        );
        file_put_contents(
            $cfg . '/uploads.php',
            "<?php\nreturn ['enabled' => true, 'disk' => 'uploads', 'path_prefix' => '', "
            . "'allowed_types' => ['image/*', 'video/*', 'audio/*'], 'max_size' => 10485760];\n"
        );

        $this->resetSharedConnection();

        $this->app = Framework::create($this->appPath)->boot(allowReboot: true);
        $this->context = $this->app->getContext();

        $this->runBlobsMigration();

        $request = new Request();
        $request->attributes->set('user', ['uuid' => 'usr123456789']);
        $this->context->getContainer()->load([
            'request' => new ValueDefinition('request', $request),
        ]);
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

    /**
     * Case 1 — no MediaProcessorInterface bound: the factory resolves $media to
     * null and the no-op fallback holds (no thumbnail, type-only metadata).
     */
    public function testFactoryResolvesNullMediaWhenUnbound(): void
    {
        $this->assertFalse(
            $this->context->getContainer()->has(MediaProcessorInterface::class),
            'Precondition: no MediaProcessorInterface should be bound for the unbound case.'
        );

        /** @var FileUploader $uploader */
        $uploader = $this->context->getContainer()->get(FileUploader::class);

        $result = $uploader->uploadMedia($this->pngFile(), 'posts/uuid123', ['save_to_blobs' => false]);

        // Media → null: no thumbnail, type-only metadata (no dimensions).
        $this->assertNull($result['thumb_url']);
        $this->assertSame('image', $result['type']);
        $this->assertNull($result['width']);
        $this->assertNull($result['height']);
    }

    /**
     * Case 2 — a fake MediaProcessorInterface bound into the SAME booted
     * container: FileUploader must pick it up, proving the factory threaded
     * $media through. Verified behaviorally via the fake's sentinel thumb_url
     * and the fake's metadata dimensions.
     */
    public function testFactoryThreadsBoundMediaProcessorIntoUploader(): void
    {
        $fake = new FakeMediaProcessor();

        // Bind the fake BEFORE FileUploader is resolved so the factory's
        // `$c->has(...) ? $c->get(...) : null` picks it up. load() mutates the
        // booted container's definitions in place — the same mechanism B1 used
        // to inject the `request` service.
        $this->context->getContainer()->load([
            MediaProcessorInterface::class => new ValueDefinition(MediaProcessorInterface::class, $fake),
        ]);

        $this->assertTrue(
            $this->context->getContainer()->has(MediaProcessorInterface::class),
            'Precondition: the fake MediaProcessorInterface must be bound.'
        );

        /** @var FileUploader $uploader */
        $uploader = $this->context->getContainer()->get(FileUploader::class);

        $result = $uploader->uploadMedia($this->pngFile(), 'posts/uuid123', ['save_to_blobs' => false]);

        // The fake's generateThumbnail() sentinel must surface — only possible if
        // the factory passed the fake into FileUploader's 5th ctor arg.
        $this->assertSame(FakeMediaProcessor::THUMB_URL, $result['thumb_url']);

        // The fake's extractMetadata() dimensions must surface too.
        $this->assertSame('image', $result['type']);
        $this->assertSame(FakeMediaProcessor::WIDTH, $result['width']);
        $this->assertSame(FakeMediaProcessor::HEIGHT, $result['height']);
    }

    /**
     * @return array<string, mixed>
     */
    private function pngFile(): array
    {
        $tmp = $this->createPngFixture();

        return [
            'name' => 'pic.png',
            'type' => 'image/png',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmp),
        ];
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
        $path = $this->appPath . '/fixture-' . uniqid('', true) . '.png';
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

/**
 * Minimal fake processor — returns known sentinels so the test can prove the
 * factory threaded this instance into FileUploader.
 */
final class FakeMediaProcessor implements MediaProcessorInterface
{
    public const THUMB_URL = 'https://cdn.test/thumbs/sentinel.png';
    public const WIDTH = 1234;
    public const HEIGHT = 567;

    public function extractMetadata(string $filepath, string $mimeType): MediaMetadata
    {
        return new MediaMetadata('image', self::WIDTH, self::HEIGHT);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function generateThumbnail(
        StorageInterface $storage,
        string $sourcePath,
        string $storagePath,
        string $originalFilename,
        array $options = []
    ): ?string {
        return self::THUMB_URL;
    }

    public function supportsThumbnail(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'image/');
    }

    /**
     * @param array<string, mixed> $options
     * @return array{data: string, mime: string}
     */
    public function renderVariant(string $sourcePath, array $options): array
    {
        return ['data' => '', 'mime' => 'image/png'];
    }
}

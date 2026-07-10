<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Controllers;

use Glueful\Application;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Definition\ValueDefinition;
use Glueful\Controllers\UploadController;
use Glueful\Framework;
use Glueful\Repository\BlobRepository;
use Glueful\Services\ImageSecurityValidator;
use Glueful\Routing\RouteManifest;
use Glueful\Storage\StorageManager;
use Glueful\Storage\Support\UrlGenerator;
use Glueful\Uploader\Contracts\BlobAccessContext;
use Glueful\Uploader\Contracts\BlobAction;
use Glueful\Uploader\Contracts\BlobAccessPolicy;
use Glueful\Uploader\Contracts\BlobCreatedHook;
use Glueful\Uploader\Contracts\MediaProcessorInterface;
use Glueful\Uploader\Contracts\NullBlobAccessPolicy;
use Glueful\Uploader\Contracts\NullBlobCreatedHook;
use Glueful\Uploader\FileUploader;
use Glueful\Uploader\MediaMetadata;
use Glueful\Uploader\Storage\StorageInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Phase B / Task B3 — proves UploadController routes on-demand variant serving
 * through the OPTIONAL MediaProcessorInterface seam and falls back to serving the
 * ORIGINAL bytes when no processor is bound.
 *
 * Reuses the B1/B2 harness (real Framework boot + file-backed SQLite + local
 * `uploads` disk + BaseRepository shared-connection reset + a `request` service
 * with a `user` attribute, and a `blobs` table). The variant path is exercised by
 * calling UploadController::show() directly with a query-bearing Request — the
 * controller is constructed by hand so we control the $media seam (null vs fake).
 *
 * Cases:
 *  1. No-media, width → serves the ORIGINAL (original mime + original byte length),
 *     never 500.
 *  2. No-media, format=webp → 415 (cannot honor an explicit format conversion with
 *     no processor).
 *  3. Media-present, width → the fake's sentinel bytes + mime surface (proves the
 *     seam is used).
 */
final class UploadControllerVariantTest extends TestCase
{
    private string $appPath;
    private string $uploadsRoot;
    private string $dbFile;
    private Application $app;
    private ApplicationContext $context;
    private string $blobUuid;
    private string $originalBytes;

    protected function setUp(): void
    {
        RouteManifest::reset();

        $this->appPath = sys_get_temp_dir() . '/glueful-uploadctrl-' . uniqid('', true);
        $this->uploadsRoot = $this->appPath . '/disk';
        $this->dbFile = $this->appPath . '/app.sqlite';
        $cfg = $this->appPath . '/config';
        mkdir($cfg, 0755, true);
        mkdir($this->uploadsRoot, 0755, true);

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
        // access=public so show() does not require auth for retrieval.
        file_put_contents(
            $cfg . '/uploads.php',
            "<?php\nreturn ['enabled' => true, 'disk' => 'uploads', 'path_prefix' => '', 'access' => 'public', "
            . "'allowed_types' => ['image/*', 'video/*', 'audio/*'], 'max_size' => 10485760];\n"
        );

        $this->resetSharedConnection();

        $this->app = Framework::create($this->appPath)->boot(allowReboot: true);
        $this->context = $this->app->getContext();

        $this->runBlobsMigration();

        $request = new Request();
        $request->attributes->set('user', ['uuid' => 'usr123456789']);
        /** @var \Glueful\Container\Container $container */
        $container = $this->context->getContainer();
        $container->load([
            'request' => new ValueDefinition('request', $request),
        ]);

        // Seed a stored image file + a matching blob row.
        $this->originalBytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        );
        $relPath = 'posts/original.png';
        $full = $this->uploadsRoot . '/' . $relPath;
        mkdir(dirname($full), 0755, true);
        file_put_contents($full, $this->originalBytes);

        $this->blobUuid = 'blob00000001';
        \Glueful\Database\Connection::fromContext($this->context)
            ->table('blobs')
            ->insert([
                'uuid' => $this->blobUuid,
                'name' => 'original.png',
                'mime_type' => 'image/png',
                'size' => strlen($this->originalBytes),
                'url' => $relPath,
                'storage_type' => 'uploads',
                'visibility' => 'public',
                'status' => 'active',
                'created_by' => 'usr123456789',
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

    public function testNoMediaWidthServesOriginal(): void
    {
        $controller = $this->makeController(null);

        $request = Request::create('/blobs/' . $this->blobUuid, 'GET', ['width' => 100]);
        $response = $controller->show($request, $this->blobUuid);

        $this->assertNotSame(500, $response->getStatusCode());
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringStartsNotWith('attachment', (string) $response->headers->get('Content-Disposition'));

        $body = $this->bodyOf($response);
        $this->assertSame(strlen($this->originalBytes), strlen($body));
        $this->assertSame($this->originalBytes, $body);
    }

    public function testUnsafeBlobMimeIsServedAsAttachmentWithNosniff(): void
    {
        $uuid = 'blob00000002';
        $relPath = 'posts/page.html';
        $full = $this->uploadsRoot . '/' . $relPath;
        file_put_contents($full, '<script>alert(1)</script>');

        \Glueful\Database\Connection::fromContext($this->context)
            ->table('blobs')
            ->insert([
                'uuid' => $uuid,
                'name' => 'page.html',
                'mime_type' => 'text/html',
                'size' => filesize($full),
                'url' => $relPath,
                'storage_type' => 'uploads',
                'visibility' => 'public',
                'status' => 'active',
                'created_by' => 'usr123456789',
            ]);

        $response = $this->makeController(null)->show(Request::create('/blobs/' . $uuid), $uuid);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));
    }

    public function testNoMediaFormatReturns415(): void
    {
        $controller = $this->makeController(null);

        $request = Request::create('/blobs/' . $this->blobUuid, 'GET', ['format' => 'webp']);
        $response = $controller->show($request, $this->blobUuid);

        $this->assertSame(415, $response->getStatusCode());
    }

    public function testMediaPresentWidthUsesSeam(): void
    {
        $fake = new VariantFakeMediaProcessor();
        $controller = $this->makeController($fake);

        $request = Request::create('/blobs/' . $this->blobUuid, 'GET', ['width' => 100]);
        $response = $controller->show($request, $this->blobUuid);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(VariantFakeMediaProcessor::MIME, $response->headers->get('Content-Type'));
        $this->assertSame(VariantFakeMediaProcessor::DATA, $this->bodyOf($response));
    }

    public function testVariantNotModifiedResponseIncludesNosniff(): void
    {
        $controller = $this->makeController(new VariantFakeMediaProcessor());

        $resize = [
            'width' => 100,
            'height' => null,
            'quality' => null,
            'format' => null,
            'fit' => null,
        ];
        $cacheKey = 'blob_variant:' . sha1($this->blobUuid . '|' . json_encode($resize));
        $etag = '"' . md5($cacheKey) . '"';

        $request = Request::create('/blobs/' . $this->blobUuid, 'GET', ['width' => 100]);
        $request->headers->set('If-None-Match', $etag);

        $response = $controller->show($request, $this->blobUuid);

        $this->assertSame(304, $response->getStatusCode());
        $this->assertSame($etag, $response->headers->get('ETag'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function testVariantRejectsInvalidSourceImageBeforeMediaProcessor(): void
    {
        $uuid = 'blob00000003';
        $relPath = 'posts/spoofed.png';
        $full = $this->uploadsRoot . '/' . $relPath;
        file_put_contents($full, '<script>alert(1)</script>');

        \Glueful\Database\Connection::fromContext($this->context)
            ->table('blobs')
            ->insert([
                'uuid' => $uuid,
                'name' => 'spoofed.png',
                'mime_type' => 'image/png',
                'size' => filesize($full),
                'url' => $relPath,
                'storage_type' => 'uploads',
                'visibility' => 'public',
                'status' => 'active',
                'created_by' => 'usr123456789',
            ]);

        $fake = new VariantFakeMediaProcessor();
        $response = $this->makeController($fake)->show(
            Request::create('/blobs/' . $uuid, 'GET', ['width' => 100]),
            $uuid
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(0, $fake->renderCalls);
    }

    public function testTenancyPolicyCanHideAnOtherwisePublicBlob(): void
    {
        $policy = new class implements BlobCreatedHook, BlobAccessPolicy {
            public ?BlobAccessContext $seen = null;

            public function onBlobCreated(string $blobUuid, ?string $uploaderUserUuid): void
            {
            }

            public function authorizeAccess(array $blob, BlobAccessContext $context): bool
            {
                $this->seen = $context;
                return false;
            }
        };

        $response = $this->makeController(null, $policy)->show(
            Request::create('/blobs/' . $this->blobUuid),
            $this->blobUuid,
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(BlobAction::VIEW, $policy->seen?->action);
        self::assertFalse($policy->seen?->signatureValid);
    }

    public function testUploadAttributesTheCreatedBlob(): void
    {
        $policy = new class implements BlobCreatedHook, BlobAccessPolicy {
            public ?string $blobUuid = null;
            public ?string $userUuid = null;

            public function onBlobCreated(string $blobUuid, ?string $uploaderUserUuid): void
            {
                $this->blobUuid = $blobUuid;
                $this->userUuid = $uploaderUserUuid;
            }

            public function authorizeAccess(array $blob, BlobAccessContext $context): bool
            {
                return true;
            }
        };

        $response = $this->makeController(null, $policy)->upload($this->uploadRequest());
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame($body['data']['blob_uuid'], $policy->blobUuid);
        self::assertSame('usr123456789', $policy->userUuid);
    }

    public function testAttributionFailureCompensatesTheCreatedBlob(): void
    {
        $policy = new class implements BlobCreatedHook, BlobAccessPolicy {
            public function onBlobCreated(string $blobUuid, ?string $uploaderUserUuid): void
            {
                throw new \RuntimeException('no tenant');
            }

            public function authorizeAccess(array $blob, BlobAccessContext $context): bool
            {
                return true;
            }
        };
        $connection = \Glueful\Database\Connection::fromContext($this->context);
        $before = $connection->table('blobs')->count();

        $response = $this->makeController(null, $policy)->upload($this->uploadRequest());

        self::assertSame(500, $response->getStatusCode());
        self::assertSame($before, $connection->table('blobs')->count());
    }

    private function makeController(
        ?MediaProcessorInterface $media,
        (BlobCreatedHook&BlobAccessPolicy)|null $blobExtension = null,
    ): UploadController
    {
        $c = $this->context->getContainer();

        return new UploadController(
            $this->context,
            $c->get(FileUploader::class),
            $c->get(BlobRepository::class),
            $c->get(StorageManager::class),
            $c->get(UrlGenerator::class),
            $media,
            new ImageSecurityValidator(),
            $blobExtension ?? new NullBlobCreatedHook(),
            $blobExtension ?? new NullBlobAccessPolicy(),
            new \Psr\Log\NullLogger(),
        );
    }

    private function bodyOf(\Symfony\Component\HttpFoundation\Response $response): string
    {
        ob_start();
        $response->sendContent();
        return (string) ob_get_clean();
    }

    private function uploadRequest(): Request
    {
        $source = $this->uploadsRoot . '/upload-source.png';
        file_put_contents($source, $this->originalBytes);
        $request = Request::create('/blobs', 'POST');
        $request->files->set('file', new UploadedFile($source, 'upload.png', 'image/png', null, true));

        return $request;
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
 * Fake processor whose renderVariant() returns known sentinel bytes + mime so the
 * test can prove the controller delegated through the seam.
 */
final class VariantFakeMediaProcessor implements MediaProcessorInterface
{
    public const DATA = 'SENTINEL-VARIANT-BYTES';
    public const MIME = 'image/avif';

    public int $renderCalls = 0;

    public function extractMetadata(string $filepath, string $mimeType): MediaMetadata
    {
        return new MediaMetadata('image', 1, 1);
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
        return null;
    }

    public function supportsThumbnail(string $mimeType): bool
    {
        return true;
    }

    /**
     * @param array<string, mixed> $options
     * @return array{data: string, mime: string}
     */
    public function renderVariant(string $sourcePath, array $options): array
    {
        $this->renderCalls++;

        return ['data' => self::DATA, 'mime' => self::MIME];
    }
}

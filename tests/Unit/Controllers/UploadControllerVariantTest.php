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
use Glueful\Uploader\BlobVersion;
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
    private VariantRecordingQueue $queue;

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
            . "'allowed_types' => ['image/*', 'video/*', 'audio/*'], 'max_size' => 10485760, "
            . "'image_processing' => ['pregenerate' => ['enabled' => true, 'queue' => 'images', "
            . "'widths' => [100, 200], 'formats' => ['webp'], 'mime_types' => ['image/png']]]];\n"
        );

        $this->resetSharedConnection();

        $this->app = Framework::create($this->appPath)->boot(allowReboot: true);
        $this->context = $this->app->getContext();

        $this->runBlobsMigration();

        $request = new Request();
        $request->attributes->set('user', ['uuid' => 'usr123456789']);
        /** @var \Glueful\Container\Container $container */
        $container = $this->context->getContainer();
        $this->queue = new VariantRecordingQueue();
        $container->load([
            'request' => new ValueDefinition('request', $request),
            \Glueful\Queue\QueueManager::class => new ValueDefinition(
                \Glueful\Queue\QueueManager::class,
                $this->queue,
            ),
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
        $first = $controller->show(
            Request::create('/blobs/' . $this->blobUuid, 'GET', ['width' => 100]),
            $this->blobUuid,
        );
        $etag = (string) $first->headers->get('ETag');

        $request = Request::create('/blobs/' . $this->blobUuid, 'GET', ['width' => 100]);
        $request->headers->set('If-None-Match', $etag);

        $response = $controller->show($request, $this->blobUuid);

        $this->assertSame(304, $response->getStatusCode());
        $this->assertSame($etag, $response->headers->get('ETag'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function testAVariantIsRebuiltWhenTheBlobChangesInPlace(): void
    {
        // An image optimized in place keeps its uuid: the variant cache and its ETag were keyed
        // on uuid and size parameters alone, so the old variant was served, and revalidated as
        // unchanged, for as long as the cache lived.
        $fake = new VariantFakeMediaProcessor('image/png');
        $controller = $this->makeController($fake);
        $first = $controller->show(
            Request::create('/blobs/' . $this->blobUuid, 'GET', ['width' => 100]),
            $this->blobUuid,
        );
        $staleEtag = (string) $first->headers->get('ETag');
        self::assertSame(1, $fake->renderCalls);
        $controller->show(Request::create('/blobs/' . $this->blobUuid, 'GET', ['width' => 100]), $this->blobUuid);
        self::assertSame(1, $fake->renderCalls, 'served from the stored copy');
        $oldVersion = BlobVersion::of($this->blobRow());

        \Glueful\Database\Connection::fromContext($this->context)->table('blobs')
            ->where('uuid', '=', $this->blobUuid)
            ->update(['size' => 10, 'updated_at' => '2030-01-01 00:00:00']);

        $request = Request::create('/blobs/' . $this->blobUuid, 'GET', ['width' => 100]);
        $request->headers->set('If-None-Match', $staleEtag);
        $response = $controller->show($request, $this->blobUuid);

        self::assertSame(200, $response->getStatusCode(), 'the old ETag no longer matches');
        self::assertNotSame($staleEtag, $response->headers->get('ETag'));
        self::assertSame(2, $fake->renderCalls, 'the variant is rendered again, not read from the cache');
        self::assertDirectoryDoesNotExist(
            $this->uploadsRoot . '/_variants/' . $this->blobUuid . '/' . $oldVersion,
            'the old version\'s copies are removed',
        );
    }

    public function testAVariantIsStoredBesideTheImageAndServedFromThere(): void
    {
        // Resized copies were base64 text in the app cache for seven days, rendered again each week.
        // Now each is a file beside the image, under its version, kept until the image changes.
        $fake = new VariantFakeMediaProcessor('image/png');
        $controller = $this->makeController($fake);
        $request = fn () => Request::create('/blobs/' . $this->blobUuid, 'GET', ['width' => 100]);

        $first = $controller->show($request(), $this->blobUuid);
        $second = $this->makeController($fake)->show($request(), $this->blobUuid);

        self::assertSame(1, $fake->renderCalls, 'the second request reads the stored copy');
        self::assertSame(VariantFakeMediaProcessor::DATA, $this->bodyOf($second));
        self::assertSame('image/png', $second->headers->get('Content-Type'));
        self::assertSame($first->headers->get('ETag'), $second->headers->get('ETag'));
        $stored = glob($this->uploadsRoot . '/_variants/' . $this->blobUuid . '/'
            . BlobVersion::of($this->blobRow()) . '/*.png') ?: [];
        self::assertCount(1, $stored);
        self::assertSame(VariantFakeMediaProcessor::DATA, file_get_contents($stored[0]));
    }

    public function testAVariantInAnotherFormatThanAskedForIsServedButNotStored(): void
    {
        // The copy is named for the format it was asked in; one that came back otherwise would be
        // stored under a name nothing reads.
        $fake = new VariantFakeMediaProcessor();
        $controller = $this->makeController($fake);
        $request = fn () => Request::create('/blobs/' . $this->blobUuid, 'GET', ['width' => 100]);

        $controller->show($request(), $this->blobUuid);
        $second = $controller->show($request(), $this->blobUuid);

        self::assertSame(VariantFakeMediaProcessor::MIME, $second->headers->get('Content-Type'));
        self::assertSame(2, $fake->renderCalls);
        self::assertDirectoryDoesNotExist($this->uploadsRoot . '/_variants');
    }

    public function testAVariantTheStoreCannotWriteIsStillServed(): void
    {
        $this->useStore(new \Glueful\Uploader\BlobVariantStore(
            $this->context->getContainer()->get(StorageManager::class),
            'uploads',
            'missing-disk',
        ));
        $fake = new VariantFakeMediaProcessor('image/png');
        $request = fn () => Request::create('/blobs/' . $this->blobUuid, 'GET', ['width' => 100]);

        $first = $this->makeController($fake)->show($request(), $this->blobUuid);
        $second = $this->makeController($fake)->show($request(), $this->blobUuid);

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(VariantFakeMediaProcessor::DATA, $this->bodyOf($second));
        self::assertSame(2, $fake->renderCalls, 'rendered each time, never a 500');
    }

    private function useStore(\Glueful\Uploader\BlobVariantStore $store): void
    {
        /** @var \Glueful\Container\Container $container */
        $container = $this->context->getContainer();
        $container->load([
            \Glueful\Uploader\BlobVariantStore::class => new ValueDefinition(
                \Glueful\Uploader\BlobVariantStore::class,
                $store,
            ),
        ]);
    }

    public function testVariantResizesASourceLargerThanTheOutputLimit(): void
    {
        $uuid = $this->insertWidePng();
        $fake = new VariantFakeMediaProcessor();

        $response = $this->makeController($fake)->show(
            Request::create('/blobs/' . $uuid, 'GET', ['width' => 400, 'height' => 400, 'format' => 'webp']),
            $uuid
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $fake->renderCalls);
    }

    public function testVariantWithNoTargetSizeKeepsTheOutputLimit(): void
    {
        $uuid = $this->insertWidePng();
        $fake = new VariantFakeMediaProcessor();

        $response = $this->makeController($fake)->show(
            Request::create('/blobs/' . $uuid, 'GET', ['format' => 'webp']),
            $uuid
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(0, $fake->renderCalls);
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

    public function testSvgWithWidthServesTheOriginalWithoutTouchingTheProcessor(): void
    {
        $uuid = 'blob00000004';
        $relPath = 'posts/logo.svg';
        $full = $this->uploadsRoot . '/' . $relPath;
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><circle cx="5" cy="5" r="4"/></svg>';
        file_put_contents($full, $svg);
        \Glueful\Database\Connection::fromContext($this->context)
            ->table('blobs')
            ->insert([
                'uuid' => $uuid,
                'name' => 'logo.svg',
                'mime_type' => 'image/svg+xml',
                'size' => strlen($svg),
                'url' => $relPath,
                'storage_type' => 'uploads',
                'visibility' => 'public',
                'status' => 'active',
                'created_by' => 'usr123456789',
            ]);

        $fake = new VariantFakeMediaProcessor();
        $response = $this->makeController($fake)->show(
            Request::create('/blobs/' . $uuid, 'GET', ['width' => 160]),
            $uuid
        );

        // A vector image has no raster variant: the width hint is ignored and the
        // original is served as-is, never a 422 from the raster validator.
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('image/svg+xml', $response->headers->get('Content-Type'));
        $this->assertSame($svg, $this->bodyOf($response));
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

    public function testAPublicBlobKeepsTheConfiguredCacheControl(): void
    {
        $this->asAnonymous();
        $response = $this->makeController(null)->show(Request::create('/blobs/' . $this->blobUuid), $this->blobUuid);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
        self::assertSame('86400', $response->headers->getCacheControlDirective('max-age'));
        self::assertFalse($response->headers->hasCacheControlDirective('immutable'));
    }

    public function testTheCurrentVersionMakesAPublicBlobImmutableForAYear(): void
    {
        // A URL naming the blob's current version can never answer different bytes: a changed
        // blob has a new version, so a shared cache may keep this answer for a year.
        $this->asAnonymous();
        $v = BlobVersion::of($this->blobRow());

        $original = $this->makeController(null)->show(
            Request::create('/blobs/' . $this->blobUuid, 'GET', ['v' => $v]),
            $this->blobUuid,
        );
        $variant = $this->makeController(new VariantFakeMediaProcessor())->show(
            Request::create('/blobs/' . $this->blobUuid, 'GET', ['v' => $v, 'width' => 100]),
            $this->blobUuid,
        );

        foreach (['original' => $original, 'variant' => $variant] as $what => $response) {
            self::assertSame(200, $response->getStatusCode(), $what);
            self::assertTrue($response->headers->hasCacheControlDirective('public'), $what);
            self::assertTrue($response->headers->hasCacheControlDirective('immutable'), $what);
            self::assertSame('31536000', $response->headers->getCacheControlDirective('max-age'), $what);
        }
    }

    public function testAVariantRevalidatedAtTheCurrentVersionStaysImmutable(): void
    {
        $this->asAnonymous();
        $controller = $this->makeController(new VariantFakeMediaProcessor());
        $query = ['v' => BlobVersion::of($this->blobRow()), 'width' => 100];
        $etag = (string) $controller->show(Request::create('/blobs/' . $this->blobUuid, 'GET', $query), $this->blobUuid)
            ->headers->get('ETag');

        $request = Request::create('/blobs/' . $this->blobUuid, 'GET', $query);
        $request->headers->set('If-None-Match', $etag);
        $response = $controller->show($request, $this->blobUuid);

        self::assertSame(304, $response->getStatusCode());
        self::assertTrue($response->headers->hasCacheControlDirective('immutable'));
    }

    public function testAnOutdatedVersionGetsTheOrdinaryCache(): void
    {
        // An old page still links the old version: it is answered with the current bytes, which
        // a cache must not keep for a year under that URL.
        $this->asAnonymous();
        $stale = BlobVersion::of($this->blobRow());
        \Glueful\Database\Connection::fromContext($this->context)->table('blobs')
            ->where('uuid', '=', $this->blobUuid)
            ->update(['updated_at' => '2030-01-01 00:00:00']);

        $response = $this->makeController(null)->show(
            Request::create('/blobs/' . $this->blobUuid, 'GET', ['v' => $stale]),
            $this->blobUuid,
        );

        self::assertNotSame($stale, BlobVersion::of($this->blobRow()));
        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->headers->hasCacheControlDirective('immutable'));
        self::assertSame('86400', $response->headers->getCacheControlDirective('max-age'));
    }

    public function testAPrivateBlobIsNeverStoredByASharedCache(): void
    {
        // Served because the request is signed in: a shared cache that kept it would hand it
        // to anyone. The browser may keep it, and revalidates every time, so a lost permission
        // takes effect on the next view.
        \Glueful\Database\Connection::fromContext($this->context)->table('blobs')
            ->where('uuid', '=', $this->blobUuid)
            ->update(['visibility' => 'private']);
        $v = BlobVersion::of($this->blobRow());

        foreach ([[], ['v' => $v], ['v' => $v, 'width' => 100]] as $query) {
            $response = $this->makeController(new VariantFakeMediaProcessor())->show(
                Request::create('/blobs/' . $this->blobUuid, 'GET', $query),
                $this->blobUuid,
            );

            $label = json_encode($query);
            self::assertSame(200, $response->getStatusCode(), $label);
            self::assertTrue($response->headers->hasCacheControlDirective('private'), $label);
            self::assertTrue($response->headers->hasCacheControlDirective('no-cache'), $label);
            self::assertFalse($response->headers->hasCacheControlDirective('public'), $label);
            self::assertFalse($response->headers->hasCacheControlDirective('immutable'), $label);
        }
    }

    public function testAPublicBlobAnAccessPolicyHidesFromVisitorsIsPrivate(): void
    {
        // Public by its column, but a tenancy policy shows it only to signed-in members: a shared
        // cache must not hand the member's copy to a visitor.
        $policy = new class implements BlobCreatedHook, BlobAccessPolicy {
            public function onBlobCreated(string $blobUuid, ?string $uploaderUserUuid): void
            {
            }

            public function authorizeAccess(array $blob, BlobAccessContext $context): bool
            {
                return $context->authenticatedUserUuid !== null;
            }
        };

        $response = $this->makeController(null, $policy)->show(
            Request::create('/blobs/' . $this->blobUuid, 'GET', ['v' => BlobVersion::of($this->blobRow())]),
            $this->blobUuid,
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertFalse($response->headers->hasCacheControlDirective('immutable'));
    }

    public function testBlobUrlNamesTheVersionWhenGivenTheBlob(): void
    {
        $row = $this->blobRow();
        $base = 'https://x.test' . api_prefix($this->context) . '/blobs/' . $this->blobUuid;

        self::assertSame(
            $base,
            UploadController::blobUrl($this->context, 'https://x.test', $this->blobUuid),
        );
        self::assertSame(
            $base . '?v=' . BlobVersion::of($row),
            UploadController::blobUrl($this->context, 'https://x.test', $this->blobUuid, $row),
        );
    }

    public function testInfoAndUploadReportTheVersion(): void
    {
        $info = $this->makeController(null)->info(Request::create('/blobs/' . $this->blobUuid . '/info'), $this->blobUuid);
        self::assertInstanceOf(\Glueful\Controllers\DTOs\BlobInfoData::class, $info);
        self::assertSame(BlobVersion::of($this->blobRow()), $info->toArray()['version'] ?? null);

        $response = $this->makeController(null)->upload($this->uploadRequest());
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(201, $response->getStatusCode());
        self::assertSame(BlobVersion::of($this->blobRow($body['data']['blob_uuid'])), $body['data']['version'] ?? null);
    }

    public function testTheVersionChangesWithTheBlob(): void
    {
        $row = ['updated_at' => '2026-01-01 00:00:00', 'size' => 10];

        self::assertSame(BlobVersion::of($row), BlobVersion::of($row));
        self::assertNotSame(BlobVersion::of($row), BlobVersion::of(['updated_at' => '2026-01-02 00:00:00'] + $row));
        self::assertNotSame(BlobVersion::of($row), BlobVersion::of(['size' => 11] + $row));
        self::assertMatchesRegularExpression('/^[a-f0-9]{12}$/', BlobVersion::of($row));
    }

    public function testAnUploadedImageQueuesItsCopies(): void
    {
        // Copies made when an image is uploaded, so no visitor waits for one to be rendered.
        $response = $this->makeController(null)->upload($this->uploadRequest());

        self::assertSame(201, $response->getStatusCode());
        $uuid = (string) (json_decode((string) $response->getContent(), true)['data']['blob_uuid'] ?? '');
        self::assertCount(1, $this->queue->pushed);
        self::assertSame(\Glueful\Uploader\Jobs\GenerateBlobVariantsJob::class, $this->queue->pushed[0]['job']);
        self::assertSame('images', $this->queue->pushed[0]['queue']);
        self::assertSame($uuid, $this->queue->pushed[0]['data']['blob_uuid']);
    }

    public function testAQueueThatFailsLeavesTheUploadStanding(): void
    {
        $this->queue->fail = true;
        $response = $this->makeController(null)->upload($this->uploadRequest());

        self::assertSame(201, $response->getStatusCode());
    }

    public function testTheJobStoresEachConfiguredCopyOnce(): void
    {
        $fake = new VariantFakeMediaProcessor('image/webp');
        $this->bindMedia($fake);
        $job = fn () => new \Glueful\Uploader\Jobs\GenerateBlobVariantsJob(
            ['blob_uuid' => $this->blobUuid],
            $this->context,
        );

        $job()->handle();
        self::assertSame(2, $fake->renderCalls, 'one per configured width');
        $job()->handle();
        self::assertSame(2, $fake->renderCalls, 'a stored copy is not rendered again');

        // The route finds them: same settings, same names.
        $route = new VariantFakeMediaProcessor('image/webp');
        $response = $this->makeController($route)->show(
            Request::create('/blobs/' . $this->blobUuid, 'GET', ['width' => 200, 'format' => 'webp']),
            $this->blobUuid,
        );
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $route->renderCalls);
    }

    public function testTheJobLeavesImagesOfOtherTypesAlone(): void
    {
        $fake = new VariantFakeMediaProcessor('image/webp');
        $this->bindMedia($fake);
        \Glueful\Database\Connection::fromContext($this->context)->table('blobs')
            ->where('uuid', '=', $this->blobUuid)
            ->update(['mime_type' => 'image/gif']);

        (new \Glueful\Uploader\Jobs\GenerateBlobVariantsJob(['blob_uuid' => $this->blobUuid], $this->context))
            ->handle();

        self::assertSame(0, $fake->renderCalls);
    }

    public function testThePruneCommandRemovesCopiesOfImagesThatAreGone(): void
    {
        $this->makeController(new VariantFakeMediaProcessor('image/png'))->show(
            Request::create('/blobs/' . $this->blobUuid, 'GET', ['width' => 100]),
            $this->blobUuid,
        );
        mkdir($this->uploadsRoot . '/_variants/gone00000001/0123456789ab', 0755, true);
        file_put_contents($this->uploadsRoot . '/_variants/gone00000001/0123456789ab/a.webp', 'w');

        $command = new \Glueful\Console\Commands\Storage\BlobVariantsPruneCommand(
            $this->context->getContainer(),
            $this->context,
        );
        $tester = new \Symfony\Component\Console\Tester\CommandTester($command);

        self::assertSame(0, $tester->execute([]));
        self::assertDirectoryDoesNotExist($this->uploadsRoot . '/_variants/gone00000001');
        self::assertCount(1, glob($this->uploadsRoot . '/_variants/' . $this->blobUuid . '/*/*.png') ?: []);

        self::assertSame(0, $tester->execute(['--all' => true]));
        self::assertSame([], glob($this->uploadsRoot . '/_variants/*') ?: []);
    }

    private function bindMedia(MediaProcessorInterface $media): void
    {
        /** @var \Glueful\Container\Container $container */
        $container = $this->context->getContainer();
        $container->load([
            MediaProcessorInterface::class => new ValueDefinition(MediaProcessorInterface::class, $media),
        ]);
    }

    private function asAnonymous(): void
    {
        $this->context->getContainer()->get('request')->attributes->remove('user');
    }

    /** @return array<string, mixed> */
    private function blobRow(?string $uuid = null): array
    {
        $row = \Glueful\Database\Connection::fromContext($this->context)->table('blobs')
            ->where('uuid', '=', $uuid ?? $this->blobUuid)
            ->first();
        self::assertIsArray($row);

        return $row;
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

    /** A real PNG wider than the 2048px output limit; returns its blob uuid. */
    private function insertWidePng(): string
    {
        $uuid = 'blob00000009';
        $relPath = 'posts/wide.png';
        $image = imagecreatetruecolor(2731, 20);
        imagepng($image, $this->uploadsRoot . '/' . $relPath);

        \Glueful\Database\Connection::fromContext($this->context)
            ->table('blobs')
            ->insert([
                'uuid' => $uuid,
                'name' => 'wide.png',
                'mime_type' => 'image/png',
                'size' => filesize($this->uploadsRoot . '/' . $relPath),
                'url' => $relPath,
                'storage_type' => 'uploads',
                'visibility' => 'public',
                'status' => 'active',
                'created_by' => 'usr123456789',
            ]);

        return $uuid;
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

    /** @param string $mime the type it answers; the stored copy is kept only when it is the one asked for */
    public function __construct(private readonly string $mime = self::MIME)
    {
    }

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

        return ['data' => self::DATA, 'mime' => $this->mime];
    }
}

/** Records what is pushed instead of queueing it; set $fail to make every push throw. */
final class VariantRecordingQueue extends \Glueful\Queue\QueueManager
{
    /** @var list<array{job: string, data: array<string, mixed>, queue: string|null}> */
    public array $pushed = [];
    public bool $fail = false;

    public function __construct()
    {
    }

    public function push(string $job, array $data = [], ?string $queue = null, ?string $connection = null): string
    {
        if ($this->fail) {
            throw new \RuntimeException('queue unavailable');
        }
        $this->pushed[] = ['job' => $job, 'data' => $data, 'queue' => $queue];

        return 'job-' . count($this->pushed);
    }
}

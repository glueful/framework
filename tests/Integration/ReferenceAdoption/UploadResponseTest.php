<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\ReferenceAdoption;

use Glueful\Application;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Container;
use Glueful\Controllers\UploadController;
use Glueful\Framework;
use Glueful\Helpers\Utils;
use Glueful\Repository\BlobRepository;
use Glueful\Routing\RouteManifest;
use Glueful\Routing\Router;
use Glueful\Storage\StorageManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Characterization + reference-adoption guard for {@see UploadController}'s
 * read/delete endpoints migrating to typed RESPONSE DTOs (the phased "adopt typed
 * DTOs as a reference example" work).
 *
 * What this pins — byte-identical, success path only:
 *  - info       GET  /blobs/{uuid}/info     → Response::success($blob, 'Blob metadata')
 *  - signedUrl  POST /blobs/{uuid}/signed-url → Response::success($payload, 'Signed URL generated')
 *  - delete     DELETE /blobs/{uuid}        → Response::success(['uuid'=>$uuid], 'Blob deleted')
 *
 * The native_url key is OMITTED when absent (the controller only writes it when a
 * native URL is minted). With the default (empty) `uploads.native_urls` config the
 * key never appears — these tests pin exactly that, so the DTO must NOT emit it.
 *
 * The repository + storage are swapped for deterministic doubles so the test
 * exercises the controller + router envelope path (the part being migrated) without
 * seeding a full blobs table / filesystem.
 */
final class UploadResponseTest extends TestCase
{
    private string $appPath;
    private Application $app;
    private ApplicationContext $context;
    private Router $router;

    /** The fixed blob row the repository double returns. */
    public const BLOB = [
        'uuid'         => 'blob-1111-2222',
        'name'         => 'report.pdf',
        'description'  => 'Quarterly report',
        'mime_type'    => 'application/pdf',
        'size'         => 1024,
        'url'          => 'uploads/report.pdf',
        'status'       => 'active',
        'visibility'   => 'private',
        'storage_type' => 'uploads',
        'created_by'   => 'user-1',
        'created_at'   => '2026-01-01 00:00:00',
        'updated_at'   => '2026-01-02 00:00:00',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        RouteManifest::reset();
        $this->bootFramework();
        $this->overrideBlobAndStorage();
        Utils::setContext($this->context);

        $this->router = $this->app->getContainer()->get(Router::class);
        $this->router->get('/test/blobs/{uuid}/info', [UploadController::class, 'info']);
        $this->router->post('/test/blobs/{uuid}/signed-url', [UploadController::class, 'signedUrl']);
        $this->router->delete('/test/blobs/{uuid}', [UploadController::class, 'delete']);
    }

    protected function tearDown(): void
    {
        Utils::setContext(null);
        if (isset($this->appPath) && is_dir($this->appPath)) {
            $this->recursiveRemoveDirectory($this->appPath);
        }
        parent::tearDown();
    }

    public function test_info_returns_byte_identical_success_envelope(): void
    {
        $request = Request::create('/test/blobs/blob-1111-2222/info', 'GET');
        $this->authenticate($request);

        $response = $this->router->dispatch($request);

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true);

        // url is passed through resolveBlobUrl(); with no base_url configured the
        // UrlGenerator returns the path unchanged. native_url is omitted (no policy).
        self::assertSame([
            'success' => true,
            'message' => 'Blob metadata',
            'data'    => self::BLOB,
        ], $body);

        // native_url MUST NOT appear when absent.
        self::assertArrayNotHasKey('native_url', $body['data']);
    }

    public function test_signed_url_returns_byte_identical_success_envelope(): void
    {
        $request = Request::create('/test/blobs/blob-1111-2222/signed-url?ttl=600', 'POST');
        $this->authenticate($request);

        $response = $this->router->dispatch($request);

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true);

        self::assertTrue($body['success']);
        self::assertSame('Signed URL generated', $body['message']);

        // Exact key set + order: uuid, signed_url, expires_in, expires_at (no native_url).
        self::assertSame(
            ['uuid', 'signed_url', 'expires_in', 'expires_at'],
            array_keys($body['data'])
        );
        self::assertSame('blob-1111-2222', $body['data']['uuid']);
        self::assertSame(600, $body['data']['expires_in']);
        self::assertIsString($body['data']['signed_url']);
        self::assertIsString($body['data']['expires_at']);
        self::assertArrayNotHasKey('native_url', $body['data']);
    }

    public function test_delete_returns_byte_identical_success_envelope(): void
    {
        $request = Request::create('/test/blobs/blob-1111-2222', 'DELETE');
        $this->authenticate($request);

        $response = $this->router->dispatch($request);

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true);

        self::assertSame([
            'success' => true,
            'message' => 'Blob deleted',
            'data'    => ['uuid' => 'blob-1111-2222'],
        ], $body);
    }

    /**
     * notFound path stays a Response (unchanged) — exercised for info to confirm the
     * migration only touches the success branch.
     */
    public function test_info_not_found_stays_response(): void
    {
        $request = Request::create('/test/blobs/missing/info', 'GET');
        $this->authenticate($request);

        $response = $this->router->dispatch($request);

        self::assertSame(404, $response->getStatusCode());
    }

    /**
     * Pins the native_url semantics directly on the DTOs: omitted when absent,
     * appended LAST (after expires_at) when present. Guards against a property-order
     * regression in {@see \Glueful\Controllers\DTOs\SignedUrlData}.
     */
    public function test_signed_url_dto_emits_native_url_last_when_present(): void
    {
        $serializer = new \Glueful\Serialization\ResponseDataSerializer();

        $without = $serializer->toArray(new \Glueful\Controllers\DTOs\SignedUrlData(
            uuid: 'u',
            signed_url: 's',
            expires_in: 60,
            expires_at: 't',
        ));
        self::assertSame(['uuid', 'signed_url', 'expires_in', 'expires_at'], array_keys($without));

        $with = $serializer->toArray(
            (new \Glueful\Controllers\DTOs\SignedUrlData(
                uuid: 'u',
                signed_url: 's',
                expires_in: 60,
                expires_at: 't',
            ))->withNativeUrl('https://native/x')
        );
        self::assertSame(
            ['uuid', 'signed_url', 'expires_in', 'expires_at', 'native_url'],
            array_keys($with)
        );
        self::assertSame('https://native/x', $with['native_url']);
    }

    /**
     * Pins BlobInfoData's pass-through: the blob array (incl. an optional native_url)
     * is emitted verbatim, and the private message is NOT leaked into data.
     */
    public function test_blob_info_dto_passes_blob_through_verbatim(): void
    {
        $serializer = new \Glueful\Serialization\ResponseDataSerializer();

        $blob = self::BLOB;
        $blob['native_url'] = 'https://native/report.pdf';

        $out = $serializer->toArray(new \Glueful\Controllers\DTOs\BlobInfoData($blob));

        self::assertSame($blob, $out);
        self::assertArrayNotHasKey('message', $out);
    }

    private function authenticate(Request $request): void
    {
        $request->attributes->set('user', ['uuid' => 'user-1', 'role' => 'admin', 'info' => []]);
        $container = $this->app->getContainer();
        self::assertInstanceOf(Container::class, $container);
        $container->load(['request' => $request]);
    }

    /**
     * Swap BlobRepository + StorageManager for deterministic doubles BEFORE the
     * controller resolves them. The router resolves these constructor args from the
     * container; overriding the definitions makes get() return the doubles.
     */
    private function overrideBlobAndStorage(): void
    {
        $container = $this->app->getContainer();
        self::assertInstanceOf(Container::class, $container);

        $blobs = new class ($this->context) extends BlobRepository {
            public function __construct(ApplicationContext $context)
            {
                parent::__construct(null, $context);
            }

            /** @return array<string, mixed>|null */
            public function findByUuidWithDeleteFilter(string $uuid, bool $includeDeleted = false): ?array
            {
                if ($uuid !== UploadResponseTest::BLOB['uuid']) {
                    return null;
                }

                return UploadResponseTest::BLOB;
            }

            public function updateStatus(string $uuid, string $status): bool
            {
                return true;
            }
        };

        $storage = new class extends StorageManager {
            public function __construct()
            {
                // Skip parent constructor — delete() is the only method reached and
                // it is short-circuited because the test blob path is non-empty but
                // disk()->delete() is stubbed below.
            }

            public function disk(?string $name = null): \League\Flysystem\FilesystemOperator
            {
                return new \League\Flysystem\Filesystem(
                    new \League\Flysystem\InMemory\InMemoryFilesystemAdapter()
                );
            }

            // The parent constructor is skipped, so the typed $drivers property is
            // uninitialized. The native_urls config guard short-circuits before
            // drivers() is reached today; fail LOUD (not with an opaque
            // uninitialized-property Error) if a future change ever reaches it.
            public function drivers(): \Glueful\Storage\Contracts\StorageDriverRegistryInterface
            {
                throw new \LogicException(
                    'StorageManager::drivers() is not expected in this characterization test.'
                );
            }
        };

        $container->load([
            BlobRepository::class => $blobs,
            StorageManager::class => $storage,
        ]);
    }

    private function bootFramework(): void
    {
        $this->appPath = sys_get_temp_dir() . '/glueful-uploadrefadopt-' . uniqid();
        $cfg = $this->appPath . '/config';
        mkdir($cfg, 0755, true);
        file_put_contents($cfg . '/app.php', "<?php\nreturn ['name'=>'T','env'=>'testing','debug'=>true];");
        file_put_contents(
            $cfg . '/database.php',
            "<?php\nreturn ['engine'=>'sqlite','sqlite'=>['primary'=>':memory:'],'pooling'=>['enabled'=>false]];"
        );
        file_put_contents(
            $cfg . '/cache.php',
            "<?php\nreturn ['enabled'=>true,'default'=>'array','stores'=>['array'=>['driver'=>'array']]];"
        );
        file_put_contents($cfg . '/security.php', "<?php\nreturn ['csrf'=>['enabled'=>false]];");
        file_put_contents($cfg . '/session.php', "<?php\nreturn ['jwt_key'=>'test'];");
        // uploads.access=public so info/delete skip the auth gate; signedUrl still
        // requires a user (set via request attribute). native_urls left empty so the
        // native_url key is omitted from every response.
        file_put_contents(
            $cfg . '/uploads.php',
            "<?php\nreturn ['access'=>'public','signed_urls'=>"
            . "['enabled'=>true,'ttl'=>3600,'secret'=>'test-signing-secret-0123456789abcdef']];"
        );

        $this->app = Framework::create($this->appPath)->boot(allowReboot: true);
        $this->context = $this->app->getContainer()->get(ApplicationContext::class);
    }

    private function recursiveRemoveDirectory(string $dir): void
    {
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->recursiveRemoveDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}

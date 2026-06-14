<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support\Documentation;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Controllers\DTOs\BlobDeletedData;
use Glueful\Controllers\DTOs\SignedUrlData;
use Glueful\Controllers\DTOs\UploadResultData;
use Glueful\Controllers\UploadController;
use Glueful\Routing\RouteCache;
use Glueful\Routing\Router;
use Glueful\Support\Documentation\ClassSchemaReflector;
use Glueful\Support\Documentation\RouteReflectionDocGenerator;
use Glueful\Support\Documentation\SecuritySchemeRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Stage 2.3 characterization: the reflect generator, fed the real
 * {@see UploadController} handlers + their new doc attributes/DTOs, must emit an
 * OpenAPI operation for every blob route that is >= the legacy comment-generator
 * spec (routes/blobs.php docblocks).
 *
 * This pins "reflect >= comment" by construction: each docblock's
 * @summary/@description/@tag/@queryParam/@requestBody/@response was transcribed into
 * #[ApiOperation]/#[QueryParam]/#[ApiRequestBody]/#[ApiResponse] (+ doc-only DTOs),
 * and this test asserts the reflect output carries that migrated information.
 *
 * This file exercises the NEW attribute forms introduced for non-JSON I/O:
 *  - upload's MULTIPART request body via #[ApiRequestBody(contentType:
 *    'multipart/form-data', inlineSchema: [...])] — the one allowed inline-array use;
 *  - show's BINARY response via #[ApiResponse(200, contentType:
 *    'application/octet-stream', body: 'binary')] — the constrained escape hatch.
 *
 * The attributes are DOC-ONLY (read by the generator, never by the router/dispatch),
 * so registering the routes here does not exercise — and cannot change — upload
 * runtime. The info/signedUrl/delete success bodies are documented via explicit
 * #[ApiResponse(200, ...Data::class)] because those handlers have UNION return types
 * (`...Data|Response`) that the generator's return-type inference deliberately
 * declines — mirroring ResourceController::destroy()'s explicit-200 pattern.
 *
 * Every doc DTO is pinned with at least one STRUCTURAL property assertion (a
 * concrete property key + type), so a reflector fallback to a bare {type:object}
 * would fail the test.
 *
 * @covers \Glueful\Support\Documentation\RouteReflectionDocGenerator
 */
final class UploadReflectDocsTest extends TestCase
{
    private function makeRouter(): Router
    {
        $context = new ApplicationContext(sys_get_temp_dir() . '/uploadreflect_' . uniqid());

        // Ensure no stale compiled route cache leaks across tests.
        (new RouteCache($context))->clear();

        $container = new class ($context) implements ContainerInterface {
            /** @var array<string, mixed> */
            private array $services;

            public function __construct(ApplicationContext $context)
            {
                $this->services = [ApplicationContext::class => $context];
            }

            public function has(string $id): bool
            {
                return array_key_exists($id, $this->services);
            }

            public function get(string $id): mixed
            {
                if ($this->has($id)) {
                    return $this->services[$id];
                }
                throw new class ("Service '$id' not found")
                    extends \RuntimeException
                    implements \Psr\Container\NotFoundExceptionInterface {
                };
            }
        };

        return new Router($container);
    }

    /**
     * Register the blob routes exactly as routes/blobs.php does (paths + verbs),
     * pointing at the real UploadController handlers.
     */
    private function registerBlobRoutes(Router $router): void
    {
        $router->group(['prefix' => '/blobs'], function (Router $router): void {
            $router->post('', [UploadController::class, 'upload']);
            $router->get('/{uuid}', [UploadController::class, 'show']);
            $router->get('/{uuid}/info', [UploadController::class, 'info']);
            $router->delete('/{uuid}', [UploadController::class, 'delete']);
            $router->post('/{uuid}/signed-url', [UploadController::class, 'signedUrl']);
        });
    }

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function generate(): array
    {
        $router = $this->makeRouter();
        $this->registerBlobRoutes($router);

        // Framework-namespaced routes are included because
        // documentation.sources.include_framework_routes defaults to true.
        $registry = new SecuritySchemeRegistry(
            ['BearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT']],
            ['auth' => ['BearerAuth']],
        );

        return (new RouteReflectionDocGenerator($registry))->generate($router);
    }

    /**
     * Index a route operation's `parameters` by name for ergonomic assertions.
     *
     * @param  array<string, mixed> $op
     * @return array<string, array<string, mixed>>
     */
    private function queryParams(array $op): array
    {
        $params = [];
        foreach (($op['parameters'] ?? []) as $param) {
            if (($param['in'] ?? null) === 'query') {
                $params[$param['name']] = $param;
            }
        }
        return $params;
    }

    public function testUploadOperationCarriesMigratedDocs(): void
    {
        $op = $this->generate()['/blobs']['post'];

        self::assertSame('Upload File', $op['summary']);
        self::assertStringContainsString(
            'Upload a file via multipart form data or base64 encoding.',
            $op['description'],
        );
        self::assertSame(['Blobs'], $op['tags']);

        // NEW FORM: multipart request body via #[ApiRequestBody(inlineSchema)].
        $multipart = $op['requestBody']['content']['multipart/form-data']['schema'];
        self::assertEquals([
            'type' => 'object',
            'properties' => [
                'file' => ['type' => 'string', 'format' => 'binary'],
                'path_prefix' => ['type' => 'string'],
                'visibility' => ['type' => 'string', 'enum' => ['public', 'private']],
            ],
            'required' => ['file'],
        ], $multipart);
        // Structural pins: the binary `file` field + required list.
        self::assertSame('string', $multipart['properties']['file']['type']);
        self::assertSame('binary', $multipart['properties']['file']['format']);
        self::assertContains('file', $multipart['required']);
        // JSON body must NOT exist for an inline multipart-only body.
        self::assertArrayNotHasKey('application/json', $op['requestBody']['content']);

        // 201 success enveloped around the UploadResultData doc DTO.
        self::assertSame('Upload successful', $op['responses']['201']['description']);
        $data = $op['responses']['201']['content']['application/json']['schema']['properties']['data'];
        self::assertEquals(ClassSchemaReflector::toSchema(UploadResultData::class), $data);
        // Structural pins so a bare {type:object} fallback fails the test.
        self::assertSame('object', $data['type']);
        self::assertSame('string', $data['properties']['url']['type']);
        self::assertSame('string', $data['properties']['blob_uuid']['type']);
        self::assertSame('string', $data['properties']['visibility']['type']);
        self::assertSame('integer', $data['properties']['size_bytes']['type']);
        self::assertTrue($data['properties']['thumb_url']['nullable']);

        // Documented error statuses.
        self::assertSame(
            'Missing file upload or invalid base64 data',
            $op['responses']['400']['description'],
        );
        self::assertSame('Authentication required', $op['responses']['401']['description']);
        self::assertSame('File too large', $op['responses']['413']['description']);
        self::assertSame('Unsupported file type', $op['responses']['415']['description']);
        self::assertArrayNotHasKey('content', $op['responses']['400']);
    }

    public function testShowOperationCarriesMigratedDocs(): void
    {
        $op = $this->generate()['/blobs/{uuid}']['get'];

        self::assertSame('Retrieve Blob', $op['summary']);
        self::assertStringContainsString(
            'Retrieve blob file content with optional image resizing.',
            $op['description'],
        );
        self::assertSame(['Blobs'], $op['tags']);

        // NEW FORM: binary response via #[ApiResponse(body: 'binary')].
        self::assertSame(
            'File content with appropriate Content-Type header',
            $op['responses']['200']['description'],
        );
        $binary = $op['responses']['200']['content']['application/octet-stream']['schema'];
        self::assertEquals(['type' => 'string', 'format' => 'binary'], $binary);
        // No JSON envelope for a binary download.
        self::assertArrayNotHasKey('application/json', $op['responses']['200']['content']);

        // Migrated resize @queryParam set: width/height/quality/format/fit.
        $params = $this->queryParams($op);
        self::assertArrayHasKey('width', $params);
        self::assertSame('integer', $params['width']['schema']['type']);
        self::assertArrayHasKey('height', $params);
        self::assertSame('integer', $params['height']['schema']['type']);
        self::assertArrayHasKey('quality', $params);
        self::assertSame('integer', $params['quality']['schema']['type']);
        self::assertArrayHasKey('format', $params);
        self::assertSame('string', $params['format']['schema']['type']);
        self::assertArrayHasKey('fit', $params);
        self::assertSame('string', $params['fit']['schema']['type']);

        // Documented error statuses.
        self::assertSame(
            'Authentication required for private blob',
            $op['responses']['401']['description'],
        );
        self::assertSame('Blob not found', $op['responses']['404']['description']);
    }

    public function testInfoOperationCarriesMigratedDocs(): void
    {
        $op = $this->generate()['/blobs/{uuid}/info']['get'];

        self::assertSame('Blob Metadata', $op['summary']);
        self::assertStringContainsString(
            'Retrieve blob metadata without downloading the file content',
            $op['description'],
        );
        self::assertSame(['Blobs'], $op['tags']);

        // 200 documented via explicit #[ApiResponse(200, BlobInfoData::class)].
        // BlobInfoData is a dynamic pass-through (toArray escape hatch, no public
        // typed properties), so its `data` is a generic open object — `{}` props.
        self::assertSame('Blob metadata retrieved', $op['responses']['200']['description']);
        $data = $op['responses']['200']['content']['application/json']['schema']['properties']['data'];
        self::assertSame('object', $data['type']);
        self::assertEquals(new \stdClass(), $data['properties']);

        self::assertSame('Authentication required', $op['responses']['401']['description']);
        self::assertSame('Blob not found', $op['responses']['404']['description']);
    }

    public function testSignedUrlOperationCarriesMigratedDocs(): void
    {
        $op = $this->generate()['/blobs/{uuid}/signed-url']['post'];

        self::assertSame('Generate Signed URL', $op['summary']);
        self::assertStringContainsString(
            'Generate a temporary signed URL for accessing a private blob.',
            $op['description'],
        );
        self::assertSame(['Blobs'], $op['tags']);

        // Migrated @queryParam ttl.
        $params = $this->queryParams($op);
        self::assertArrayHasKey('ttl', $params);
        self::assertSame('integer', $params['ttl']['schema']['type']);
        self::assertStringContainsString('URL lifetime in seconds', $params['ttl']['description']);

        // 200 documented via explicit #[ApiResponse(200, SignedUrlData::class)].
        self::assertSame('Signed URL generated', $op['responses']['200']['description']);
        $data = $op['responses']['200']['content']['application/json']['schema']['properties']['data'];
        self::assertEquals(ClassSchemaReflector::toSchema(SignedUrlData::class), $data);
        // Structural pins.
        self::assertSame('string', $data['properties']['signed_url']['type']);
        self::assertSame('integer', $data['properties']['expires_in']['type']);
        self::assertSame('string', $data['properties']['expires_at']['type']);

        // Documented error statuses.
        self::assertSame('Signed URLs are disabled', $op['responses']['400']['description']);
        self::assertSame('Authentication required', $op['responses']['401']['description']);
        self::assertSame('Blob not found', $op['responses']['404']['description']);
    }

    public function testDeleteOperationCarriesMigratedDocs(): void
    {
        $op = $this->generate()['/blobs/{uuid}']['delete'];

        self::assertSame('Delete Blob', $op['summary']);
        self::assertStringContainsString(
            'Soft-delete a blob and remove its underlying file from storage',
            $op['description'],
        );
        self::assertSame(['Blobs'], $op['tags']);

        // 200 documented via explicit #[ApiResponse(200, BlobDeletedData::class)].
        self::assertSame('Blob deleted', $op['responses']['200']['description']);
        $data = $op['responses']['200']['content']['application/json']['schema']['properties']['data'];
        self::assertEquals(ClassSchemaReflector::toSchema(BlobDeletedData::class), $data);
        // Structural pin.
        self::assertSame('string', $data['properties']['uuid']['type']);

        self::assertSame('Authentication required', $op['responses']['401']['description']);
        self::assertSame('Blob not found', $op['responses']['404']['description']);
    }
}

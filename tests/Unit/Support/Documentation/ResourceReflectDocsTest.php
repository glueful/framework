<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support\Documentation;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Controllers\DTOs\BulkOperationResultData;
use Glueful\Controllers\DTOs\BulkUpdateData;
use Glueful\Controllers\DTOs\ResourceCreateData;
use Glueful\Controllers\DTOs\ResourceCreatedData;
use Glueful\Controllers\DTOs\ResourceDeletedData;
use Glueful\Controllers\DTOs\ResourceRecordData;
use Glueful\Controllers\DTOs\ResourceUpdateData;
use Glueful\Controllers\DTOs\ResourceUpdatedData;
use Glueful\Controllers\ResourceController;
use Glueful\Routing\Router;
use Glueful\Routing\RouteCache;
use Glueful\Support\Documentation\ClassSchemaReflector;
use Glueful\Support\Documentation\RouteReflectionDocGenerator;
use Glueful\Support\Documentation\SecuritySchemeRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Stage 2.2 characterization: the reflect generator, fed the real
 * {@see ResourceController} handlers + their new doc attributes/DTOs, must emit an
 * OpenAPI operation for every resource route that is >= the legacy comment-generator
 * spec (routes/resource.php docblocks).
 *
 * This pins "reflect >= comment" by construction: each docblock's
 * @summary/@description/@tag/@queryParam/@requestBody/@response was transcribed into
 * #[ApiOperation]/#[QueryParam]/#[ApiRequestBody]/#[ApiResponse] (+ doc-only DTOs),
 * and this test asserts the reflect output carries that migrated information.
 *
 * The attributes are DOC-ONLY (read by the generator, never by the router/dispatch),
 * so registering the routes here does not exercise — and cannot change — resource
 * runtime. The polymorphic write bodies are documented via #[ApiRequestBody(schema:
 * ...Data::class)] (no runtime hydration), and the dynamic-column records via a
 * generic `array<string,mixed>` DTO — never a fabricated fixed column set.
 *
 * Every doc DTO is pinned with at least one STRUCTURAL property assertion (a
 * concrete property key + type), so a reflector fallback to a bare {type:object}
 * would fail the test.
 *
 * @covers \Glueful\Support\Documentation\RouteReflectionDocGenerator
 */
final class ResourceReflectDocsTest extends TestCase
{
    private function makeRouter(): Router
    {
        $context = new ApplicationContext(sys_get_temp_dir() . '/resourcereflect_' . uniqid());

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
     * Register the resource routes exactly as routes/resource.php does (paths +
     * middleware), pointing at the real ResourceController handlers. The bulk
     * routes are config-gated in the route file; here they are registered
     * directly so their migrated docs are exercised.
     */
    private function registerResourceRoutes(Router $router): void
    {
        $router->group(['prefix' => '/data'], function (Router $router): void {
            $router->get('/{table}', [ResourceController::class, 'index'])
                ->setFieldsConfig([
                    'strict' => false,
                    'maxDepth' => 6,
                    'maxFields' => 200,
                    'maxItems' => 1000,
                ])
                ->middleware(['auth', 'field_selection', 'rate_limit:100,60']);

            $router->get('/{table}/{uuid}', [ResourceController::class, 'show'])
                ->setFieldsConfig([
                    'strict' => false,
                    'maxDepth' => 6,
                    'maxFields' => 200,
                    'maxItems' => 1000,
                ])
                ->middleware(['auth', 'field_selection', 'rate_limit:200,60']);

            $router->post('/{table}', [ResourceController::class, 'store'])
                ->middleware(['auth', 'rate_limit:50,60']);

            $router->put('/{table}/{uuid}', [ResourceController::class, 'update'])
                ->middleware(['auth', 'rate_limit:30,60']);

            $router->delete('/{table}/{uuid}', [ResourceController::class, 'destroy'])
                ->middleware(['auth', 'rate_limit:20,60']);

            // Bulk routes (config-gated in routes/resource.php; registered directly here).
            $router->delete('/{table}/bulk', [ResourceController::class, 'destroyBulk'])
                ->middleware(['auth', 'rate_limit:5,60']);

            $router->put('/{table}/bulk', [ResourceController::class, 'updateBulk'])
                ->middleware(['auth', 'rate_limit:10,60']);
        });
    }

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function generate(): array
    {
        $router = $this->makeRouter();
        $this->registerResourceRoutes($router);

        // Framework-namespaced routes (Glueful\Controllers\ResourceController) are
        // included because documentation.sources.include_framework_routes defaults to true.
        $registry = new SecuritySchemeRegistry(
            ['BearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT']],
            ['auth' => ['BearerAuth']],
        );

        return (new RouteReflectionDocGenerator($registry))->generate($router);
    }

    public function testIndexOperationCarriesMigratedDocs(): void
    {
        $op = $this->generate()['/data/{table}']['get'];

        self::assertSame('List Resources', $op['summary']);
        self::assertStringContainsString(
            'Retrieves a paginated list of resources from the specified table',
            $op['description'],
        );
        self::assertSame(['Data'], $op['tags']);

        // Migrated @queryParam set: page, per_page, sort, order (+ field_selection's
        // fields/expand from setFieldsConfig). Assert each documented query param.
        $queryParams = [];
        foreach ($op['parameters'] as $param) {
            if (($param['in'] ?? '') === 'query') {
                $queryParams[$param['name']] = $param;
            }
        }

        self::assertArrayHasKey('page', $queryParams);
        self::assertSame('integer', $queryParams['page']['schema']['type']);
        self::assertArrayHasKey('per_page', $queryParams);
        self::assertSame('integer', $queryParams['per_page']['schema']['type']);
        self::assertArrayHasKey('sort', $queryParams);
        self::assertSame('string', $queryParams['sort']['schema']['type']);
        self::assertArrayHasKey('order', $queryParams);
        self::assertSame(['asc', 'desc'], $queryParams['order']['schema']['enum']);

        // 200 list shape: envelope around an array of generic record objects.
        self::assertSame('Resources retrieved successfully', $op['responses']['200']['description']);
        $listSchema = $op['responses']['200']['content']['application/json']['schema'];
        self::assertSame('object', $listSchema['type']);
        self::assertSame('array', $listSchema['properties']['data']['type']);
        self::assertEquals(
            ClassSchemaReflector::toSchema(ResourceRecordData::class),
            $listSchema['properties']['data']['items'],
        );
        // Structural pin: the record DTO documents a generic attributes map (array).
        self::assertSame('array', $listSchema['properties']['data']['items']['properties']['attributes']['type']);

        self::assertSame('Insufficient permissions for resource access', $op['responses']['403']['description']);
        self::assertSame('Resource table not found', $op['responses']['404']['description']);
    }

    public function testShowOperationCarriesMigratedDocs(): void
    {
        $op = $this->generate()['/data/{table}/{uuid}']['get'];

        self::assertSame('Get Single Resource', $op['summary']);
        self::assertStringContainsString('Retrieves a single resource by its UUID', $op['description']);
        self::assertSame(['Data'], $op['tags']);

        self::assertSame('Resource retrieved successfully', $op['responses']['200']['description']);
        $data = $op['responses']['200']['content']['application/json']['schema']['properties']['data'];
        self::assertEquals(ClassSchemaReflector::toSchema(ResourceRecordData::class), $data);
        self::assertSame('array', $data['properties']['attributes']['type']);

        self::assertSame('Resource not found', $op['responses']['404']['description']);
        self::assertSame('Insufficient permissions', $op['responses']['403']['description']);
    }

    public function testStoreOperationCarriesMigratedDocs(): void
    {
        $op = $this->generate()['/data/{table}']['post'];

        self::assertSame('Create Resource', $op['summary']);
        self::assertStringContainsString('Creates a new resource in the specified table', $op['description']);
        self::assertSame(['Data'], $op['tags']);

        // Request body: doc-only polymorphic write DTO (store stays manual at runtime).
        $jsonSchema = $op['requestBody']['content']['application/json']['schema'];
        self::assertEquals(ClassSchemaReflector::toSchema(ResourceCreateData::class), $jsonSchema);
        // Structural pin: a generic `data` map, NOT a fallback {type:object}.
        self::assertSame('object', $jsonSchema['type']);
        self::assertSame('array', $jsonSchema['properties']['data']['type']);

        // 200 success enveloped around ResourceCreatedData.
        self::assertSame('Resource created successfully', $op['responses']['200']['description']);
        $data = $op['responses']['200']['content']['application/json']['schema']['properties']['data'];
        self::assertEquals(ClassSchemaReflector::toSchema(ResourceCreatedData::class), $data);
        self::assertSame('string', $data['properties']['uuid']['type']);
        self::assertSame('boolean', $data['properties']['success']['type']);

        self::assertSame('Invalid input data', $op['responses']['400']['description']);
        self::assertSame('Insufficient permissions', $op['responses']['403']['description']);
    }

    public function testUpdateOperationCarriesMigratedDocs(): void
    {
        $op = $this->generate()['/data/{table}/{uuid}']['put'];

        self::assertSame('Update Resource', $op['summary']);
        self::assertStringContainsString('Updates an existing resource by UUID', $op['description']);
        self::assertSame(['Data'], $op['tags']);

        $jsonSchema = $op['requestBody']['content']['application/json']['schema'];
        self::assertEquals(ClassSchemaReflector::toSchema(ResourceUpdateData::class), $jsonSchema);
        self::assertSame('object', $jsonSchema['type']);
        self::assertSame('array', $jsonSchema['properties']['data']['type']);

        self::assertSame('Resource updated successfully', $op['responses']['200']['description']);
        $data = $op['responses']['200']['content']['application/json']['schema']['properties']['data'];
        self::assertEquals(ClassSchemaReflector::toSchema(ResourceUpdatedData::class), $data);
        self::assertSame('integer', $data['properties']['affected']['type']);

        self::assertSame('Resource not found', $op['responses']['404']['description']);
        self::assertSame('Invalid input data', $op['responses']['400']['description']);
        self::assertSame('Insufficient permissions', $op['responses']['403']['description']);
    }

    public function testDestroyOperationCarriesMigratedDocs(): void
    {
        $op = $this->generate()['/data/{table}/{uuid}']['delete'];

        self::assertSame('Delete Resource', $op['summary']);
        self::assertStringContainsString('Deletes a resource by UUID', $op['description']);
        self::assertSame(['Data'], $op['tags']);

        // 200 enveloped around ResourceDeletedData (Phase D response DTO).
        self::assertSame('Resource deleted successfully', $op['responses']['200']['description']);
        $data = $op['responses']['200']['content']['application/json']['schema']['properties']['data'];
        self::assertEquals(ClassSchemaReflector::toSchema(ResourceDeletedData::class), $data);
        self::assertSame('integer', $data['properties']['affected']['type']);
        self::assertSame('boolean', $data['properties']['success']['type']);

        self::assertSame('Resource not found', $op['responses']['404']['description']);
        self::assertSame('Insufficient permissions', $op['responses']['403']['description']);
    }

    public function testBulkDeleteOperationCarriesMigratedDocs(): void
    {
        $op = $this->generate()['/data/{table}/bulk']['delete'];

        self::assertSame('Bulk Delete Resources', $op['summary']);
        self::assertStringContainsString('Deletes multiple resources by UUIDs', $op['description']);
        self::assertSame(['Data'], $op['tags']);

        // The legacy @requestBody (uuids[]) rides a DELETE verb; the reflect
        // generator only emits a requestBody for POST/PUT/PATCH, so none is present
        // here. This is a reflect/OpenAPI DELETE-body limitation, not a prose gap.
        self::assertArrayNotHasKey('requestBody', $op);

        self::assertSame('Resources deleted successfully', $op['responses']['200']['description']);
        $data = $op['responses']['200']['content']['application/json']['schema']['properties']['data'];
        self::assertEquals(ClassSchemaReflector::toSchema(BulkOperationResultData::class), $data);
        self::assertSame('integer', $data['properties']['total_requested']['type']);

        self::assertSame('Invalid input data', $op['responses']['400']['description']);
        self::assertSame(
            'Insufficient permissions or bulk operations disabled',
            $op['responses']['403']['description'],
        );
    }

    public function testBulkUpdateOperationCarriesMigratedDocs(): void
    {
        $op = $this->generate()['/data/{table}/bulk']['put'];

        self::assertSame('Bulk Update Resources', $op['summary']);
        self::assertStringContainsString('Updates multiple resources with provided data', $op['description']);
        self::assertSame(['Data'], $op['tags']);

        $jsonSchema = $op['requestBody']['content']['application/json']['schema'];
        self::assertEquals(ClassSchemaReflector::toSchema(BulkUpdateData::class), $jsonSchema);
        self::assertSame('object', $jsonSchema['type']);
        self::assertSame('array', $jsonSchema['properties']['updates']['type']);

        self::assertSame('Resources updated successfully', $op['responses']['200']['description']);
        $data = $op['responses']['200']['content']['application/json']['schema']['properties']['data'];
        self::assertEquals(ClassSchemaReflector::toSchema(BulkOperationResultData::class), $data);
        self::assertSame('integer', $data['properties']['total_requested']['type']);

        self::assertSame('Invalid input data', $op['responses']['400']['description']);
        self::assertSame(
            'Insufficient permissions or bulk operations disabled',
            $op['responses']['403']['description'],
        );
    }
}

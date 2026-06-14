<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support\Documentation;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Controllers\HealthController;
use Glueful\Controllers\DTOs\DatabaseHealthData;
use Glueful\Controllers\DTOs\CacheHealthData;
use Glueful\Controllers\DTOs\ReadinessData;
use Glueful\Controllers\DTOs\LivenessData;
use Glueful\Controllers\DTOs\StartupData;
use Glueful\Controllers\DTOs\QueueHealthData;
use Glueful\Routing\RouteCache;
use Glueful\Routing\Router;
use Glueful\Support\Documentation\ClassSchemaReflector;
use Glueful\Support\Documentation\RouteReflectionDocGenerator;
use Glueful\Support\Documentation\SecuritySchemeRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Stage 2.4 characterization: the reflect generator, fed the real
 * {@see HealthController} handlers + their new doc attributes/DTOs, must emit an
 * OpenAPI operation for every health route that is >= the legacy comment-generator
 * spec (routes/health.php docblocks).
 *
 * This pins "reflect >= comment" by construction: each docblock's
 * @summary/@description/@tag/@response was transcribed into
 * #[ApiOperation]/#[ApiResponse] (+ doc-only DTOs), and this test asserts the
 * reflect output carries that migrated information.
 *
 * The attributes are DOC-ONLY (read by the generator, never by the router/dispatch),
 * so registering the routes here does not exercise — and cannot change — health runtime.
 *
 * Key nuances pinned here:
 *  - liveness / startup: bare probes (new Response(['status' => ...])) →
 *    envelope:false → the schema is the bare DTO object, NOT the {success,message,data}
 *    wrapper; tests assert absence of 'data'/'success' keys at the top level.
 *  - readiness: UNION return (ReadinessData|Response) → explicit #[ApiResponse(200,
 *    ReadinessData::class)] + #[ApiResponse(503)]; the 200 IS envelope-wrapped (the
 *    DTO flows through the router's ResponseData envelope path at runtime).
 *  - database/cache/queue: enveloped {success,message,data}; structural DTO properties
 *    are pinned so a bare {type:object} fallback would fail.
 *  - detailed/middleware/responseApi: free-form metric blobs documented with description-
 *    only or generic-object responses (propertyless DTO → `properties: {}`).
 *  - index (GET /health): large multi-section blob documented as description+503.
 *
 * @covers \Glueful\Support\Documentation\RouteReflectionDocGenerator
 */
final class HealthReflectDocsTest extends TestCase
{
    private function makeRouter(): Router
    {
        $context = new ApplicationContext(sys_get_temp_dir() . '/healthreflect_' . uniqid());

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
     * Register the health routes exactly as routes/health.php does (paths + middleware),
     * pointing at the real HealthController handlers.
     *
     * Both the group-nested paths (/health/*) and the top-level aliases
     * (/healthz and /ready) are registered to exercise every route-wired method.
     */
    private function registerHealthRoutes(Router $router): void
    {
        $router->group(['prefix' => '/health'], function (Router $router): void {
            $router->get('/', [HealthController::class, 'index'])
                ->middleware('rate_limit:60,60');
            $router->get('/database', [HealthController::class, 'database'])
                ->middleware('rate_limit:30,60');
            $router->get('/cache', [HealthController::class, 'cache'])
                ->middleware('rate_limit:30,60');
            $router->get('/detailed', [HealthController::class, 'detailed'])
                ->middleware(['auth', 'rate_limit:10,60']);
            $router->get('/middleware', [HealthController::class, 'middleware'])
                ->middleware(['auth', 'rate_limit:20,60']);
            $router->get('/response-api', [HealthController::class, 'responseApi'])
                ->middleware(['auth', 'rate_limit:15,60']);
            $router->get('/queue', [HealthController::class, 'queue'])
                ->middleware('rate_limit:20,60');
            $router->get('/live', [HealthController::class, 'liveness'])
                ->middleware('rate_limit:60,60');
            $router->get('/ready', [HealthController::class, 'readiness'])
                ->middleware('rate_limit:30,60');
            $router->get('/startup', [HealthController::class, 'startup'])
                ->middleware('rate_limit:60,60');
        });

        $router->get('/healthz', [HealthController::class, 'liveness'])
            ->middleware('rate_limit:60,60');
        $router->get('/ready', [HealthController::class, 'readiness'])
            ->middleware(['rate_limit:30,60', 'allow_ip']);
    }

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function generate(): array
    {
        $router = $this->makeRouter();
        $this->registerHealthRoutes($router);

        $registry = new SecuritySchemeRegistry(
            ['BearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT']],
            ['auth' => ['BearerAuth']],
        );

        return (new RouteReflectionDocGenerator($registry))->generate($router);
    }

    // -------------------------------------------------------------------------
    // Index (GET /health)
    // -------------------------------------------------------------------------

    public function testIndexOperationCarriesMigratedDocs(): void
    {
        $op = $this->generate()['/health']['get'];

        self::assertSame('System Health Check', $op['summary']);
        self::assertStringContainsString('overall system health', $op['description']);
        self::assertSame(['Health'], $op['tags']);

        // 200 enveloped response is documented
        self::assertArrayHasKey('200', $op['responses']);
        self::assertArrayHasKey('content', $op['responses']['200']);

        // 503 path is documented
        self::assertArrayHasKey('503', $op['responses']);
    }

    // -------------------------------------------------------------------------
    // Liveness probe (GET /health/live + GET /healthz)
    // -------------------------------------------------------------------------

    public function testLivenessOperationCarriesMigratedDocs(): void
    {
        $op = $this->generate()['/health/live']['get'];

        self::assertSame('Liveness Probe (k8s convention)', $op['summary']);
        self::assertStringContainsString('alive', $op['description']);
        self::assertSame(['Health'], $op['tags']);

        // Bare probe: envelope:false — schema must NOT have a wrapping 'data' key.
        $schema = $op['responses']['200']['content']['application/json']['schema'];
        self::assertSame('object', $schema['type']);
        self::assertArrayNotHasKey('data', $schema['properties'] ?? []);
        self::assertArrayNotHasKey('success', $schema['properties'] ?? []);

        // The bare DTO's 'status' property is directly on the schema.
        self::assertArrayHasKey('status', $schema['properties']);
        self::assertSame('string', $schema['properties']['status']['type']);

        // Verify it equals the bare DTO schema (not wrapped).
        self::assertEquals(ClassSchemaReflector::toSchema(LivenessData::class), $schema);
    }

    public function testHealthzAliasOperationCarriesMigratedDocs(): void
    {
        $op = $this->generate()['/healthz']['get'];

        // /healthz calls the same liveness handler — shares the handler's attributes,
        // so the summary and tags come from the method's #[ApiOperation].
        self::assertSame('Liveness Probe (k8s convention)', $op['summary']);
        self::assertStringContainsString('alive', $op['description']);
        self::assertSame(['Health'], $op['tags']);

        // Also bare probe (envelope:false).
        $schema = $op['responses']['200']['content']['application/json']['schema'];
        self::assertArrayNotHasKey('data', $schema['properties'] ?? []);
        self::assertArrayHasKey('status', $schema['properties']);
    }

    // -------------------------------------------------------------------------
    // Startup probe (GET /health/startup)
    // -------------------------------------------------------------------------

    public function testStartupOperationCarriesMigratedDocs(): void
    {
        $op = $this->generate()['/health/startup']['get'];

        self::assertSame('Startup Probe (k8s convention)', $op['summary']);
        self::assertStringContainsString('startup', $op['description']);
        self::assertSame(['Health'], $op['tags']);

        // Bare probe: envelope:false — schema is bare StartupData, not wrapped.
        $schema = $op['responses']['200']['content']['application/json']['schema'];
        self::assertSame('object', $schema['type']);
        self::assertArrayNotHasKey('data', $schema['properties'] ?? []);
        self::assertArrayNotHasKey('success', $schema['properties'] ?? []);

        self::assertArrayHasKey('status', $schema['properties']);
        self::assertSame('string', $schema['properties']['status']['type']);

        self::assertEquals(ClassSchemaReflector::toSchema(StartupData::class), $schema);
    }

    // -------------------------------------------------------------------------
    // Readiness probe (GET /health/ready)
    // -------------------------------------------------------------------------

    public function testReadinessOperationCarriesMigratedDocs(): void
    {
        $op = $this->generate()['/health/ready']['get'];

        self::assertSame('Readiness Probe (k8s convention)', $op['summary']);
        self::assertStringContainsString('ready', $op['description']);
        self::assertSame(['Health'], $op['tags']);

        // 200: enveloped ReadinessData (flows through ResponseData envelope at runtime).
        self::assertSame('Service is ready', $op['responses']['200']['description']);
        $data = $op['responses']['200']['content']['application/json']['schema']['properties']['data'];
        self::assertEquals(ClassSchemaReflector::toSchema(ReadinessData::class), $data);

        // ReadinessData structural pins (so a bare {type:object} fallback fails).
        self::assertSame('string', $data['properties']['status']['type']);
        self::assertSame('string', $data['properties']['timestamp']['type']);
        self::assertSame('array', $data['properties']['checks']['type']);

        // 503: service-not-ready path is documented.
        self::assertArrayHasKey('503', $op['responses']);
        self::assertSame('Service not ready', $op['responses']['503']['description']);
    }

    public function testTopLevelReadyOperationCarriesMigratedDocs(): void
    {
        $op = $this->generate()['/ready']['get'];

        // /ready calls the same readiness handler — shares the handler's #[ApiOperation].
        self::assertSame('Readiness Probe (k8s convention)', $op['summary']);
        self::assertStringContainsString('ready', $op['description']);
        self::assertSame(['Health'], $op['tags']);

        // 200 enveloped, 503 documented.
        self::assertArrayHasKey('200', $op['responses']);
        self::assertArrayHasKey('503', $op['responses']);
    }

    // -------------------------------------------------------------------------
    // Database health (GET /health/database)
    // -------------------------------------------------------------------------

    public function testDatabaseOperationCarriesMigratedDocs(): void
    {
        $op = $this->generate()['/health/database']['get'];

        self::assertSame('Database Health Check', $op['summary']);
        self::assertStringContainsString('database', strtolower($op['description']));
        self::assertSame(['Health'], $op['tags']);

        // 200 enveloped with DatabaseHealthData.
        self::assertSame('Database is healthy', $op['responses']['200']['description']);
        $data = $op['responses']['200']['content']['application/json']['schema']['properties']['data'];
        self::assertEquals(ClassSchemaReflector::toSchema(DatabaseHealthData::class), $data);

        // Structural pins.
        self::assertSame('string', $data['properties']['status']['type']);
        self::assertSame('string', $data['properties']['driver']['type']);
        self::assertSame('integer', $data['properties']['migrations_applied']['type']);
        self::assertSame('boolean', $data['properties']['connectivity_test']['type']);

        // 503 error path.
        self::assertArrayHasKey('503', $op['responses']);
        self::assertSame('Database is unhealthy', $op['responses']['503']['description']);
    }

    // -------------------------------------------------------------------------
    // Cache health (GET /health/cache)
    // -------------------------------------------------------------------------

    public function testCacheOperationCarriesMigratedDocs(): void
    {
        $op = $this->generate()['/health/cache']['get'];

        self::assertSame('Cache Health Check', $op['summary']);
        self::assertStringContainsString('cache', strtolower($op['description']));
        self::assertSame(['Health'], $op['tags']);

        // 200 enveloped with CacheHealthData.
        self::assertSame('Cache is healthy', $op['responses']['200']['description']);
        $data = $op['responses']['200']['content']['application/json']['schema']['properties']['data'];
        self::assertEquals(ClassSchemaReflector::toSchema(CacheHealthData::class), $data);

        // Structural pins.
        self::assertSame('string', $data['properties']['status']['type']);
        self::assertSame('string', $data['properties']['driver']['type']);
        self::assertSame('string', $data['properties']['operations']['type']);

        // 503 error path.
        self::assertArrayHasKey('503', $op['responses']);
        self::assertSame('Cache is unhealthy', $op['responses']['503']['description']);
    }

    // -------------------------------------------------------------------------
    // Queue health (GET /health/queue)
    // -------------------------------------------------------------------------

    public function testQueueOperationCarriesMigratedDocs(): void
    {
        $op = $this->generate()['/health/queue']['get'];

        self::assertSame('Queue Health', $op['summary']);
        self::assertStringContainsString('queue', strtolower($op['description']));
        self::assertSame(['Health'], $op['tags']);

        // 200 enveloped with QueueHealthData.
        self::assertArrayHasKey('200', $op['responses']);
        $data = $op['responses']['200']['content']['application/json']['schema']['properties']['data'];
        self::assertEquals(ClassSchemaReflector::toSchema(QueueHealthData::class), $data);

        // Structural pin: 'status' must be a string on the DTO.
        self::assertSame('string', $data['properties']['status']['type']);
    }

    // -------------------------------------------------------------------------
    // Detailed health (GET /health/detailed) — free-form blob
    // -------------------------------------------------------------------------

    public function testDetailedOperationCarriesMigratedDocs(): void
    {
        $op = $this->generate()['/health/detailed']['get'];

        self::assertSame('Detailed Health Metrics', $op['summary']);
        self::assertStringContainsString('comprehensive', strtolower($op['description']));
        self::assertSame(['Health'], $op['tags']);

        // 200 is documented (shape may be a generic object for free-form blob).
        self::assertArrayHasKey('200', $op['responses']);

        // 403 and 503 are documented.
        self::assertArrayHasKey('403', $op['responses']);
        self::assertArrayHasKey('503', $op['responses']);
    }

    // -------------------------------------------------------------------------
    // Middleware health (GET /health/middleware) — free-form blob
    // -------------------------------------------------------------------------

    public function testMiddlewareOperationCarriesMigratedDocs(): void
    {
        $op = $this->generate()['/health/middleware']['get'];

        self::assertSame('Middleware Pipeline Health', $op['summary']);
        self::assertStringContainsString('middleware', strtolower($op['description']));
        self::assertSame(['Health'], $op['tags']);

        // 200 documented.
        self::assertArrayHasKey('200', $op['responses']);

        // 403 and 503 documented.
        self::assertArrayHasKey('403', $op['responses']);
        self::assertArrayHasKey('503', $op['responses']);
    }

    // -------------------------------------------------------------------------
    // Response API health (GET /health/response-api) — free-form blob
    // -------------------------------------------------------------------------

    public function testResponseApiOperationCarriesMigratedDocs(): void
    {
        $op = $this->generate()['/health/response-api']['get'];

        self::assertSame('Response API Health', $op['summary']);
        self::assertStringContainsString('response api', strtolower($op['description']));
        self::assertSame(['Health'], $op['tags']);

        // 200 documented.
        self::assertArrayHasKey('200', $op['responses']);

        // 403 and 503 documented.
        self::assertArrayHasKey('403', $op['responses']);
        self::assertArrayHasKey('503', $op['responses']);
    }
}

<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\ReferenceAdoption;

use Glueful\Application;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Container;
use Glueful\Controllers\HealthController;
use Glueful\Framework;
use Glueful\Helpers\Utils;
use Glueful\Routing\RouteManifest;
use Glueful\Routing\Router;
use Glueful\Services\HealthService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Characterization + reference-adoption guard for {@see HealthController} (the
 * phased "adopt typed DTOs as a reference example" work).
 *
 * What this pins — success (200) path only:
 *  - readiness GET /health/ready    → MIGRATED to a typed ReadinessData; body byte-identical.
 *  - database  GET /health/database → intentionally NOT migrated (it sets Cache-Control via
 *    privateCached(), which the DTO return path can't carry); this guards it stays a cached
 *    Response with the header intact.
 *
 * Determinism: {@see HealthService} memoizes its database check in a private
 * static `$healthCache` (5s TTL) that the check consults BEFORE touching the DB.
 * We seed that cache with a fixed `status: ok` result via reflection so the
 * database check is stable across dispatches WITHOUT a live SQLite connection
 * (an in-memory `:memory:` DB is per-connection, so a seeded table would not be
 * visible to HealthService's own connection). The cache check (array driver) and
 * config check (valid JWT_KEY/APP_KEY + .env present) return `ok` live and are
 * therefore stable too.
 *
 * The only irreducibly VOLATILE field is the readiness `timestamp` (date('c'),
 * minted per request). There is no time seam on the controller, so the readiness
 * body is asserted MINUS `timestamp` for byte-equality, with a separate type +
 * ISO-8601 format assertion on `timestamp` itself. The DTO passes the
 * controller's already-computed `timestamp` through UNCHANGED (typed `string`),
 * so within a single dispatch the value is identical pre/post migration.
 */
final class HealthDtoTest extends TestCase
{
    private string $appPath;
    private Application $app;
    private ApplicationContext $context;
    private Router $router;

    /** The fixed database-check result the seeded HealthService cache returns. */
    private const DB_OK = [
        'status'             => 'ok',
        'message'            => 'Database connection and QueryBuilder operational',
        'driver'             => 'sqlite',
        'database'           => 'SQLite (file-based)',
        'migrations_applied' => 5,
        'connectivity_test'  => true,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        RouteManifest::reset();
        $this->bootFramework();
        Utils::setContext($this->context);
        $this->seedDatabaseCheck();

        $this->router = $this->app->getContainer()->get(Router::class);
        $this->router->get('/test/health/database', [HealthController::class, 'database']);
        $this->router->get('/test/health/ready', [HealthController::class, 'readiness']);
    }

    protected function tearDown(): void
    {
        $this->resetHealthCache();
        Utils::setContext(null);
        if (isset($this->appPath) && is_dir($this->appPath)) {
            $this->recursiveRemoveDirectory($this->appPath);
        }
        parent::tearDown();
    }

    public function test_database_stays_manual_and_retains_cache_headers(): void
    {
        // `database` is intentionally NOT migrated to a typed ResponseData: it wraps
        // its success in privateCached() to set Cache-Control headers, which the DTO
        // return path cannot carry. This test guards that it stays a cached Response
        // (body unchanged AND the Cache-Control header preserved).
        $response = $this->router->dispatch(Request::create('/test/health/database', 'GET'));

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true);
        self::assertSame([
            'success' => true,
            'message' => 'Database health check completed',
            'data'    => self::DB_OK,
        ], $body);

        // The privateCached() wrapper must still attach Cache-Control (the regression
        // a DTO migration would have silently dropped).
        $cacheControl = $response->headers->get('Cache-Control');
        self::assertNotNull($cacheControl);
        self::assertStringContainsString('private', (string) $cacheControl);
        self::assertStringContainsString('max-age=30', (string) $cacheControl);
    }

    public function test_readiness_returns_byte_identical_success_envelope_minus_timestamp(): void
    {
        $response = $this->router->dispatch(Request::create('/test/health/ready', 'GET'));

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true);

        self::assertTrue($body['success']);
        self::assertSame('Service is ready', $body['message']);

        // Exact key set + order of data: status, timestamp, checks.
        self::assertSame(['status', 'timestamp', 'checks'], array_keys($body['data']));
        self::assertSame('ready', $body['data']['status']);

        // timestamp is volatile (date('c')) — assert type + ISO-8601 shape only.
        self::assertIsString($body['data']['timestamp']);
        self::assertNotFalse(
            \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $body['data']['timestamp']),
            'readiness timestamp must be ISO-8601 (date(\'c\'))'
        );

        // The remaining body (minus the volatile timestamp) is byte-identical.
        $stable = $body;
        unset($stable['data']['timestamp']);
        self::assertSame([
            'success' => true,
            'message' => 'Service is ready',
            'data'    => [
                'status' => 'ready',
                'checks' => [
                    'database' => self::DB_OK,
                    'cache'    => [
                        'status'     => 'ok',
                        'message'    => 'Cache is working properly',
                        'driver'     => 'array',
                        'operations' => 'read/write/delete functional',
                    ],
                    'config' => [
                        'status'      => 'ok',
                        'message'     => 'Configuration is valid',
                        'environment' => 'testing',
                    ],
                ],
            ],
        ], $stable);
    }

    /**
     * Seed the private static `$healthCache['database']` so the database check
     * returns a fixed `ok` result deterministically (no live DB dependency).
     */
    private function seedDatabaseCheck(): void
    {
        $prop = (new \ReflectionClass(HealthService::class))->getProperty('healthCache');
        $prop->setAccessible(true);
        $prop->setValue(null, [
            'database' => ['result' => self::DB_OK, 'timestamp' => microtime(true)],
        ]);
    }

    private function resetHealthCache(): void
    {
        $ref = new \ReflectionClass(HealthService::class);
        $cache = $ref->getProperty('healthCache');
        $cache->setAccessible(true);
        $cache->setValue(null, []);
        $instance = $ref->getProperty('instance');
        $instance->setAccessible(true);
        $instance->setValue(null, null);
    }

    private function bootFramework(): void
    {
        $this->appPath = sys_get_temp_dir() . '/glueful-healthrefadopt-' . uniqid();
        $cfg = $this->appPath . '/config';
        mkdir($cfg, 0755, true);
        file_put_contents(
            $cfg . '/app.php',
            "<?php\nreturn ['name'=>'T','env'=>'testing','debug'=>true,'version'=>'1.0.0'];"
        );
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
        // .env present + valid JWT_KEY/APP_KEY so the config check returns 'ok'.
        file_put_contents($this->appPath . '/.env', "APP_ENV=testing\n");
        putenv('JWT_KEY=some-secure-jwt-key-value-1234567890');
        putenv('APP_KEY=base64:' . base64_encode(str_repeat('a', 32)));
        $_ENV['JWT_KEY'] = getenv('JWT_KEY');
        $_ENV['APP_KEY'] = getenv('APP_KEY');

        $this->app = Framework::create($this->appPath)->boot(allowReboot: true);
        $container = $this->app->getContainer();
        self::assertInstanceOf(Container::class, $container);
        $this->context = $container->get(ApplicationContext::class);
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

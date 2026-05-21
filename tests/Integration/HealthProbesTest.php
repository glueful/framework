<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration;

use Glueful\Application;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Framework;
use Glueful\Routing\RouteManifest;
use Glueful\Routing\Router;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Verifies the k8s-conventional health probe routes are wired and respond
 * with the expected shapes.
 *
 * The framework registers /health/live, /health/ready, /health/startup via
 * routes/health.php (loaded by RouteManifest as a "public" — no API prefix —
 * route file). These tests boot the framework and dispatch real requests
 * through the router to confirm both registration and handler behavior.
 */
class HealthProbesTest extends TestCase
{
    private string $appPath;
    private Application $app;
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootFramework();
    }

    protected function tearDown(): void
    {
        if (isset($this->appPath) && is_dir($this->appPath)) {
            $this->recursiveRemoveDirectory($this->appPath);
        }
        parent::tearDown();
    }

    public function testLivenessProbeReturnsOk(): void
    {
        $response = $this->router->dispatch(Request::create('/health/live', 'GET'));

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        $this->assertIsArray($body);
        $this->assertSame('ok', $body['status'] ?? null);
    }

    public function testStartupProbeReturnsStarted(): void
    {
        $response = $this->router->dispatch(Request::create('/health/startup', 'GET'));

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        $this->assertIsArray($body);
        $this->assertSame('started', $body['status'] ?? null);
    }

    public function testReadinessProbeIsReachableAndReportsCheckResults(): void
    {
        // The /health/ready endpoint always reports the result of database,
        // cache, and config checks. Status is 200 when all pass, 503 when any
        // fails. The minimal test config flunks an upstream check (no JWT/APP
        // keys, no .env), so we assert the route is wired and the payload has
        // the expected check shape regardless of which envelope it lands in.
        $response = $this->router->dispatch(Request::create('/health/ready', 'GET'));

        $status = $response->getStatusCode();
        $this->assertContains($status, [200, 503], 'Readiness must respond with either 200 or 503');

        $body = json_decode((string) $response->getContent(), true);
        $this->assertIsArray($body);

        // Success envelope nests checks under 'data'; error envelope under 'error.details'.
        $checks = $body['data']['checks']
            ?? $body['error']['details']['checks']
            ?? null;
        $this->assertIsArray($checks, 'Readiness payload should expose database/cache/config checks');
        $this->assertArrayHasKey('database', $checks);
        $this->assertArrayHasKey('cache', $checks);
        $this->assertArrayHasKey('config', $checks);
    }

    public function testHealthLiveCoexistsWithLegacyHealthz(): void
    {
        // The k8s-conventional /health/live should work alongside the existing
        // /healthz endpoint — they call the same handler so both must respond.
        $live = $this->router->dispatch(Request::create('/health/live', 'GET'));
        $healthz = $this->router->dispatch(Request::create('/healthz', 'GET'));

        $this->assertSame(200, $live->getStatusCode());
        $this->assertSame(200, $healthz->getStatusCode());
        $this->assertSame($live->getContent(), $healthz->getContent());
    }

    private function bootFramework(): void
    {
        // RouteManifest tracks load state in a static so tests that dispatch
        // through the router must reset it before each fresh framework boot,
        // otherwise the new Router instance starts with no routes registered.
        RouteManifest::reset();

        $this->appPath = sys_get_temp_dir() . '/glueful-probes-' . uniqid();
        $configPath = $this->appPath . '/config';
        mkdir($configPath, 0755, true);

        file_put_contents(
            $configPath . '/app.php',
            "<?php\nreturn ['name' => 'T', 'version_full' => '1.0.0', 'env' => 'testing', 'debug' => true];\n"
        );
        file_put_contents(
            $configPath . '/database.php',
            "<?php\nreturn ["
            . "'engine' => 'sqlite', "
            . "'sqlite' => ['primary' => ':memory:'], "
            . "'pooling' => ['enabled' => false]"
            . "];\n"
        );
        file_put_contents(
            $configPath . '/cache.php',
            "<?php\nreturn ['enabled' => true, 'default' => 'array', "
            . "'stores' => ['array' => ['driver' => 'array']]];\n"
        );
        file_put_contents($configPath . '/security.php', "<?php\nreturn ['csrf' => ['enabled' => false]];\n");
        file_put_contents($configPath . '/session.php', "<?php\nreturn ['jwt_key' => 'test'];\n");

        $this->app = Framework::create($this->appPath)->boot(allowReboot: true);
        $this->router = $this->app->getContainer()->get(Router::class);
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

<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Routes;

use Glueful\Framework;
use Glueful\Http\Router;
use Glueful\Http\Response as ApiResponse;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class CompiledRoutesTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->baseDir = sys_get_temp_dir() . '/routes-compiled-' . uniqid();
        @mkdir($this->baseDir . '/config', 0755, true);
        @mkdir($this->baseDir . '/routes', 0755, true);

        // Production environment to trigger compilation
        file_put_contents(
            $this->baseDir . '/config/app.php',
            "<?php return ['env'=>'production','debug'=>false,'version_full'=>'1.0.0'];\n"
        );

        // Simple route file
        $routesPhp = <<<'PHP'
        <?php
        use Glueful\Http\Router;
        use Glueful\Http\Response as ApiResponse;
        Router::get('/cr', function () { return ApiResponse::success(['ok'=>true]); });
        PHP;
        file_put_contents($this->baseDir . '/routes/api.php', $routesPhp);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->baseDir);
        parent::tearDown();
    }

    public function test_compiled_routes_cache_is_created_and_used(): void
    {
        Framework::create($this->baseDir)->withEnvironment('production')->boot(allowReboot: true);

        // The router should have a compiled matcher or factory registered
        $this->assertTrue(Router::hasCompiledMatcher(), 'Compiled matcher not registered');

        // A cache file should exist
        $cacheFiles = glob($this->baseDir . '/storage/cache/routes_production_*.php') ?: [];
        $this->assertNotEmpty($cacheFiles, 'Compiled routes cache file not found');

        // Dispatch the route and assert success
        $resp = Router::dispatch(Request::create('/cr', 'GET'));
        $this->assertSame(200, $resp->getStatusCode());
        $data = json_decode($resp->getContent(), true);
        $this->assertTrue($data['success'] ?? false);
        $this->assertTrue(($data['data']['ok'] ?? false) === true);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) { return; }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}


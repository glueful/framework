<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions;

use Glueful\Framework;
use Glueful\Extensions\Services\ExtensionLoader;
use Glueful\Http\Router;
use Glueful\Http\Response as ApiResponse;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ExtensionLoaderTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->baseDir = sys_get_temp_dir() . '/ext-loader-' . uniqid();
        @mkdir($this->baseDir . '/config', 0755, true);
        @mkdir($this->baseDir . '/extensions/DemoExt/src', 0755, true);

        file_put_contents($this->baseDir . '/config/app.php', "<?php return ['env'=>'testing','debug'=>true,'version_full'=>'1.0.0'];\n");
        file_put_contents($this->baseDir . '/config/extensions.php', file_get_contents(base_path('config/extensions.php')) ?: "<?php return [];\n");

        // Manifest with fallback routes via src/routes.php
        $manifest = [
            'version' => '1.0.0',
            'author' => 'Glueful Team',
            'main' => 'DemoExt.php',
            'main_class' => 'Glueful\\Extensions\\DemoExt',
            'provides' => [],
        ];
        file_put_contents($this->baseDir . '/extensions/DemoExt/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));

        // routes.php that registers a route
        $routesPhp = <<<'PHP'
        <?php
        use Glueful\Http\Router;
        use Glueful\Http\Response as ApiResponse;
        Router::get('/ext-demo', function() { return ApiResponse::success(['ok'=>true]); });
        PHP;
        file_put_contents($this->baseDir . '/extensions/DemoExt/src/routes.php', $routesPhp);

        // Main extension class file referenced by manifest
        $mainClass = <<<'PHP'
        <?php
        namespace Glueful\Extensions;
        class DemoExt { }
        PHP;
        file_put_contents($this->baseDir . '/extensions/DemoExt/DemoExt.php', $mainClass);

        Framework::create($this->baseDir)->withEnvironment('testing')->boot(allowReboot: true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->baseDir);
        parent::tearDown();
    }

    public function test_discovers_and_loads_routes(): void
    {
        /** @var ExtensionLoader $loader */
        $loader = container()->get(ExtensionLoader::class);

        $found = $loader->discoverExtensions();
        $this->assertContains('DemoExt', $found);

        $loader->loadRoutes('DemoExt');
        $response = Router::dispatch(Request::create('/ext-demo', 'GET'));
        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['ok']);
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

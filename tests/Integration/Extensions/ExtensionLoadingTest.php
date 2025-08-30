<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Extensions;

use Glueful\Framework;
use Glueful\Extensions\ExtensionManager;
use Glueful\Http\Router;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ExtensionLoadingTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->baseDir = sys_get_temp_dir() . '/ext-int-' . uniqid();
        @mkdir($this->baseDir . '/config', 0755, true);
        @mkdir($this->baseDir . '/extensions/DemoExt/src', 0755, true);

        // Minimal configs
        file_put_contents(
            $this->baseDir . '/config/app.php',
            "<?php return ['env'=>'testing','debug'=>true,'version_full'=>'1.0.0'];\n"
        );
        file_put_contents(
            $this->baseDir . '/config/extensions.php',
            file_get_contents(base_path('config/extensions.php')) ?: "<?php return [];\n"
        );

        // Extension with a route
        $manifest = [
            'version' => '1.0.0',
            'author' => 'Glueful Team',
            'main' => 'DemoExt.php',
            'main_class' => 'Glueful\\Extensions\\DemoExt',
            'provides' => [
                // Let loader fallback to src/routes.php if not listed
            ],
        ];
        file_put_contents(
            $this->baseDir . '/extensions/DemoExt/manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT)
        );

        $routesPhp = <<<'PHP'
        <?php
        use Glueful\Http\Router;
        use Glueful\Http\Response as ApiResponse;
        Router::get('/demo-int', function() { return ApiResponse::success(['from_ext'=>true]); });
        PHP;
        file_put_contents($this->baseDir . '/extensions/DemoExt/src/routes.php', $routesPhp);

        // Main file for the extension (required by loader)
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

    public function testEnableExtensionAndLoadRoutesViaManager(): void
    {
        /** @var ExtensionManager $manager */
        $manager = container()->get(ExtensionManager::class);

        // Enable extension and load routes
        $this->assertTrue($manager->enable('DemoExt'));
        $manager->loadEnabledExtensions();
        $manager->loadExtensionRoutes();

        // Route should respond
        $resp = Router::dispatch(Request::create('/demo-int', 'GET'));
        $this->assertSame(200, $resp->getStatusCode());
        $data = json_decode($resp->getContent(), true);
        $this->assertTrue($data['from_ext']);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
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

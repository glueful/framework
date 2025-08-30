<?php

declare(strict_types=1);

namespace Glueful\Tests\Documentation;

use Glueful\Framework;
use Glueful\Http\Router;
use Glueful\Http\Response as ApiResponse;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class DocumentationExamplesTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->baseDir = sys_get_temp_dir() . '/docs-examples-' . uniqid();
        @mkdir($this->baseDir . '/config', 0755, true);
        @mkdir($this->baseDir . '/app/Controllers', 0755, true);

        // Minimal config to boot framework
        file_put_contents(
            $this->baseDir . '/config/app.php',
            "<?php return ['env'=>'testing','debug'=>true,'version_full'=>'1.0.0'];\n"
        );

        Framework::create($this->baseDir)->withEnvironment('testing')->boot(allowReboot: true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->baseDir);
        parent::tearDown();
    }

    public function testReadmeQuickStartExample(): void
    {
        // Simulate README quick start endpoints
        Router::get('/', function (): ApiResponse {
            return ApiResponse::success(['message' => 'Welcome']);
        });

        Router::get('/health', function (): ApiResponse {
            return ApiResponse::success([
                'status' => 'ok',
                'timestamp' => time(),
                'uptime' => 1,
                'memory_usage' => 1,
                'peak_memory' => 1,
            ]);
        });

        $resp = Router::dispatch(Request::create('/', 'GET'));
        $this->assertSame(200, $resp->getStatusCode());

        $resp = Router::dispatch(Request::create('/health', 'GET'));
        $this->assertSame(200, $resp->getStatusCode());

        $data = json_decode($resp->getContent(), true);
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('timestamp', $data);
        $this->assertArrayHasKey('uptime', $data);
        $this->assertArrayHasKey('memory_usage', $data);
        $this->assertArrayHasKey('peak_memory', $data);
    }

    public function testControllerExampleFromDocs(): void
    {
        // Write a simple controller matching doc style
        $controllerPhp = <<<'PHP'
        <?php
        namespace App\Controllers;
        use Glueful\Controllers\BaseController;
        class ExampleController extends BaseController {
            public function index() { return \Glueful\Http\Response::success(["message" => "Hello from docs"]); }
        }
        PHP;

        $controllerPath = $this->baseDir . '/app/Controllers/ExampleController.php';
        file_put_contents($controllerPath, $controllerPhp);
        require_once $controllerPath;

        // Route to the controller (wrapped in closure to avoid autoload requirement)
        // Route to the controller (avoid direct type reference to keep static analyzers happy)
        $class = 'App\\Controllers\\ExampleController';
        Router::get('/example', function () use ($class) {
            $controller = new $class();
            return $controller->index();
        });

        $resp = Router::dispatch(Request::create('/example', 'GET'));
        $this->assertSame(200, $resp->getStatusCode());

        $expectedJson = '{"success":true,"message":"Success",' .
            '"data":{"message":"Hello from docs"}}';
        $this->assertSame($expectedJson, $resp->getContent());

        // Cleanup
        @unlink($controllerPath);
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

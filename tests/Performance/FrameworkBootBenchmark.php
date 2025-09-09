<?php

declare(strict_types=1);

namespace Glueful\Tests\Performance;

use Glueful\Framework;
use Glueful\Routing\Router;
use Glueful\Http\Response as ApiResponse;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class FrameworkBootBenchmark extends TestCase
{
    public function testFrameworkBootPerformance(): void
    {
        $iterations = 10;
        $times = [];

        for ($i = 0; $i < $iterations; $i++) {
            $start = microtime(true);

            $testPath = sys_get_temp_dir() . '/glueful-perf-test-' . uniqid();
            // Minimal config to allow boot
            @mkdir($testPath . '/config', 0755, true);
            file_put_contents(
                $testPath . '/config/app.php',
                "<?php return ['env'=>'testing','debug'=>true,'version_full'=>'1.0.0'];\n"
            );

            $framework = Framework::create($testPath)->withEnvironment('testing');
            $framework->boot(allowReboot: true);

            $end = microtime(true);
            $times[] = ($end - $start) * 1000.0; // ms

            // Cleanup
            $this->rrmdir($testPath);
            unset($framework);
        }

        $averageTime = array_sum($times) / max(1, count($times));
        $maxTime = max($times);

        fwrite(STDOUT, sprintf(
            "\nFramework boot performance:\n  Average: %.2f ms\n  Max: %.2f ms\n",
            $averageTime,
            $maxTime
        ));

        // Thresholds can be tuned per environment
        $this->assertLessThan(1000, $averageTime, 'Average boot time should be under 1 second');
        $this->assertLessThan(2000, $maxTime, 'Maximum boot time should be under 2 seconds');
    }

    public function testRequestHandlingPerformance(): void
    {
        $testPath = sys_get_temp_dir() . '/glueful-perf-http-' . uniqid();
        @mkdir($testPath . '/config', 0755, true);
        file_put_contents(
            $testPath . '/config/app.php',
            "<?php return ['env'=>'testing','debug'=>true,'version_full'=>'1.0.0'];\n"
        );
        Framework::create($testPath)->withEnvironment('testing')->boot(allowReboot: true);

        // Register a simple route using DI container
        $router = container()->get(Router::class);
        $router->get('/perf-test', function () {
            return ApiResponse::success(['timestamp' => microtime(true)]);
        });

        $iterations = 100;
        $times = [];

        for ($i = 0; $i < $iterations; $i++) {
            $request = Request::create('/perf-test', 'GET');

            $start = microtime(true);
            $response = $router->dispatch($request);
            $end = microtime(true);

            // Ensure OK and small payload
            $this->assertSame(200, $response->getStatusCode());

            $times[] = ($end - $start) * 1000.0; // ms
        }

        $averageTime = array_sum($times) / max(1, count($times));
        $maxTime = max($times);

        fwrite(STDOUT, sprintf(
            "\nRequest handling performance:\n  Average: %.2f ms\n  Max: %.2f ms\n",
            $averageTime,
            $maxTime
        ));

        // Reasonable defaults; adjust as needed if environment is slow
        $this->assertLessThan(50, $averageTime, 'Average request time should be under 50ms');
        $this->assertLessThan(200, $maxTime, 'Maximum request time should be under 200ms');

        // Cleanup
        $this->rrmdir($testPath);
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

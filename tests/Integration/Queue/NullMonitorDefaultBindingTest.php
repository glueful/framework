<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Queue;

use Glueful\Application;
use Glueful\Framework;
use Glueful\Queue\Contracts\WorkerMonitorInterface;
use Glueful\Queue\Monitoring\NullWorkerMonitor;
use Glueful\Routing\RouteManifest;
use PHPUnit\Framework\TestCase;

/**
 * WS3 Task 3a: NullWorkerMonitor is the default core binding for
 * WorkerMonitorInterface. After dropping the concrete WorkerMonitor from core
 * DI, a booted core container must resolve the interface seam to the no-op
 * implementation (real persistence lives in the queue-ops capability).
 */
final class NullMonitorDefaultBindingTest extends TestCase
{
    private string $appPath;
    private Application $app;

    protected function tearDown(): void
    {
        if (isset($this->appPath) && is_dir($this->appPath)) {
            $this->recursiveRemoveDirectory($this->appPath);
        }
        parent::tearDown();
    }

    public function testInterfaceResolvesToNullWorkerMonitor(): void
    {
        $container = $this->bootContainer();

        $monitor = $container->get(WorkerMonitorInterface::class);

        self::assertInstanceOf(
            NullWorkerMonitor::class,
            $monitor,
            'Core DI must resolve WorkerMonitorInterface to NullWorkerMonitor by default'
        );
    }

    public function testNullMonitorReportsNoActiveWorkers(): void
    {
        $container = $this->bootContainer();

        $monitor = $container->get(WorkerMonitorInterface::class);

        self::assertSame([], $monitor->getActiveWorkers());
    }

    private function bootContainer(): \Psr\Container\ContainerInterface
    {
        RouteManifest::reset();

        $this->appPath = sys_get_temp_dir() . '/glueful-nullmon-' . uniqid();
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
        file_put_contents(
            $configPath . '/queue.php',
            "<?php\nreturn ['default' => 'database', 'maintenance' => []];\n"
        );

        $this->app = Framework::create($this->appPath)->boot(allowReboot: true);

        return $this->app->getContainer();
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

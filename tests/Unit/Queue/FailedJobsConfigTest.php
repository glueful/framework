<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Queue;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Framework;
use Glueful\Queue\Drivers\DatabaseQueue;
use Glueful\Queue\Failed\FailedJobProvider;
use Glueful\Queue\Jobs\QueueMaintenance;
use Glueful\Routing\RouteManifest;
use PHPUnit\Framework\TestCase;

/**
 * config/queue.php ships `failed.max_retries` and `failed.retention_days`, but the database driver
 * and QueueMaintenance built their failed-job storage with a hardcoded 5 and 30, so changing the
 * config did nothing. Both now read it, with 5 and 30 as the defaults.
 */
final class FailedJobsConfigTest extends TestCase
{
    private string $appPath;

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->appPath));
    }

    private function boot(string $failed): ApplicationContext
    {
        RouteManifest::reset();
        $this->appPath = sys_get_temp_dir() . '/glueful-qfailedcfg-' . uniqid('', true);
        $cfg = $this->appPath . '/config';
        mkdir($cfg, 0755, true);
        $files = [
            'app' => "['name' => 'T', 'version_full' => '1.0.0', 'env' => 'testing', 'debug' => true, "
                . "'key' => 'test-queue-signing-key']",
            'database' => "['engine' => 'sqlite', 'sqlite' => ['primary' => '" . $this->appPath . "/q.sqlite'], "
                . "'pooling' => ['enabled' => false]]",
            'cache' => "['enabled' => true, 'default' => 'array', 'stores' => ['array' => ['driver' => 'array']]]",
            'security' => "['csrf' => ['enabled' => false]]",
            'session' => "['jwt_key' => 'test']",
            'queue' => "['default' => 'database', 'connections' => ['database' => ['driver' => 'database', "
                . "'table' => 'queue_jobs', 'failed_table' => 'queue_failed_jobs']], 'failed' => {$failed}]",
        ];
        foreach ($files as $name => $body) {
            file_put_contents("{$cfg}/{$name}.php", "<?php\nreturn {$body};\n");
        }

        return Framework::create($this->appPath)->boot(allowReboot: true)->getContext();
    }

    private function maintenanceProvider(ApplicationContext $context): FailedJobProvider
    {
        $property = new \ReflectionProperty(QueueMaintenance::class, 'failedJobProvider');
        $property->setAccessible(true);
        $provider = $property->getValue(new QueueMaintenance($context));
        self::assertInstanceOf(FailedJobProvider::class, $provider);

        return $provider;
    }

    public function testTheDriverAndMaintenanceUseTheConfiguredValues(): void
    {
        $context = $this->boot("['max_retries' => 2, 'retention_days' => 9]");
        $queue = new DatabaseQueue();
        $queue->initialize(['context' => $context]);

        self::assertSame(9, $queue->failures()->getRetentionDays());
        self::assertSame(9, $this->maintenanceProvider($context)->getRetentionDays());
    }

    public function testTheContainerBuiltProviderUsesTheConfiguredValues(): void
    {
        $context = $this->boot("['max_retries' => 2, 'retention_days' => 9]");

        $provider = container($context)->get(FailedJobProvider::class);

        self::assertInstanceOf(FailedJobProvider::class, $provider);
        self::assertSame(9, $provider->getRetentionDays());
    }

    public function testWithoutTheKeysTheDefaultsAreFiveAndThirty(): void
    {
        $context = $this->boot('[]');
        $queue = new DatabaseQueue();
        $queue->initialize(['context' => $context]);

        self::assertSame(30, $queue->failures()->getRetentionDays());
        self::assertSame(30, $this->maintenanceProvider($context)->getRetentionDays());
    }
}

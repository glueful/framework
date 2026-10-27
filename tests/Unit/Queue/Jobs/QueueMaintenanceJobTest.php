<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Queue\Jobs;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Framework;
use Glueful\Queue\JobHandlerResolver;
use Glueful\Queue\Jobs\QueueMaintenanceJob;
use Glueful\Routing\RouteManifest;
use PHPUnit\Framework\TestCase;

/**
 * QueueMaintenance prunes failed jobs past queue.failed.retention_days, but nothing scheduled it,
 * so queue_failed_jobs grew forever. The built-in `queue_maintenance` job now runs it daily.
 */
final class QueueMaintenanceJobTest extends TestCase
{
    private string $appPath;

    protected function tearDown(): void
    {
        if (isset($this->appPath)) {
            exec('rm -rf ' . escapeshellarg($this->appPath));
        }
    }

    private function boot(): ApplicationContext
    {
        RouteManifest::reset();
        $this->appPath = sys_get_temp_dir() . '/glueful-qmaint-' . uniqid('', true);
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
                . "'table' => 'queue_jobs', 'failed_table' => 'queue_failed_jobs']], "
                . "'failed' => ['retention_days' => 7]]",
        ];
        foreach ($files as $name => $body) {
            file_put_contents("{$cfg}/{$name}.php", "<?php\nreturn {$body};\n");
        }
        $context = Framework::create($this->appPath)->boot(allowReboot: true)->getContext();

        require_once dirname(__DIR__, 4) . '/migrations/queue/001_CreateQueueSystemTables.php';
        (new \Glueful\Migrations\Queue\CreateQueueSystemTables())
            ->up(Connection::fromContext($context)->getSchemaBuilder());

        return $context;
    }

    public function testTheJobPrunesFailedJobsPastTheRetention(): void
    {
        $context = $this->boot();
        $db = Connection::fromContext($context);
        foreach (['old' => '-10 days', 'new' => '-1 day'] as $uuid => $age) {
            $db->table('queue_failed_jobs')->insert([
                'uuid' => $uuid,
                'connection' => 'database',
                'queue' => 'default',
                'payload' => '{}',
                'exception' => 'boom',
                'failed_at' => date('Y-m-d H:i:s', (int) strtotime($age)),
            ]);
        }

        JobHandlerResolver::resolve(QueueMaintenanceJob::class, [], $context)->handle();

        $left = array_column($db->table('queue_failed_jobs')->select(['uuid'])->get(), 'uuid');
        self::assertSame(['new'], $left);
    }

    public function testTheDefaultScheduleRunsTheJobDaily(): void
    {
        $config = require dirname(__DIR__, 4) . '/config/schedule.php';
        $jobs = array_column($config['jobs'], null, 'name');

        self::assertArrayHasKey('queue_maintenance', $jobs);
        self::assertSame(QueueMaintenanceJob::class, $jobs['queue_maintenance']['handler_class']);
        self::assertMatchesRegularExpression('/^\d+ \d+ \* \* \*$/', $jobs['queue_maintenance']['schedule']);
    }
}

<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Scheduler;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Framework;
use Glueful\Queue\Job;
use Glueful\Routing\RouteManifest;
use Glueful\Scheduler\JobScheduler;
use PHPUnit\Framework\TestCase;

/**
 * A config job declared `persistence => true` was registered with a fresh INSERT every time the
 * scheduler loaded (each `queue:scheduler run` tick), so scheduled_jobs grew one duplicate row per
 * tick and every duplicate ran. The in-memory copy registerInDatabase() added also had no
 * callback. Registration is now idempotent by job name and the job runs once per due tick.
 */
final class PersistentConfigJobRegistrationTest extends TestCase
{
    private string $appPath;
    private ApplicationContext $context;

    protected function setUp(): void
    {
        RouteManifest::reset();
        $this->appPath = sys_get_temp_dir() . '/glueful-sched-persist-' . uniqid('', true);
        $cfg = $this->appPath . '/config';
        mkdir($cfg, 0755, true);
        $files = [
            'app' => "['name' => 'T', 'version_full' => '1.0.0', 'env' => 'testing', 'debug' => true, "
                . "'key' => 'test-scheduler-signing-key']",
            'database' => "['engine' => 'sqlite', 'sqlite' => ['primary' => '" . $this->appPath . "/s.sqlite'], "
                . "'pooling' => ['enabled' => false]]",
            'cache' => "['enabled' => true, 'default' => 'array', 'stores' => ['array' => ['driver' => 'array']]]",
            'security' => "['csrf' => ['enabled' => false]]",
            'session' => "['jwt_key' => 'test']",
            'schedule' => var_export(['jobs' => [[
                'name' => 'persisted_probe',
                'schedule' => '* * * * *',
                'handler_class' => PersistedRecordingJob::class,
                'parameters' => ['answer' => 7],
                'persistence' => true,
            ]]], true),
        ];
        foreach ($files as $name => $body) {
            file_put_contents("{$cfg}/{$name}.php", "<?php\nreturn {$body};\n");
        }
        $this->context = Framework::create($this->appPath)->boot(allowReboot: true)->getContext();

        require_once dirname(__DIR__, 3) . '/migrations/scheduler/001_CreateScheduledJobsTables.php';
        (new \Glueful\Migrations\Scheduler\CreateScheduledJobsTables())
            ->up(Connection::fromContext($this->context)->getSchemaBuilder());
        PersistedRecordingJob::$runs = 0;
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->appPath));
    }

    private function rows(): int
    {
        return count(Connection::fromContext($this->context)->table('scheduled_jobs')
            ->select(['uuid'])->where('name', 'persisted_probe')->get());
    }

    public function testRepeatedSchedulerLoadsKeepOneRowAndRunTheJobOncePerTick(): void
    {
        ob_start();
        try {
            new JobScheduler(null, $this->context);
            new JobScheduler(null, $this->context);
            $scheduler = new JobScheduler(null, $this->context);

            self::assertSame(1, $this->rows(), 'one scheduled_jobs row per config job name');
            $named = array_filter($scheduler->getJobs(), static fn (array $j): bool => $j['name'] === 'persisted_probe');
            self::assertCount(1, $named, 'registered once in memory');

            $scheduler->runDueJobs();
        } finally {
            ob_end_clean();
        }

        self::assertSame(1, PersistedRecordingJob::$runs);
    }

    public function testAChangedScheduleUpdatesTheExistingRow(): void
    {
        ob_start();
        try {
            new JobScheduler(null, $this->context);
            (new JobScheduler(null, $this->context))->registerInDatabase(
                'persisted_probe',
                '0 4 * * *',
                PersistedRecordingJob::class,
                ['answer' => 8]
            );
        } finally {
            ob_end_clean();
        }

        $rows = Connection::fromContext($this->context)->table('scheduled_jobs')
            ->select(['schedule'])->where('name', 'persisted_probe')->get();
        self::assertCount(1, $rows);
        self::assertSame('0 4 * * *', $rows[0]['schedule']);
    }

    public function testDuplicateRowsFromEarlierLoadsAreCollapsedToOne(): void
    {
        $db = Connection::fromContext($this->context);
        foreach (['dup000000001', 'dup000000002'] as $uuid) {
            $db->table('scheduled_jobs')->insert([
                'uuid' => $uuid,
                'name' => 'persisted_probe',
                'schedule' => '* * * * *',
                'handler_class' => PersistedRecordingJob::class,
                'is_enabled' => 1,
                'next_run' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        ob_start();
        try {
            $scheduler = new JobScheduler(null, $this->context);
        } finally {
            ob_end_clean();
        }

        $rows = $db->table('scheduled_jobs')->select(['uuid'])->where('name', 'persisted_probe')->get();
        self::assertSame([['uuid' => 'dup000000001']], $rows, 'the oldest row is kept');
        $named = array_filter($scheduler->getJobs(), static fn (array $j): bool => $j['name'] === 'persisted_probe');
        self::assertCount(1, $named);
    }
}

final class PersistedRecordingJob extends Job
{
    public static int $runs = 0;

    public function handle(): void
    {
        self::$runs++;
    }
}

<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Queue;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Framework;
use Glueful\Queue\Drivers\DatabaseQueue;
use Glueful\Routing\RouteManifest;
use PHPUnit\Framework\TestCase;

/**
 * DatabaseQueue::pop() selected the next job and then reserved it by uuid alone, with no lock
 * and no condition, so two workers that selected the same row both reserved it and both ran it.
 * The reservation is now a claim: it succeeds only while the row is still unreserved, and a
 * worker that loses the claim moves on to the next job.
 */
final class DatabaseQueueClaimTest extends TestCase
{
    private string $appPath;
    private ApplicationContext $context;

    protected function setUp(): void
    {
        RouteManifest::reset();
        $this->appPath = sys_get_temp_dir() . '/glueful-qclaim-' . uniqid('', true);
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
                . "'table' => 'queue_jobs', 'failed_table' => 'queue_failed_jobs', 'retry_after' => 90]]]",
        ];
        foreach ($files as $name => $body) {
            file_put_contents("{$cfg}/{$name}.php", "<?php\nreturn {$body};\n");
        }
        $this->context = Framework::create($this->appPath)->boot(allowReboot: true)->getContext();

        require_once dirname(__DIR__, 3) . '/migrations/queue/001_CreateQueueSystemTables.php';
        (new \Glueful\Migrations\Queue\CreateQueueSystemTables())
            ->up(Connection::fromContext($this->context)->getSchemaBuilder());
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->appPath));
    }

    public function testAWorkerThatLosesTheClaimTakesTheNextJobNotTheSameOne(): void
    {
        // This worker selected both jobs; before it claimed, a rival reserved the first. The
        // worker's candidate list is that stale selection.
        $queue = new class extends DatabaseQueue {
            /** @var list<array<string, mixed>> */
            public array $staleSelection = [];

            protected function candidates(string $queue, int $limit): array
            {
                return $this->staleSelection;
            }
        };
        $queue->initialize(['context' => $this->context]);
        $first = $queue->push(ClaimTestJob::class, ['n' => 1], 'default');
        $second = $queue->push(ClaimTestJob::class, ['n' => 2], 'default');
        $connection = Connection::fromContext($this->context);
        $queue->staleSelection = array_values($connection->table('queue_jobs')
            ->select(['*'])->orderBy('id', 'ASC')->get());
        $connection->table('queue_jobs')->where('uuid', $first)
            ->update(['reserved_at' => date('Y-m-d H:i:s')]);

        $job = $queue->pop('default');

        self::assertNotNull($job);
        self::assertSame($second, $job->getUuid(), 'the job the rival claimed is not taken again');
        $firstRow = $connection->table('queue_jobs')->where('uuid', $first)->first();
        self::assertSame(0, (int) $firstRow['attempts'], 'the rival\'s claim is untouched');
    }

    public function testTheHealthCheckReportsAWorkingQueueAsHealthy(): void
    {
        // It probed the connection with a table-less query the builder refuses, so it reported
        // every database queue unhealthy.
        $queue = new DatabaseQueue();
        $queue->initialize(['context' => $this->context]);

        $health = $queue->healthCheck();

        self::assertTrue($health->isHealthy(), $health->message);
    }

    public function testAJobThatReleasesItselfIsRequeuedWithItsDelay(): void
    {
        // The worker runs a fresh instance of the job class, with no driver: its release() only
        // set a flag, the queue wrapper saw nothing and deleted the row. A webhook delivery that
        // scheduled a retry this way never ran again.
        $queue = new DatabaseQueue();
        $queue->initialize(['context' => $this->context]);
        $uuid = $queue->push(ReleasingTestJob::class, [], 'default');

        $job = $queue->pop('default');
        self::assertNotNull($job);
        $job->fire();

        $row = Connection::fromContext($this->context)->table('queue_jobs')->where('uuid', $uuid)->first();
        self::assertNotNull($row, 'the released job is still on the queue');
        self::assertNull($row['reserved_at']);
        self::assertGreaterThanOrEqual(time() + 55, strtotime((string) $row['available_at']));
    }
}


final class ClaimTestJob extends \Glueful\Queue\Job
{
    public function handle(): void
    {
    }
}

final class ReleasingTestJob extends \Glueful\Queue\Job
{
    public function handle(): void
    {
        $this->release(60);
    }
}

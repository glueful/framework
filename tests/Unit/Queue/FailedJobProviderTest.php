<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Queue;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Framework;
use Glueful\Queue\Drivers\DatabaseQueue;
use Glueful\Queue\Failed\FailedJobProvider;
use Glueful\Routing\RouteManifest;
use PHPUnit\Framework\TestCase;

/**
 * FailedJobProvider was written for a failed-jobs table the migration never creates: log()
 * inserted retryable/retry_count/job_class/exception_class columns, retry() read them, and its
 * requeue step was a stub that never put a job back. Statistics used MySQL's HOUR(). It now works
 * against the stock queue_failed_jobs table on every engine, and is the one implementation the
 * database driver and the queue:* commands use.
 */
final class FailedJobProviderTest extends TestCase
{
    private \Glueful\Application $app;

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
        $this->app = Framework::create($this->appPath)->boot(allowReboot: true);
        $this->context = $this->app->getContext();

        require_once dirname(__DIR__, 3) . '/migrations/queue/001_CreateQueueSystemTables.php';
        (new \Glueful\Migrations\Queue\CreateQueueSystemTables())
            ->up(Connection::fromContext($this->context)->getSchemaBuilder());
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->appPath));
    }

    private function queue(): DatabaseQueue
    {
        $queue = new DatabaseQueue();
        $queue->initialize(['context' => $this->context]);

        return $queue;
    }

    private function provider(): FailedJobProvider
    {
        return new FailedJobProvider(Connection::fromContext($this->context), 'queue_failed_jobs', 5, 30, $this->context);
    }

    /** A signed payload exactly as the database driver stores it. */
    private function storedPayload(int $n): string
    {
        $queue = new DatabaseQueue();
        $queue->initialize(['context' => $this->context]);
        $queue->push(ProviderTestJob::class, ['n' => $n], 'default');
        $job = $queue->pop('default');
        self::assertNotNull($job);
        $queue->delete($job);

        return (string) json_encode($job->getPayload());
    }

    public function testLogWritesARowTheStockTableAcceptsAndAllListsIt(): void
    {
        $provider = $this->provider();
        $uuid = $provider->log('database', 'mail', $this->storedPayload(1), new \RuntimeException('boom'));

        $all = $provider->all(['queue' => 'mail']);

        self::assertCount(1, $all);
        self::assertSame($uuid, $all[0]['uuid']);
        self::assertSame(ProviderTestJob::class, $all[0]['job']);
        self::assertStringStartsWith('RuntimeException: boom', $all[0]['exception']);
    }

    public function testRetryPutsTheJobBackOnItsQueue(): void
    {
        $provider = $this->provider();
        $uuid = $provider->log('database', 'mail', $this->storedPayload(5), new \RuntimeException('boom'));

        self::assertTrue($provider->retry($uuid));

        self::assertNull($provider->find($uuid), 'the failure is gone once the job is back');
        $row = Connection::fromContext($this->context)->table('queue_jobs')->where('queue', 'mail')->first();
        self::assertNotNull($row, 'the job is on its queue again');
        self::assertSame(5, json_decode((string) $row['payload'], true)['data']['n'] ?? null);
    }

    public function testStatisticsAndPatternsWorkOnEveryEngine(): void
    {
        $provider = $this->provider();
        $provider->log('database', 'mail', $this->storedPayload(1), new \RuntimeException('boom'));
        $provider->log('database', 'mail', $this->storedPayload(2), new \LogicException('bad'));

        $stats = $provider->getStats();

        self::assertSame(2, $stats['total_failed']);
        self::assertSame(2, $stats['recent_failures']);
        self::assertSame(2, $stats['failure_patterns']['job_classes'][ProviderTestJob::class] ?? null);
        self::assertSame(2, array_sum($stats['failure_patterns']['hourly_trends']));
    }

    public function testPruneReportsHowManyOldFailuresItRemoved(): void
    {
        $provider = $this->provider();
        $old = $provider->log('database', 'default', $this->storedPayload(1), new \RuntimeException('old'));
        $provider->log('database', 'default', $this->storedPayload(2), new \RuntimeException('new'));
        Connection::fromContext($this->context)->table('queue_failed_jobs')->where('uuid', $old)
            ->update(['failed_at' => date('Y-m-d H:i:s', time() - 40 * 86400)]);

        self::assertSame(1, $provider->prune(30));
        self::assertCount(1, $provider->all());
    }
}

final class ProviderTestJob extends \Glueful\Queue\Job
{
    public function handle(): void
    {
    }
}

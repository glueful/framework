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
 * A job that exhausts its attempts moves to queue_failed_jobs, and nothing could list, retry or
 * remove it: no command, no screen. The database driver now manages its own failed jobs, and
 * queue:failed / queue:retry / queue:forget / queue:flush sit on top.
 */
final class DatabaseQueueFailedJobsTest extends TestCase
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

    private function failOne(DatabaseQueue $queue, int $n, string $name = 'default'): string
    {
        $queue->push(FailedTestJob::class, ['n' => $n], $name);
        $job = $queue->pop($name);
        self::assertNotNull($job);
        $queue->fail($job, new \RuntimeException("boom {$n}"));

        return (string) Connection::fromContext($this->context)->table('queue_failed_jobs')
            ->select(['uuid'])->orderBy('id', 'DESC')->limit(1)->get()[0]['uuid'];
    }

    public function testFailedJobsAreListedWithTheirJobClassAndError(): void
    {
        $queue = $this->queue();
        $uuid = $this->failOne($queue, 1);

        $failed = $queue->failedJobs();

        self::assertCount(1, $failed);
        self::assertSame($uuid, $failed[0]['uuid']);
        self::assertSame(FailedTestJob::class, $failed[0]['job']);
        self::assertSame('default', $failed[0]['queue']);
        self::assertStringStartsWith('RuntimeException: boom 1', $failed[0]['exception']);
    }

    public function testRetryPutsTheJobBackOnItsQueueAndDropsTheFailure(): void
    {
        $queue = $this->queue();
        $uuid = $this->failOne($queue, 7, 'mail');

        $newUuid = $queue->retryFailed($uuid);

        self::assertNotNull($newUuid);
        self::assertSame([], $queue->failedJobs());
        $job = $queue->pop('mail');
        self::assertNotNull($job);
        self::assertSame(7, $job->getPayload()['data']['n'] ?? null);
        self::assertSame(FailedTestJob::class, $job->getPayload()['job'] ?? null);
    }

    public function testRetryRefusesAPayloadWhoseSignatureNoLongerMatches(): void
    {
        $queue = $this->queue();
        $uuid = $this->failOne($queue, 1);
        $db = Connection::fromContext($this->context);
        $row = $db->table('queue_failed_jobs')->where('uuid', $uuid)->first();
        $payload = json_decode((string) $row['payload'], true);
        $payload['data']['n'] = 999;
        $db->table('queue_failed_jobs')->where('uuid', $uuid)->update(['payload' => json_encode($payload)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('signature');
        $queue->retryFailed($uuid);
    }

    public function testForgetAndFlushRemoveFailures(): void
    {
        $queue = $this->queue();
        $one = $this->failOne($queue, 1);
        $this->failOne($queue, 2, 'mail');
        $this->failOne($queue, 3, 'mail');

        self::assertTrue($queue->forgetFailed($one));
        self::assertFalse($queue->forgetFailed($one));
        self::assertSame(2, $queue->flushFailed('mail'));
        self::assertSame([], $queue->failedJobs());
    }

    public function testTheCommandsListAndRetryFailedJobs(): void
    {
        $uuid = $this->failOne($this->queue(), 4, 'mail');
        $container = $this->app->getContainer();

        $list = new \Symfony\Component\Console\Tester\CommandTester(
            new \Glueful\Console\Commands\Queue\FailedCommand($container, $this->context)
        );
        self::assertSame(0, $list->execute(['--json' => true]));
        $listed = json_decode($list->getDisplay(), true);
        self::assertSame($uuid, $listed[0]['uuid'] ?? null);

        $retry = new \Symfony\Component\Console\Tester\CommandTester(
            new \Glueful\Console\Commands\Queue\RetryCommand($container, $this->context)
        );
        self::assertSame(0, $retry->execute(['uuid' => [$uuid]]));
        self::assertStringContainsString("Retried {$uuid}", $retry->getDisplay());
        self::assertSame(1, $retry->execute(['uuid' => ['nope']]), 'an unknown uuid is a failure');
    }
}


final class FailedTestJob extends \Glueful\Queue\Job
{
    public function handle(): void
    {
    }
}

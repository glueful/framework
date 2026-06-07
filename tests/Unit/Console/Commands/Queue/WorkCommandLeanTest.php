<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Console\Commands\Queue;

use Glueful\Application;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Console\Commands\Queue\WorkCommand;
use Glueful\Database\Connection;
use Glueful\Framework;
use Glueful\Queue\QueueManager;
use Glueful\Queue\QueueWorker;
use Glueful\Routing\RouteManifest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Exception\RuntimeException as ConsoleRuntimeException;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * WS2 Task 2c: the lean `queue:work` command is a thin adapter over
 * {@see QueueWorker}. It exposes no `action` argument and none of the legacy
 * supervisor/process/IPC options — those moved to `glueful/queue-ops`.
 *
 * Covers:
 *  (a) `queue:work --once` drains one queued job via QueueWorker;
 *  (b) `--connection` defaults to `config('queue.default')` and targets the
 *      named connection when passed;
 *  (c) the removed actions (e.g. `queue:work spawn`) are rejected as a Symfony
 *      console "too many arguments" error.
 */
final class WorkCommandLeanTest extends TestCase
{
    private string $appPath;
    private string $dbFile;
    private Application $app;
    private ApplicationContext $context;

    protected function setUp(): void
    {
        RouteManifest::reset();

        $this->appPath = sys_get_temp_dir() . '/glueful-qwork-lean-' . uniqid('', true);
        $this->dbFile = $this->appPath . '/queue.sqlite';
        $cfg = $this->appPath . '/config';
        mkdir($cfg, 0755, true);

        file_put_contents($cfg . '/app.php', "<?php\nreturn ['name' => 'T', 'version_full' => '1.0.0', 'env' => 'testing', 'debug' => true];\n");
        file_put_contents(
            $cfg . '/database.php',
            "<?php\nreturn ['engine' => 'sqlite', 'sqlite' => ['primary' => '" . $this->dbFile . "'], "
            . "'pooling' => ['enabled' => false]];\n"
        );
        file_put_contents($cfg . '/cache.php', "<?php\nreturn ['enabled' => true, 'default' => 'array', 'stores' => ['array' => ['driver' => 'array']]];\n");
        file_put_contents($cfg . '/security.php', "<?php\nreturn ['csrf' => ['enabled' => false]];\n");
        file_put_contents($cfg . '/session.php', "<?php\nreturn ['jwt_key' => 'test'];\n");
        file_put_contents(
            $cfg . '/queue.php',
            "<?php\nreturn ['default' => 'database', 'connections' => ['database' => ['driver' => 'database', "
            . "'table' => 'queue_jobs', 'failed_table' => 'queue_failed_jobs', 'retry_after' => 90]]];\n"
        );

        $this->app = Framework::create($this->appPath)->boot(allowReboot: true);
        $this->context = $this->app->getContext();

        $this->createQueueSchema();
    }

    protected function tearDown(): void
    {
        if (isset($this->appPath) && is_dir($this->appPath)) {
            $this->recursiveRemoveDirectory($this->appPath);
        }
        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // (a) --once drains one job via QueueWorker
    // ---------------------------------------------------------------------

    public function testOnceProcessesOneQueuedJobViaQueueWorker(): void
    {
        LeanWorkJob::$ran = 0;
        $manager = $this->manager();
        $manager->push(LeanWorkJob::class, ['n' => 1], 'default');

        $tester = $this->tester();
        $exit = $tester->execute(['--once' => true]);

        self::assertSame(0, $exit, 'one-shot run succeeds');
        self::assertSame(1, LeanWorkJob::$ran, 'the queued job fired via QueueWorker');
        self::assertSame(0, $this->queueSize('default'), 'job removed from queue');
    }

    // ---------------------------------------------------------------------
    // (b) --connection defaulting + targeting
    // ---------------------------------------------------------------------

    public function testConnectionDefaultsToConfiguredDefault(): void
    {
        // Override QueueWorker with a spy so we can assert the connection name
        // the command resolves when --connection is omitted.
        $spy = new ConnectionSpyQueueWorker();
        $tester = $this->testerWithWorker($spy);
        $tester->execute(['--once' => true]);

        self::assertSame('database', $spy->connection, '--connection defaults to config(queue.default)');
    }

    public function testConnectionTargetsNamedConnectionWhenPassed(): void
    {
        $spy = new ConnectionSpyQueueWorker();
        $tester = $this->testerWithWorker($spy);
        $tester->execute(['--once' => true, '--connection' => 'reporting']);

        self::assertSame('reporting', $spy->connection, 'named --connection is passed through');
    }

    // ---------------------------------------------------------------------
    // (c) removed actions are rejected
    // ---------------------------------------------------------------------

    public function testRemovedSpawnActionIsRejectedAsTooManyArguments(): void
    {
        // The lean command declares no positional `action` argument, so passing
        // a positional (as a real CLI invocation does: `queue:work spawn`) is a
        // Symfony console "Too many arguments" RuntimeException.
        $command = new WorkCommand($this->app->getContainer(), $this->context);
        $command->setName('queue:work');

        $application = new ConsoleApplication();
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);
        $application->add($command);

        $this->expectException(ConsoleRuntimeException::class);
        $this->expectExceptionMessage('No arguments expected');
        $application->run(new StringInput('queue:work spawn'), new NullOutput());
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function tester(): CommandTester
    {
        $command = new WorkCommand($this->app->getContainer(), $this->context);

        return new CommandTester($command);
    }

    private function testerWithWorker(object $worker): CommandTester
    {
        /** @var \Glueful\Container\Container $base */
        $base = $this->app->getContainer();
        $container = $base->with([QueueWorker::class => $worker]);
        $command = new WorkCommand($container, $this->context);

        return new CommandTester($command);
    }

    private function manager(): QueueManager
    {
        /** @var QueueManager $manager */
        $manager = $this->app->getContainer()->get(QueueManager::class);
        return $manager;
    }

    private function createQueueSchema(): void
    {
        $connection = Connection::fromContext($this->context);
        $schema = $connection->getSchemaBuilder();

        require_once dirname(__DIR__, 5) . '/migrations/queue/001_CreateQueueSystemTables.php';
        $migration = new \Glueful\Migrations\Queue\CreateQueueSystemTables();
        $migration->up($schema);
    }

    private function queueSize(string $queue): int
    {
        $connection = Connection::fromContext($this->context);
        return (int) $connection->table('queue_jobs')->where('queue', $queue)->count();
    }

    private function recursiveRemoveDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}

/**
 * Job that records its executions.
 */
final class LeanWorkJob
{
    public static int $ran = 0;

    /** @param array<string,mixed> $data */
    public function handle(array $data): void
    {
        self::$ran++;
    }
}

/**
 * Duck-typed QueueWorker spy capturing the connection name routed by the
 * command. QueueWorker is final, so the spy cannot subclass it; the command
 * resolves it from the container and calls runOnce()/daemon() without an
 * instanceof check, so a structurally compatible object suffices.
 */
final class ConnectionSpyQueueWorker
{
    public ?string $connection = null;

    /** @param array<int,string> $queues */
    public function daemon(string $connection, array $queues, \Glueful\Queue\WorkerOptions $options): int
    {
        $this->connection = $connection;
        return 0;
    }

    /** @param array<int,string> $queues */
    public function runOnce(string $connection, array $queues, \Glueful\Queue\WorkerOptions $options): bool
    {
        $this->connection = $connection;
        return false;
    }
}

<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Scheduler;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\ConfigurationLoader;
use Glueful\Queue\Job;
use Glueful\Scheduler\JobScheduler;
use PHPUnit\Framework\TestCase;

/**
 * Jobs declared in config/schedule.php must actually run when due. The in-memory registration
 * used to wrap them in a callback that only RETURNED the handler class name: the tick logged
 * "Executed job (0ms)" and never instantiated the handler, so every config-declared job was a
 * no-op under `queue:scheduler run`. They now resolve and run the handler the way database
 * jobs do, with the application context, and a job declared `enabled => false` is skipped.
 */
final class ConfigScheduledJobsTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/glueful-schedule-' . uniqid('', true);
        mkdir($this->base . '/config', 0755, true);
        file_put_contents($this->base . '/config/schedule.php', "<?php\nreturn " . var_export([
            'jobs' => [
                [
                    'name' => 'probe',
                    'schedule' => '* * * * *',
                    'handler_class' => RecordingScheduledJob::class,
                    'parameters' => ['answer' => 42],
                ],
                [
                    'name' => 'switched_off',
                    'schedule' => '* * * * *',
                    'handler_class' => RecordingScheduledJob::class,
                    'parameters' => ['answer' => 'never'],
                    'enabled' => false,
                ],
            ],
        ], true) . ";\n");
        RecordingScheduledJob::$runs = [];
    }

    protected function tearDown(): void
    {
        @unlink($this->base . '/config/schedule.php');
        @rmdir($this->base . '/config');
        @rmdir($this->base);
    }

    public function testADueConfigJobRunsItsHandlerWithItsParametersAndTheContext(): void
    {
        $context = new ApplicationContext($this->base, 'testing');
        $context->setConfigLoader(new ConfigurationLoader($this->base, 'testing'));

        (new JobScheduler(null, $context))->runDueJobs();

        self::assertSame([['answer' => 42, 'context' => true]], RecordingScheduledJob::$runs);
    }
}

final class RecordingScheduledJob extends Job
{
    /** @var list<array<string, mixed>> */
    public static array $runs = [];

    public function handle(): void
    {
        self::$runs[] = ['answer' => $this->getData()['answer'] ?? null, 'context' => $this->context !== null];
    }
}

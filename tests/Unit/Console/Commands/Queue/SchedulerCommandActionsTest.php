<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Console\Commands\Queue;

use Glueful\Application;
use Glueful\Console\Commands\Queue\SchedulerCommand;
use Glueful\Framework;
use Glueful\Routing\RouteManifest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * queue:scheduler advertised `add`, `remove`, `enable`, `disable` and `history`, which printed
 * "not yet implemented" (or a success message for work never done) and exited 0. They are gone:
 * the command offers only the actions that work, and an unknown action fails with a message
 * listing them.
 */
final class SchedulerCommandActionsTest extends TestCase
{
    private string $appPath;
    private Application $app;

    protected function setUp(): void
    {
        RouteManifest::reset();
        $this->appPath = sys_get_temp_dir() . '/glueful-sched-cmd-' . uniqid('', true);
        $cfg = $this->appPath . '/config';
        mkdir($cfg, 0755, true);
        $files = [
            'app' => "['name' => 'T', 'version_full' => '1.0.0', 'env' => 'testing', 'debug' => true]",
            'database' => "['engine' => 'sqlite', 'sqlite' => ['primary' => '" . $this->appPath . "/s.sqlite'], "
                . "'pooling' => ['enabled' => false]]",
            'cache' => "['enabled' => true, 'default' => 'array', 'stores' => ['array' => ['driver' => 'array']]]",
            'security' => "['csrf' => ['enabled' => false]]",
            'session' => "['jwt_key' => 'test']",
            'schedule' => "['jobs' => []]",
        ];
        foreach ($files as $name => $body) {
            file_put_contents("{$cfg}/{$name}.php", "<?php\nreturn {$body};\n");
        }
        $this->app = Framework::create($this->appPath)->boot(allowReboot: true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->appPath));
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new SchedulerCommand($this->app->getContainer(), $this->app->getContext()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function removedActions(): iterable
    {
        foreach (['add', 'remove', 'enable', 'disable', 'history'] as $action) {
            yield $action => [$action];
        }
    }

    /**
     * @dataProvider removedActions
     */
    public function testARemovedActionFailsAndNamesTheAvailableOnes(string $action): void
    {
        $tester = $this->tester();
        $code = $tester->execute(['action' => $action], ['interactive' => false]);
        $display = $tester->getDisplay();

        self::assertSame(Command::FAILURE, $code);
        self::assertStringContainsString("Unknown action: {$action}", $display);
        self::assertStringNotContainsString('not yet implemented', $display);
        self::assertStringContainsString('run, work, list, status, health', $display);
    }

    public function testTheHelpOffersOnlyWorkingActions(): void
    {
        $command = new SchedulerCommand($this->app->getContainer(), $this->app->getContext());
        $description = $command->getDefinition()->getArgument('action')->getDescription();

        self::assertSame('Action to perform (run, work, list, status, health)', $description);
        foreach (['job-name', 'cron', 'command', 'description', 'timeout', 'history'] as $option) {
            self::assertFalse($command->getDefinition()->hasOption($option), "--{$option} belonged to a removed action");
        }
    }

    public function testListStillWorks(): void
    {
        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute(['action' => 'list']));
    }
}

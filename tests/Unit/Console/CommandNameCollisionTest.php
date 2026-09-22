<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Console;

use Glueful\Console\Application;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;

/**
 * Two packages declaring the same command name used to overwrite each other in silence: Symfony
 * keeps the last one added, and nothing said which command a name now runs. The console still
 * keeps the last one, and now says so.
 */
final class CommandNameCollisionTest extends TestCase
{
    private string $log = '';
    private string|false $previousLog = false;

    protected function setUp(): void
    {
        $this->log = (string) tempnam(sys_get_temp_dir(), 'glueful-console-log-');
        $this->previousLog = ini_set('error_log', $this->log);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousLog === false ? '' : $this->previousLog);
        @unlink($this->log);
    }

    public function testASecondCommandUnderTheSameNameIsReported(): void
    {
        $console = new Application($this->emptyContainer(), '1.0.0');
        $console->addCommand(new FirstStatusCommand());
        $console->addCommand(new SecondStatusCommand());

        self::assertInstanceOf(SecondStatusCommand::class, $console->find('search:status'));
        $log = (string) file_get_contents($this->log);
        self::assertStringContainsString("'search:status'", $log);
        self::assertStringContainsString(FirstStatusCommand::class, $log);
        self::assertStringContainsString(SecondStatusCommand::class, $log);
    }

    public function testTheSameCommandAddedTwiceIsNotACollision(): void
    {
        $console = new Application($this->emptyContainer(), '1.0.0');
        $console->addCommand(new FirstStatusCommand());
        $console->addCommand(new FirstStatusCommand());

        self::assertSame('', (string) file_get_contents($this->log));
    }

    private function emptyContainer(): ContainerInterface
    {
        return new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException("no {$id}");
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
    }
}

final class FirstStatusCommand extends Command
{
    public function __construct()
    {
        parent::__construct('search:status');
    }
}

final class SecondStatusCommand extends Command
{
    public function __construct()
    {
        parent::__construct('search:status');
    }
}

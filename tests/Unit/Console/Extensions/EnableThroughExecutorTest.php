<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Console\Extensions;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Console\Commands\Extensions\DisableCommand;
use Glueful\Console\Commands\Extensions\EnableCommand;
use Glueful\Extensions\Schema\ExtensionOperation;
use Glueful\Extensions\Schema\ExtensionSchemaExecutor;
use Glueful\Extensions\Schema\SchemaNotBootstrappedException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Tester\CommandTester;

/** Spy standing in for the executor; the command must not care how it is built. */
final class SpyExecutor extends ExtensionSchemaExecutor
{
    /** @var list<array{op: string, package: string, dryRun: bool, backup: bool}> */
    public array $calls = [];
    public ?ExtensionOperation $result = null;
    public ?\Throwable $throws = null;

    public function __construct()
    {
        // No parent construction: the spy needs none of the collaborators.
    }

    public function enable(
        string $package,
        string $actor,
        bool $dryRun = false,
        bool $backup = false
    ): ExtensionOperation {
        return $this->respond('enable', $package, $dryRun, $backup);
    }

    public function disable(
        string $package,
        string $actor,
        bool $dryRun = false,
        bool $backup = false
    ): ExtensionOperation {
        return $this->respond('disable', $package, $dryRun, $backup);
    }

    private function respond(string $op, string $package, bool $dryRun, bool $backup): ExtensionOperation
    {
        $this->calls[] = ['op' => $op, 'package' => $package, 'dryRun' => $dryRun, 'backup' => $backup];
        if ($this->throws !== null) {
            throw $this->throws;
        }
        return $this->result
            ?? new ExtensionOperation(7, $package, $op, 'enabled', ExtensionOperation::STATUS_SUCCEEDED, 'cli');
    }
}

final class EnableThroughExecutorTest extends TestCase
{
    private string $base;
    private ApplicationContext $context;
    private SpyExecutor $executor;
    /** @var array<string, string> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/glueful-cmd-' . uniqid('', true);
        mkdir($this->base . '/vendor/composer', 0777, true);
        file_put_contents($this->base . '/vendor/composer/installed.json', json_encode(['packages' => [[
            'name' => 'acme/widgets',
            'type' => 'glueful-extension',
            'install-path' => '../acme/widgets',
            'extra' => ['glueful' => [
                'provider' => 'Acme\\Widgets\\Provider',
                'migrations' => 'none',
            ]],
        ]]], JSON_UNESCAPED_SLASHES));
        $this->context = new ApplicationContext($this->base);
        $this->executor = new SpyExecutor();

        // The whole point: production is no longer refused.
        $this->envBackup['APP_ENV'] = getenv('APP_ENV') !== false ? (string) getenv('APP_ENV') : '';
        putenv('APP_ENV=production');
        $_ENV['APP_ENV'] = 'production';
    }

    protected function tearDown(): void
    {
        if ($this->envBackup['APP_ENV'] === '') {
            putenv('APP_ENV');
            unset($_ENV['APP_ENV']);
        } else {
            putenv('APP_ENV=' . $this->envBackup['APP_ENV']);
            $_ENV['APP_ENV'] = $this->envBackup['APP_ENV'];
        }
    }

    private function container(): ContainerInterface
    {
        $executor = $this->executor;
        return new class ($executor) implements ContainerInterface {
            public function __construct(private readonly SpyExecutor $executor)
            {
            }

            public function get(string $id): mixed
            {
                if ($id === ExtensionSchemaExecutor::class) {
                    return $this->executor;
                }
                throw new \RuntimeException("unexpected service {$id}");
            }

            public function has(string $id): bool
            {
                return $id === ExtensionSchemaExecutor::class;
            }
        };
    }

    public function testProductionIsNotRefusedAndExecutorReceivesFlags(): void
    {
        $tester = new CommandTester(new EnableCommand($this->container(), $this->context));
        $exit = $tester->execute([
            'extension' => 'acme/widgets',
            '--dry-run' => true,
            '--backup' => true,
        ]);

        self::assertSame(0, $exit, $tester->getDisplay());
        self::assertStringNotContainsString('not available in production', $tester->getDisplay());
        self::assertSame(
            [['op' => 'enable', 'package' => 'acme/widgets', 'dryRun' => true, 'backup' => true]],
            $this->executor->calls
        );
    }

    public function testFailurePathPrintsTheFailedMigration(): void
    {
        $this->executor->result = new ExtensionOperation(
            9,
            'acme/widgets',
            'enable',
            'migrating',
            ExtensionOperation::STATUS_FAILED,
            'cli',
            '001_Boom.php',
            'boom'
        );
        $tester = new CommandTester(new EnableCommand($this->container(), $this->context));
        $exit = $tester->execute(['extension' => 'acme/widgets']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('001_Boom.php', $tester->getDisplay());
        self::assertStringContainsString('failed', $tester->getDisplay());
    }

    public function testBootstrapExceptionRendersItsRemedy(): void
    {
        $this->executor->throws = SchemaNotBootstrappedException::create();
        $tester = new CommandTester(new EnableCommand($this->container(), $this->context));
        $exit = $tester->execute(['extension' => 'acme/widgets']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('migrate:run', $tester->getDisplay());
    }

    public function testDisableDrivesTheSameExecutor(): void
    {
        $tester = new CommandTester(new DisableCommand($this->container(), $this->context));
        $exit = $tester->execute(['extension' => 'acme/widgets']);

        self::assertSame(0, $exit, $tester->getDisplay());
        self::assertSame('disable', $this->executor->calls[0]['op']);
    }
}

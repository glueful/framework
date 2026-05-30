<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Console\Extensions;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Console\Commands\Extensions\CreateCommand;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class CreateExtensionTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/glueful-create-' . uniqid('', true);
        @mkdir($this->base, 0777, true);
        file_put_contents(
            $this->base . '/composer.json',
            (string) json_encode(['name' => 'app/test', 'require' => []], JSON_PRETTY_PRINT) . "\n"
        );
    }

    private function container(ApplicationContext $ctx): ContainerInterface
    {
        return new class ($ctx) implements ContainerInterface {
            public function __construct(private ApplicationContext $ctx)
            {
            }

            public function get(string $id): mixed
            {
                if ($id === ApplicationContext::class) {
                    return $this->ctx;
                }
                throw new class ("no {$id}") extends \RuntimeException implements
                    \Psr\Container\NotFoundExceptionInterface {
                };
            }

            public function has(string $id): bool
            {
                return $id === ApplicationContext::class;
            }
        };
    }

    public function testScaffoldsComposerPackageAndPathRepo(): void
    {
        $ctx = new ApplicationContext($this->base, 'testing');
        $tester = new CommandTester(new CreateCommand($this->container($ctx), $ctx));
        $status = $tester->execute(['name' => 'Widgets']);

        $this->assertSame(0, $status, $tester->getDisplay());

        $extDir = $this->base . '/extensions/widgets';
        $this->assertFileExists($extDir . '/composer.json');
        $composer = json_decode((string) file_get_contents($extDir . '/composer.json'), true);
        $this->assertIsArray($composer);
        $this->assertSame('glueful-extension', $composer['type']);
        $this->assertSame(
            'Glueful\\Extensions\\Widgets\\WidgetsServiceProvider',
            $composer['extra']['glueful']['provider']
        );
        $this->assertArrayHasKey('Glueful\\Extensions\\Widgets\\', $composer['autoload']['psr-4']);

        $this->assertFileExists($extDir . '/src/WidgetsServiceProvider.php');
        $this->assertFileExists($extDir . '/routes/routes.php');
        $this->assertFileExists($extDir . '/config/widgets.php');
        $this->assertDirectoryExists($extDir . '/database/migrations');

        // Generated provider stub must be syntactically valid PHP.
        $lint = shell_exec('php -l ' . escapeshellarg($extDir . '/src/WidgetsServiceProvider.php') . ' 2>&1');
        $this->assertStringContainsString('No syntax errors', (string) $lint);

        // App composer.json gained a path repository for the extension.
        $appComposer = json_decode((string) file_get_contents($this->base . '/composer.json'), true);
        $this->assertIsArray($appComposer);
        $repoPaths = array_column($appComposer['repositories'] ?? [], 'url');
        $this->assertContains('extensions/widgets', $repoPaths);
    }

    public function testRefusesWhenExtensionAlreadyExists(): void
    {
        $ctx = new ApplicationContext($this->base, 'testing');
        (new CommandTester(new CreateCommand($this->container($ctx), $ctx)))->execute(['name' => 'Widgets']);

        $tester = new CommandTester(new CreateCommand($this->container($ctx), $ctx));
        $status = $tester->execute(['name' => 'Widgets']);
        $this->assertSame(1, $status);
        $this->assertStringContainsString('already exists', $tester->getDisplay());
    }
}

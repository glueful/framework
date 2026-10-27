<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Console\Scaffold;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Console\Commands\Scaffold\ControllerCommand;
use Glueful\Services\FileFinder;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * scaffold:controller must write the controller where the app autoloads it (app/Controllers,
 * App\Controllers — the api-skeleton's PSR-4 root) and its "next steps" must name the real
 * registration step (the app provider's services() entry), not a nonexistent
 * ControllerServiceProvider.
 */
final class ControllerCommandTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/glueful-ctrl-' . uniqid('', true);
        @mkdir($this->base, 0777, true);
    }

    private function tester(ApplicationContext $ctx): CommandTester
    {
        $container = new class ($ctx) implements ContainerInterface {
            public function __construct(private ApplicationContext $ctx)
            {
            }

            public function get(string $id): mixed
            {
                return match ($id) {
                    ApplicationContext::class => $this->ctx,
                    FileFinder::class => new FileFinder(),
                    default => throw new class ("no {$id}") extends \RuntimeException implements
                        \Psr\Container\NotFoundExceptionInterface {
                    },
                };
            }

            public function has(string $id): bool
            {
                return in_array($id, [ApplicationContext::class, FileFinder::class], true);
            }
        };

        return new CommandTester(new ControllerCommand($container, $ctx));
    }

    public function testScaffoldsIntoAppNamespaceAndPrintsRealRegistrationStep(): void
    {
        @mkdir($this->base . '/app', 0777, true);
        $ctx = new ApplicationContext($this->base, 'testing');
        $tester = $this->tester($ctx);

        $status = $tester->execute(['name' => 'TaskController']);
        $display = $tester->getDisplay();

        $this->assertSame(0, $status, $display);
        $file = $this->base . '/app/Controllers/TaskController.php';
        $this->assertFileExists($file);
        $this->assertStringContainsString('namespace App\\Controllers;', (string) file_get_contents($file));

        $this->assertStringNotContainsString('ControllerServiceProvider', $display);
        $this->assertStringContainsString('App\\Providers\\AppServiceProvider', $display);
        $this->assertStringContainsString('App\\Controllers\\TaskController::class', $display);
        $this->assertStringContainsString('config/serviceproviders.php', $display);
    }

    public function testFallsBackToLegacyLayoutWithoutAppDir(): void
    {
        $ctx = new ApplicationContext($this->base, 'testing');
        $tester = $this->tester($ctx);

        $status = $tester->execute(['name' => 'TaskController']);

        $this->assertSame(0, $status, $tester->getDisplay());
        $file = $this->base . '/api/Controllers/TaskController.php';
        $this->assertFileExists($file);
        $this->assertStringContainsString('namespace Glueful\\Controllers;', (string) file_get_contents($file));
        $this->assertStringNotContainsString('ControllerServiceProvider', $tester->getDisplay());
    }
}

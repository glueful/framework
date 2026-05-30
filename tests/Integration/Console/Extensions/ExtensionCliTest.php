<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Console\Extensions;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\ConfigurationLoader;
use Glueful\Console\Commands\Extensions\DisableCommand;
use Glueful\Console\Commands\Extensions\EnableCommand;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * End-to-end CLI test for extensions:enable / extensions:disable.
 *
 * The headline guarantee under test: enable/disable VALIDATE the proposed enabled
 * list before writing, so a command never leaves config/extensions.php in a broken
 * state (e.g. enabling an extension whose dependency is not enabled is refused, and
 * the config file is left untouched).
 *
 * ExtensionManager is intentionally NOT provided by the test container: the post-write
 * cache recompile is best-effort and its failure is a documented, recoverable warning —
 * it must not affect the config write that this test asserts on.
 */
final class ExtensionCliTest extends TestCase
{
    private string $base;
    private ?string $prevEnv = null;

    protected function setUp(): void
    {
        $this->prevEnv = getenv('APP_ENV') === false ? null : (string) getenv('APP_ENV');
        putenv('APP_ENV=testing');

        $this->base = sys_get_temp_dir() . '/glueful-cli-' . uniqid('', true);
        @mkdir($this->base . '/config', 0777, true);
        @mkdir($this->base . '/vendor/composer', 0777, true);

        file_put_contents(
            $this->base . '/config/extensions.php',
            "<?php\n\nreturn [\n    'enabled' => [\n    ],\n];\n"
        );

        // Three installed extensions: a standalone (widgets), a base, and a dependent
        // (gadgets) that requires the base's provider.
        file_put_contents(
            $this->base . '/vendor/composer/installed.php',
            "<?php\nreturn " . var_export([
                'versions' => [
                    'vendor/widgets' => [
                        'type' => 'glueful-extension',
                        'extra' => ['glueful' => ['provider' => 'Vendor\\Widgets\\Provider']],
                    ],
                    'vendor/base' => [
                        'type' => 'glueful-extension',
                        'extra' => ['glueful' => ['provider' => 'Vendor\\Base\\Provider']],
                    ],
                    'vendor/gadgets' => [
                        'type' => 'glueful-extension',
                        'extra' => ['glueful' => [
                            'provider' => 'Vendor\\Gadgets\\Provider',
                            'requires' => ['extensions' => ['Vendor\\Base\\Provider']],
                        ]],
                    ],
                ],
            ], true) . ";\n"
        );
    }

    protected function tearDown(): void
    {
        if ($this->prevEnv === null) {
            putenv('APP_ENV');
        } else {
            putenv('APP_ENV=' . $this->prevEnv);
        }
    }

    /** @return list<string> */
    private function enabled(): array
    {
        $config = require $this->base . '/config/extensions.php';
        return array_values($config['enabled']);
    }

    private function context(): ApplicationContext
    {
        $ctx = new ApplicationContext($this->base, 'testing', [
            'framework' => $this->base . '/config',
            'application' => $this->base . '/config',
        ]);
        $ctx->setConfigLoader(new ConfigurationLoader($this->base, 'testing', $this->base . '/config'));
        return $ctx;
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

    /** @param array<string, string> $args */
    private function runEnable(array $args): CommandTester
    {
        $ctx = $this->context();
        $tester = new CommandTester(new EnableCommand($this->container($ctx), $ctx));
        $tester->execute($args);
        return $tester;
    }

    /** @param array<string, string> $args */
    private function runDisable(array $args): CommandTester
    {
        $ctx = $this->context();
        $tester = new CommandTester(new DisableCommand($this->container($ctx), $ctx));
        $tester->execute($args);
        return $tester;
    }

    public function testEnableAddsProviderToConfig(): void
    {
        $tester = $this->runEnable(['extension' => 'widgets']);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame(['Vendor\\Widgets\\Provider'], $this->enabled());
    }

    public function testEnableMatchesSlugCaseInsensitively(): void
    {
        // Package slug is "widgets"; the capitalized "Widgets" must still resolve.
        $tester = $this->runEnable(['extension' => 'Widgets']);
        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame(['Vendor\\Widgets\\Provider'], $this->enabled());
    }

    public function testEnableIsIdempotent(): void
    {
        $this->runEnable(['extension' => 'widgets']);
        $tester = $this->runEnable(['extension' => 'widgets']);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('already enabled', $tester->getDisplay());
        $this->assertSame(['Vendor\\Widgets\\Provider'], $this->enabled());
    }

    public function testDisableRemovesProvider(): void
    {
        $this->runEnable(['extension' => 'widgets']);
        $tester = $this->runDisable(['extension' => 'widgets']);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame([], $this->enabled());
    }

    public function testEnableUnknownFailsAndLeavesConfigUntouched(): void
    {
        $tester = $this->runEnable(['extension' => 'does-not-exist']);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('not found among installed packages', $tester->getDisplay());
        $this->assertSame([], $this->enabled());
    }

    public function testEnableWithUnmetDependencyIsRefusedAndConfigNotModified(): void
    {
        // gadgets requires base, which is not enabled → must refuse, no write.
        $tester = $this->runEnable(['extension' => 'gadgets']);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('missing_dependency', $tester->getDisplay());
        $this->assertSame([], $this->enabled());
    }

    public function testDisableDependencyInUseIsRefused(): void
    {
        // Enable base, then gadgets (which depends on base).
        $this->runEnable(['extension' => 'base']);
        $this->runEnable(['extension' => 'gadgets']);
        $this->assertEqualsCanonicalizing(
            ['Vendor\\Base\\Provider', 'Vendor\\Gadgets\\Provider'],
            $this->enabled()
        );

        // Disabling base while gadgets still requires it must be refused.
        $tester = $this->runDisable(['extension' => 'base']);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('depends on it', $tester->getDisplay());
        $this->assertEqualsCanonicalizing(
            ['Vendor\\Base\\Provider', 'Vendor\\Gadgets\\Provider'],
            $this->enabled()
        );
    }
}

<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions\Install;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ExtensionCatalog;
use Glueful\Extensions\Install\ExtensionInstaller;
use Glueful\Extensions\Install\HostCapability;
use Glueful\Extensions\Install\HostNotWritableException;
use Glueful\Extensions\Install\InstallDisabledException;
use Glueful\Extensions\Install\PackageNotAllowedException;
use Glueful\Support\Process\ComposerBinaryResolver;
use Glueful\Support\Process\ProcessRunner;
use PHPUnit\Framework\TestCase;

final class ExtensionInstallerTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];
    private ?string $prevComposerEnv = null;

    protected function setUp(): void
    {
        $this->prevComposerEnv = getenv('COMPOSER_BINARY') ?: null;
        putenv('COMPOSER_BINARY=' . PHP_BINARY); // host preflight + resolve() pass deterministically
    }

    protected function tearDown(): void
    {
        $this->prevComposerEnv === null
            ? putenv('COMPOSER_BINARY')
            : putenv('COMPOSER_BINARY=' . $this->prevComposerEnv);
        foreach ($this->tempDirs as $dir) {
            $this->rmrf($dir);
        }
    }

    public function test_rejects_flag_like_non_glueful_and_non_catalog_packages(): void
    {
        [$installer] = $this->make(killSwitch: true, catalog: ['glueful/aegis']);

        foreach (['--evil', 'evil/thallo', 'glueful/not-in-catalog', 'GLUEFUL/Aegis', ''] as $bad) {
            try {
                $installer->install($bad);
                $this->fail("accepted disallowed package: '{$bad}'");
            } catch (PackageNotAllowedException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_happy_path_runs_composer_require_synchronously(): void
    {
        [$installer, $runner] = $this->make(killSwitch: true, catalog: ['glueful/aegis'], exitCode: 0);

        $result = $installer->install('glueful/aegis');

        $this->assertSame('installed', $result['status']);
        $this->assertNull($result['error']);
        // Invoked as `<php> <composer> require glueful/aegis …` (no shell), with COMPOSER_HOME set.
        $this->assertContains('require', $runner->cmd);
        $this->assertContains('glueful/aegis', $runner->cmd);
        $this->assertArrayHasKey('COMPOSER_HOME', $runner->env ?? []);
    }

    public function test_composer_failure_returns_failed_with_output(): void
    {
        [$installer] = $this->make(
            killSwitch: true,
            catalog: ['glueful/aegis'],
            exitCode: 1,
            output: 'Your requirements could not be resolved',
        );

        $result = $installer->install('glueful/aegis');

        $this->assertSame('failed', $result['status']);
        $this->assertSame('composer require failed', $result['error']);
        $this->assertStringContainsString('could not be resolved', $result['output']);
    }

    public function test_missing_composer_binary_fails_the_host_preflight(): void
    {
        // A missing composer is caught by the host preflight (409) before the install
        // even starts — the installer's own null-guard is belt-and-suspenders.
        putenv('COMPOSER_BINARY=/no/such/composer');
        [$installer] = $this->make(killSwitch: true, catalog: ['glueful/aegis']);

        $this->expectException(HostNotWritableException::class);
        $installer->install('glueful/aegis');
    }

    public function test_kill_switch_off_throws(): void
    {
        [$installer] = $this->make(killSwitch: false, catalog: ['glueful/aegis']);
        $this->expectException(InstallDisabledException::class);
        $installer->install('glueful/aegis');
    }

    /**
     * @param list<string> $catalog
     * @return array{0:ExtensionInstaller,1:object}
     */
    private function make(bool $killSwitch, array $catalog, int $exitCode = 0, string $output = ''): array
    {
        $base = sys_get_temp_dir() . '/inst_' . bin2hex(random_bytes(6));
        mkdir($base . '/config', 0755, true);
        $this->tempDirs[] = $base;

        $context = ApplicationContext::forTesting($base);
        $context->mergeConfigDefaults('extensions', [
            'install' => ['enabled' => $killSwitch, 'vendor' => 'glueful/'],
        ]);

        $rows = array_map(
            static fn(string $pkg) => ['package' => $pkg, 'description' => '', 'version' => '1.0.0',
                                       'downloads' => 0, 'repository' => '', 'state' => 'available'],
            $catalog,
        );
        $fakeCatalog = new class ($rows) extends ExtensionCatalog {
            /** @param list<array<string,mixed>> $rows */
            public function __construct(private array $rows)
            {
                // Intentionally skip parent constructor — catalog() is overridden.
            }

            public function catalog(bool $refresh = false): array
            {
                return $this->rows;
            }
        };

        // Fake runner: capture the argv + env, return a scripted exit code / output.
        $runner = new class ($exitCode, $output) implements ProcessRunner {
            /** @var list<string>|null */
            public ?array $cmd = null;
            /** @var array<string,string>|null */
            public ?array $env = null;

            public function __construct(private int $exitCode, private string $output)
            {
            }

            public function run(array $cmd, string $cwd, float $timeout, ?callable $onOutput = null, ?array $env = null): array
            {
                $this->cmd = $cmd;
                $this->env = $env;
                return ['exitCode' => $this->exitCode, 'output' => $this->output];
            }
        };

        $installer = new ExtensionInstaller(
            $context,
            $fakeCatalog,
            new HostCapability($context, new ComposerBinaryResolver()),
            $runner,
        );

        return [$installer, $runner];
    }

    private function rmrf(string $dir): void
    {
        if (!file_exists($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmrf($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}

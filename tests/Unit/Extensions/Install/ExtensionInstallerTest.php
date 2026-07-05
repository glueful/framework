<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions\Install;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\Drivers\ArrayCacheDriver;
use Glueful\Extensions\ExtensionCatalog;
use Glueful\Extensions\Install\ExtensionInstaller;
use Glueful\Extensions\Install\HostCapability;
use Glueful\Extensions\Install\InstallDisabledException;
use Glueful\Extensions\Install\InstallJobStore;
use Glueful\Extensions\Install\PackageNotAllowedException;
use Glueful\Support\Process\ComposerBinaryResolver;
use Glueful\Support\Process\DetachedRunner;
use PHPUnit\Framework\TestCase;

final class ExtensionInstallerTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];
    private ?string $prevComposerEnv = null;

    protected function setUp(): void
    {
        $this->prevComposerEnv = getenv('COMPOSER_BINARY') ?: null;
        putenv('COMPOSER_BINARY=' . PHP_BINARY); // host preflight passes deterministically
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
                $installer->start($bad);
                $this->fail("accepted disallowed package: '{$bad}'");
            } catch (PackageNotAllowedException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_happy_path_creates_job_and_spawns_detached(): void
    {
        $spawned = [];
        [$installer] = $this->make(
            killSwitch: true,
            catalog: ['glueful/aegis'],
            spy: function (array $argv) use (&$spawned): void {
                $spawned[] = $argv;
            },
        );

        $result = $installer->start('glueful/aegis');

        $this->assertSame('queued', $result['status']);
        $this->assertNotEmpty($result['jobId']);
        $this->assertCount(1, $spawned);
        $this->assertContains('glueful/aegis', $spawned[0]);
    }

    public function test_kill_switch_off_throws(): void
    {
        [$installer] = $this->make(killSwitch: false, catalog: ['glueful/aegis']);
        $this->expectException(InstallDisabledException::class);
        $installer->start('glueful/aegis');
    }

    /**
     * @param list<string> $catalog
     * @return array{0:ExtensionInstaller}
     */
    private function make(bool $killSwitch, array $catalog, ?\Closure $spy = null): array
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

        $installer = new ExtensionInstaller(
            $context,
            $fakeCatalog,
            new InstallJobStore(new ArrayCacheDriver()),
            new HostCapability($context, new ComposerBinaryResolver()),
            new DetachedRunner($context, $spy ?? static fn() => null),
        );

        return [$installer];
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

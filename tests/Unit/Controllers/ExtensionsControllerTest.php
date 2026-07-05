<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Controllers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Controllers\BaseController;
use Glueful\Controllers\ExtensionsController;
use Glueful\DTOs\ExtensionInstallData;
use Glueful\DTOs\ExtensionToggleData;
use Glueful\Extensions\ExtensionCatalog;
use Glueful\Extensions\ExtensionManager;
use Glueful\Extensions\Install\ExtensionInstaller;
use Glueful\Extensions\Install\HostCapability;
use Glueful\Support\Process\ComposerBinaryResolver;
use Glueful\Support\Process\ProcessRunner;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * The controller is reflection-injected (BaseController's constructor needs a full
 * container boot, out of scope for a unit test). `requirePermission` is stubbed to
 * record the permission asked for; all other collaborators are real and driven by
 * inputs, so exception→HTTP mapping and toggle branches get genuine coverage.
 */
final class ExtensionsControllerTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];
    private ?string $prevComposerEnv = null;

    protected function setUp(): void
    {
        $this->prevComposerEnv = getenv('COMPOSER_BINARY') ?: null;
        putenv('COMPOSER_BINARY=' . PHP_BINARY);
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

    public function test_install_happy_returns_200_installed_and_checks_edit_permission(): void
    {
        $c = $this->build(killSwitch: true, catalog: ['glueful/aegis']);
        $res = $c->controller->install(new ExtensionInstallData('glueful/aegis'));

        $this->assertSame(200, $res->getStatusCode());
        $this->assertContains('system.config.edit', $c->controller->perms);
    }

    public function test_install_422_when_composer_fails(): void
    {
        $c = $this->build(killSwitch: true, catalog: ['glueful/aegis'], composerExit: 1);
        $this->assertSame(422, $c->controller->install(new ExtensionInstallData('glueful/aegis'))->getStatusCode());
    }

    public function test_install_403_when_kill_switch_off(): void
    {
        $c = $this->build(killSwitch: false, catalog: ['glueful/aegis']);
        $this->assertSame(403, $c->controller->install(new ExtensionInstallData('glueful/aegis'))->getStatusCode());
    }

    public function test_install_422_when_package_not_in_catalog(): void
    {
        $c = $this->build(killSwitch: true, catalog: ['glueful/aegis']);
        $this->assertSame(422, $c->controller->install(new ExtensionInstallData('glueful/other'))->getStatusCode());
    }

    public function test_install_409_when_host_read_only(): void
    {
        $c = $this->build(killSwitch: true, catalog: ['glueful/aegis'], readOnly: true);
        $this->assertSame(409, $c->controller->install(new ExtensionInstallData('glueful/aegis'))->getStatusCode());
    }

    public function test_index_checks_view_permission(): void
    {
        $c = $this->build(killSwitch: true, catalog: ['glueful/aegis']);
        $this->assertSame(200, $c->controller->index()->getStatusCode());
        $this->assertContains('system.config.view', $c->controller->perms);
    }

    public function test_enable_happy_writes_config_and_returns_enabled(): void
    {
        $provider = 'Glueful\\Tests\\Support\\DummyAegisProvider';
        $c = $this->build(killSwitch: true, catalog: [], installedProvider: $provider);

        $res = $c->controller->enable(new ExtensionToggleData('glueful/aegis'));

        $this->assertSame(200, $res->getStatusCode());
        $enabled = (require $c->base . '/config/extensions.php')['enabled'];
        $this->assertContains($provider, $enabled);
        $this->assertFileExists($c->base . '/bootstrap/cache/extensions.php');
    }

    public function test_enable_409_when_host_read_only(): void
    {
        $c = $this->build(killSwitch: true, catalog: [], installedProvider: 'X\\P', readOnly: true);
        $this->assertSame(409, $c->controller->enable(new ExtensionToggleData('glueful/aegis'))->getStatusCode());
    }

    public function test_enable_404_when_not_installed(): void
    {
        $c = $this->build(killSwitch: true, catalog: []);
        $this->assertSame(404, $c->controller->enable(new ExtensionToggleData('glueful/ghost'))->getStatusCode());
    }

    /**
     * @param list<string> $catalog
     */
    private function build(
        bool $killSwitch,
        array $catalog,
        ?string $installedProvider = null,
        bool $readOnly = false,
        int $composerExit = 0,
    ): object {
        $base = sys_get_temp_dir() . '/ectrl_' . bin2hex(random_bytes(6));
        mkdir($base . '/config', 0755, true);
        mkdir($base . '/bootstrap/cache', 0755, true);
        $this->tempDirs[] = $base;
        file_put_contents($base . '/config/extensions.php', "<?php\n\nreturn ['enabled' => []];\n");

        if ($installedProvider !== null) {
            mkdir($base . '/vendor/composer', 0755, true);
            file_put_contents($base . '/vendor/composer/installed.json', (string) json_encode([
                'packages' => [[
                    'name' => 'glueful/aegis',
                    'type' => 'glueful-extension',
                    'version' => '1.2.0',
                    'extra' => ['glueful' => ['provider' => $installedProvider]],
                ]],
            ]));
        }

        $context = ApplicationContext::forTesting($base);
        $context->mergeConfigDefaults('extensions', [
            'install' => ['enabled' => $killSwitch, 'vendor' => 'glueful/'],
        ]);

        $container = new class ($context) implements ContainerInterface {
            public function __construct(private ApplicationContext $context)
            {
            }

            public function get(string $id): mixed
            {
                return $id === ApplicationContext::class ? $this->context
                    : throw new class ("No {$id}") extends \RuntimeException implements
                        \Psr\Container\NotFoundExceptionInterface {};
            }

            public function has(string $id): bool
            {
                return $id === ApplicationContext::class;
            }
        };

        $rows = array_map(
            static fn(string $pkg) => ['package' => $pkg, 'description' => '', 'version' => '1.0.0',
                                       'downloads' => 0, 'repository' => '', 'state' => 'available'],
            $catalog,
        );
        $fakeCatalog = new class ($rows) extends ExtensionCatalog {
            /** @param list<array<string,mixed>> $rows */
            public function __construct(private array $rows)
            {
            }

            public function catalog(bool $refresh = false): array
            {
                return $this->rows;
            }

            public function installed(): array
            {
                return [];
            }
        };

        $host = new HostCapability($context, new ComposerBinaryResolver());
        $extensions = new ExtensionManager($container);
        // Fake runner so install() never shells out to real composer in a unit test.
        $runner = new class ($composerExit) implements ProcessRunner {
            public function __construct(private int $exitCode)
            {
            }

            public function run(array $cmd, string $cwd, float $timeout, ?callable $onOutput = null, ?array $env = null): array
            {
                return ['exitCode' => $this->exitCode, 'output' => $this->exitCode === 0 ? 'done' : 'boom'];
            }
        };
        $installer = new ExtensionInstaller($context, $fakeCatalog, $host, $runner);

        if ($readOnly) {
            chmod($base . '/bootstrap/cache', 0555);
            chmod($base . '/config', 0555);
            chmod($base, 0555);
        }

        $controller = new class extends ExtensionsController {
            /** @var list<string> */
            public array $perms = [];

            // phpcs:ignore
            public function __construct()
            {
                // Skip BaseController's container-dependent constructor.
            }

            protected function requirePermission(string $permission, string $resource = 'system', array $context = []): void
            {
                $this->perms[] = $permission;
            }

            protected function getCurrentUserUuid(): ?string
            {
                return 'tester';
            }
        };

        $this->inject($controller, BaseController::class, 'context', $context);
        $this->inject($controller, ExtensionsController::class, 'catalog', $fakeCatalog);
        $this->inject($controller, ExtensionsController::class, 'installer', $installer);
        $this->inject($controller, ExtensionsController::class, 'host', $host);
        $this->inject($controller, ExtensionsController::class, 'extensions', $extensions);
        $this->inject($controller, ExtensionsController::class, 'auditLog', new NullLogger());

        return (object) ['controller' => $controller, 'base' => $base];
    }

    private function inject(object $obj, string $class, string $prop, mixed $value): void
    {
        $rp = new \ReflectionProperty($class, $prop);
        $rp->setAccessible(true);
        $rp->setValue($obj, $value);
    }

    private function rmrf(string $dir): void
    {
        if (!file_exists($dir)) {
            return;
        }
        @chmod($dir, 0755);
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

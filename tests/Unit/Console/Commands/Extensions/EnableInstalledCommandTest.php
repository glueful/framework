<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Console\Commands\Extensions;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Console\Commands\Extensions\EnableInstalledCommand;
use Glueful\Extensions\ExtensionManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class EnableInstalledCommandTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->rmrf($dir);
        }
    }

    public function test_emits_ok_false_json_when_package_not_a_candidate(): void
    {
        $base = $this->makeApp(withCandidate: false);
        $tester = $this->runCommand($base, 'glueful/does-not-exist');

        $decoded = json_decode(trim($tester->getDisplay()), true);
        $this->assertFalse($decoded['ok']);
        $this->assertNotEmpty($decoded['error']);
    }

    public function test_happy_path_writes_config_recompiles_cache_and_emits_ok_true(): void
    {
        $provider = 'Glueful\\Tests\\Support\\DummyAegisProvider';
        $base = $this->makeApp(withCandidate: true, provider: $provider);

        $tester = $this->runCommand($base, 'glueful/aegis');

        // 1) JSON contract
        $decoded = json_decode(trim($tester->getDisplay()), true);
        $this->assertTrue($decoded['ok']);
        $this->assertSame($provider, $decoded['provider']);

        // 2) config/extensions.php now lists the provider FQCN
        $enabled = (require $base . '/config/extensions.php')['enabled'];
        $this->assertContains($provider, $enabled);

        // 3) writeCacheNow() ran — its side effect is the compiled cache file
        $this->assertFileExists($base . '/bootstrap/cache/extensions.php');
    }

    private function runCommand(string $base, string $package): CommandTester
    {
        $context = ApplicationContext::forTesting($base);
        $container = $this->container($context);
        $command = new EnableInstalledCommand($container, $context);
        $tester = new CommandTester($command);
        $tester->execute(['package' => $package]);
        return $tester;
    }

    private function makeApp(bool $withCandidate, string $provider = 'Glueful\\X\\Provider'): string
    {
        $base = sys_get_temp_dir() . '/eic_' . bin2hex(random_bytes(6));
        mkdir($base . '/config', 0755, true);
        mkdir($base . '/bootstrap/cache', 0755, true);
        $this->tempDirs[] = $base;

        file_put_contents($base . '/config/extensions.php', "<?php\n\nreturn ['enabled' => []];\n");

        if ($withCandidate) {
            mkdir($base . '/vendor/composer', 0755, true);
            file_put_contents($base . '/vendor/composer/installed.json', (string) json_encode([
                'packages' => [[
                    'name' => 'glueful/aegis',
                    'type' => 'glueful-extension',
                    'version' => '1.2.0',
                    'extra' => ['glueful' => ['provider' => $provider]],
                ]],
            ]));
        }

        return $base;
    }

    private function container(ApplicationContext $context): ContainerInterface
    {
        return new class ($context) implements ContainerInterface {
            private ExtensionManager $extensions;

            public function __construct(private ApplicationContext $context)
            {
                $this->extensions = new ExtensionManager($this);
            }

            public function get(string $id): mixed
            {
                return match ($id) {
                    ApplicationContext::class => $this->context,
                    ExtensionManager::class => $this->extensions,
                    default => throw new class ("No service {$id}") extends \RuntimeException implements
                        \Psr\Container\NotFoundExceptionInterface {},
                };
            }

            public function has(string $id): bool
            {
                return in_array($id, [ApplicationContext::class, ExtensionManager::class], true);
            }
        };
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

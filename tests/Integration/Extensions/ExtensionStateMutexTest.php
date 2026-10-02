<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Extensions;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\ConfigurationLoader;
use Glueful\Extensions\ExtensionManager;
use Glueful\Extensions\ExtensionStateMutex;
use Glueful\Extensions\ExtensionStateWriter;
use Glueful\Extensions\ServiceProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Every change to the enabled list holds one lock, from reading the list (or resolving providers)
 * through rebuilding the extension cache, so two changes can't overwrite each other.
 *
 * The suite runs on SQLite, so the process handshake here exercises the file-lock implementation;
 * the PostgreSQL advisory-lock implementation is proven by the applications that use it.
 */
final class ExtensionStateMutexTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/glueful-state-mutex-' . bin2hex(random_bytes(4));
        mkdir($this->base . '/config', 0777, true);
        mkdir($this->base . '/vendor/composer', 0777, true);
        file_put_contents($this->base . '/config/extensions.php', "<?php\n\nreturn [\n    'enabled' => [\n    ],\n];\n");
    }

    protected function tearDown(): void
    {
        ExtensionStateMutex::$afterAcquire = null;
        self::removeTree($this->base);
    }

    public function testASecondHolderWaitsUntilTheFirstReleasesAndBothWritesSurvive(): void
    {
        $a = $this->child('Vendor\\Alpha\\Provider', pause: true);
        $this->readUntil($a, 'holding');
        $b = $this->child('Vendor\\Beta\\Provider');
        $this->readUntil($b, 'attempting');
        usleep(1_200_000);
        self::assertTrue(proc_get_status($b['proc'])['running'], 'the second writer waits while the first holds the lock');

        fwrite($a['pipes'][0], "resume\n");
        $this->finish($a);
        $this->finish($b);

        $enabled = (require $this->base . '/config/extensions.php')['enabled'];
        self::assertContains('Vendor\\Alpha\\Provider', $enabled);
        self::assertContains('Vendor\\Beta\\Provider', $enabled);
    }

    public function testRebuildingTheCacheResolvesProvidersOnlyAfterTakingTheMutex(): void
    {
        // An enable that committed while this rebuild waited for the lock: its provider must be in
        // the cache, because providers are resolved inside the lock, after the config cache is cleared.
        $provider = RebuildCacheAlphaProvider::class;
        file_put_contents($this->base . '/vendor/composer/installed.json', json_encode(['packages' => [[
            'name' => 'acme/alpha',
            'type' => 'glueful-extension',
            'install-path' => '../acme/alpha',
            'extra' => ['glueful' => ['provider' => $provider, 'migrations' => 'none']],
        ]]], JSON_UNESCAPED_SLASHES));
        $context = $this->context();
        $manager = new ExtensionManager($this->containerFor($context));
        $context->getConfig('extensions');      // the enabled list is read (and cached) before the wait
        ExtensionStateMutex::$afterAcquire = function () use ($provider): void {
            (new ExtensionStateWriter())->enable($this->base . '/config/extensions.php', $provider);
        };

        $result = $manager->rebuildCache();

        self::assertSame([], $result['errors']);
        $cached = (require $this->base . '/bootstrap/cache/extensions.php')['providers'];
        self::assertContains($provider, $cached);
    }

    private function context(): ApplicationContext
    {
        $context = new ApplicationContext($this->base, 'testing', [
            'framework' => $this->base . '/config',
            'application' => $this->base . '/config',
        ]);
        $context->setConfigLoader(new ConfigurationLoader($this->base, 'testing', $this->base . '/config'));
        return $context;
    }

    private function containerFor(ApplicationContext $context): ContainerInterface
    {
        $container = new class ($context) implements ContainerInterface {
            public function __construct(private readonly ApplicationContext $context)
            {
            }

            public function get(string $id): mixed
            {
                if ($id === ApplicationContext::class) {
                    return $this->context;
                }
                throw new \RuntimeException("Unexpected service: {$id}");
            }

            public function has(string $id): bool
            {
                return $id === ApplicationContext::class;
            }
        };
        $context->setContainer($container);
        return $container;
    }

    /** @return array{proc: resource, pipes: array<int, resource>} */
    private function child(string $provider, bool $pause = false): array
    {
        $cmd = [PHP_BINARY, dirname(__DIR__, 2) . '/fixtures/extension_state_child.php', $this->base, $provider];
        if ($pause) {
            $cmd[] = '--pause';
        }
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($proc);
        return ['proc' => $proc, 'pipes' => $pipes];
    }

    /** @param array{proc: resource, pipes: array<int, resource>} $child */
    private function readUntil(array $child, string $marker): void
    {
        $deadline = microtime(true) + 15;
        while (microtime(true) < $deadline) {
            $line = fgets($child['pipes'][1]);
            if ($line !== false && trim($line) === $marker) {
                return;
            }
            if ($line === false) {
                usleep(20_000);
            }
        }
        self::fail("child never printed {$marker}: " . stream_get_contents($child['pipes'][2]));
    }

    /** @param array{proc: resource, pipes: array<int, resource>} $child */
    private function finish(array $child): void
    {
        fclose($child['pipes'][0]);
        $out = stream_get_contents($child['pipes'][1]) . stream_get_contents($child['pipes'][2]);
        self::assertSame(0, proc_close($child['proc']), $out);
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}

final class RebuildCacheAlphaProvider extends ServiceProvider
{
}

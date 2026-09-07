<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ExtensionManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Production must boot from the compiled extension cache — but a checkout that has never been
 * installed (no security keys in .env) cannot have one yet, and the command that would build
 * it needs a booted framework. On first run, production resolves live ONCE and writes the
 * cache; once installed, a missing cache is a deploy mistake and still fails loudly.
 */
final class FirstRunDiscoveryTest extends TestCase
{
    private string $base;
    private ?string $previousEnv = null;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/glueful-first-run-' . uniqid('', true);
        mkdir($this->base, 0755, true);
        $this->previousEnv = $_ENV['APP_ENV'] ?? null;
        $_ENV['APP_ENV'] = 'production';
    }

    protected function tearDown(): void
    {
        if ($this->previousEnv === null) {
            unset($_ENV['APP_ENV']);
        } else {
            $_ENV['APP_ENV'] = $this->previousEnv;
        }
        @unlink($this->base . '/bootstrap/cache/extensions.php');
        @rmdir($this->base . '/bootstrap/cache');
        @rmdir($this->base . '/bootstrap');
        @unlink($this->base . '/.env');
        @rmdir($this->base);
    }

    public function testAnUninstalledProductionCheckoutResolvesLiveAndWritesTheCache(): void
    {
        file_put_contents($this->base . '/.env', "APP_ENV=production\nAPP_KEY=\nJWT_KEY=\nTOKEN_SALT=\n");
        $manager = new ExtensionManager($this->container());

        $manager->discover();

        self::assertFileExists(
            $this->base . '/bootstrap/cache/extensions.php',
            'first run writes the cache so the next boot is a normal production boot',
        );
    }

    public function testAnInstalledProductionCheckoutWithoutACacheStillFailsLoudly(): void
    {
        file_put_contents($this->base . '/.env', "APP_ENV=production\nAPP_KEY=a\nJWT_KEY=b\nTOKEN_SALT=c\n");
        $manager = new ExtensionManager($this->container());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Extension cache missing in production');

        $manager->discover();
    }

    private function container(): ContainerInterface
    {
        $context = new ApplicationContext($this->base, 'production');

        return new class ($context) implements ContainerInterface {
            public function __construct(private readonly ApplicationContext $context)
            {
            }

            public function get(string $id): mixed
            {
                if ($id === ApplicationContext::class) {
                    return $this->context;
                }
                throw new \LogicException("unexpected service {$id}");
            }

            public function has(string $id): bool
            {
                return $id === ApplicationContext::class;
            }
        };
    }
}

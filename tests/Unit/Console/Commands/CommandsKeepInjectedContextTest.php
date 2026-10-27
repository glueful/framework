<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Console\Commands;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\CacheStore;
use Glueful\Console\BaseCommand;
use Glueful\Extensions\ExtensionManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * CommandFactory and the console container hand every command the app's booted container and
 * context. A command constructor that drops them (`__construct() { parent::__construct(); }`) makes
 * BaseCommand build a fresh context from getcwd() and a never-booted container, so the command
 * works on different state than the app: cache:* on an unconfigured store, extensions:* without
 * the app's extensions, migrate:create writing into whatever directory the process started in.
 */
final class CommandsKeepInjectedContextTest extends TestCase
{
    public function testEveryBaseCommandKeepsTheContainerAndContextItIsGiven(): void
    {
        $context = new ApplicationContext(sys_get_temp_dir() . '/glueful-ctx-' . uniqid(), 'testing');
        $container = $this->container($context);
        $checked = 0;

        foreach ($this->commandClasses() as $class) {
            $command = new $class($container, $context);

            self::assertSame($context, $this->call($command, 'getContext'), "{$class} dropped the injected context");
            self::assertSame($container, $this->call($command, 'getContainer'), "{$class} dropped the injected container");
            $checked++;
        }

        self::assertGreaterThan(50, $checked);
    }

    /**
     * @return list<class-string<BaseCommand>>
     */
    private function commandClasses(): array
    {
        $root = dirname(__DIR__, 4) . '/src';
        $classes = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $src = (string) file_get_contents($file->getPathname());
            if (!str_contains($src, '#[AsCommand') || preg_match('/namespace\s+([^;]+);/', $src, $ns) !== 1) {
                continue;
            }
            $class = $ns[1] . '\\' . $file->getBasename('.php');
            if (!class_exists($class) || !is_subclass_of($class, BaseCommand::class)) {
                continue;
            }
            if ((new \ReflectionClass($class))->isAbstract()) {
                continue;
            }
            $classes[] = $class;
        }
        sort($classes);

        return $classes;
    }

    private function call(object $command, string $method): mixed
    {
        $ref = new \ReflectionMethod($command, $method);

        return $ref->invoke($command);
    }

    private function container(ApplicationContext $context): ContainerInterface
    {
        $services = [
            ApplicationContext::class => $context,
            CacheStore::class => $this->createStub(CacheStore::class),
            ExtensionManager::class => (new \ReflectionClass(ExtensionManager::class))->newInstanceWithoutConstructor(),
        ];

        return new class ($services) implements ContainerInterface {
            /** @param array<string, mixed> $services */
            public function __construct(private array $services)
            {
            }

            public function get(string $id): mixed
            {
                if (!array_key_exists($id, $this->services)) {
                    throw new class ("no {$id}") extends \RuntimeException implements NotFoundExceptionInterface {
                    };
                }

                return $this->services[$id];
            }

            public function has(string $id): bool
            {
                return array_key_exists($id, $this->services);
            }
        };
    }
}

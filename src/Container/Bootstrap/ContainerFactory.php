<?php

declare(strict_types=1);

namespace Glueful\Container\Bootstrap;

use Glueful\Container\Container;
use Glueful\Container\Support\ParamBag;
use Glueful\Container\Definition\{ValueDefinition, TaggedIteratorDefinition};
use Glueful\Container\Compile\ContainerCompiler;
use Glueful\Container\Providers\{TagCollector, BaseServiceProvider};
use Psr\Container\ContainerInterface;

final class ContainerFactory
{
    public static function create(bool $prod = false): ContainerInterface
    {
        $tags = new TagCollector();
        $defs = [];

        foreach (self::providers($tags) as $provider) {
            /** @var BaseServiceProvider $provider */
            $defs += $provider->defs();
        }

        $defs['param.bag'] = new ValueDefinition('param.bag', new ParamBag([
            'env' => $prod ? 'prod' : 'dev',
        ]));

        foreach ($tags->all() as $name => $entries) {
            $defs[$name] = new TaggedIteratorDefinition($name, $entries);
        }

        $container = new Container($defs);

        if ($prod) {
            try {
                $defsRef = (new \ReflectionClass($container))->getProperty('definitions');
                $defsRef->setAccessible(true);
                /** @var array<string, mixed> $normalized */
                $normalized = $defsRef->getValue($container);

                $compiler = new ContainerCompiler();
                $php = $compiler->compile($normalized, 'CompiledContainer', 'Glueful\\Container\\Compiled');
                $cacheFile = sys_get_temp_dir() . '/glueful_compiled_container.php';
                file_put_contents($cacheFile, $php);
                require_once $cacheFile;

                $compiledClass = '\\Glueful\\Container\\Compiled\\CompiledContainer';
                if (class_exists($compiledClass)) {
                    /** @var ContainerInterface $compiled */
                    $compiled = new $compiledClass();
                    return $compiled;
                }
            } catch (\Throwable $e) {
                // Best-effort compilation. Continue with runtime container if unsupported definitions exist.
                // Optionally log: error_log('[ContainerFactory] compile skipped: ' . $e->getMessage());
            }
        }

        return $container;
    }

    /** @return iterable<BaseServiceProvider> */
    private static function providers(TagCollector $tags): iterable
    {
        /** @var array<class-string<BaseServiceProvider>> $classes */
        $classes = [
            \Glueful\Container\Providers\CoreProvider::class,
            \Glueful\Container\Providers\ConsoleProvider::class,
        ];

        if ($classes === []) {
            return [];
        }

        return array_map(
            static fn (string $class): BaseServiceProvider => new $class($tags),
            $classes
        );
    }
}

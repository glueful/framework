<?php

declare(strict_types=1);

namespace Glueful\Container\Bootstrap;

use Glueful\Container\Container;
use Glueful\Container\Support\ParamBag;
use Glueful\Container\Definition\{ValueDefinition, TaggedIteratorDefinition};
use Glueful\Container\Compile\ContainerCompiler;
use Glueful\Container\Providers\{TagCollector, BaseServiceProvider};
use Glueful\Container\Loader\{ServicesLoader, DefaultServicesLoader};
use Glueful\Extensions\ProviderLocator;
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

        // Merge extension-provided service definitions (typed or DSL)
        $defs += self::loadExtensionDefinitions($tags, $prod);

        $defs['param.bag'] = new ValueDefinition('param.bag', new ParamBag([
            'env' => $prod ? 'prod' : 'dev',
        ]));

        foreach ($tags->all() as $name => $entries) {
            $defs[$name] = new TaggedIteratorDefinition($name, $entries);
        }

        $container = new Container($defs);

        if ($prod) {
            // Prefer precompiled container dumped by CLI
            $precompiled = self::loadPrecompiledIfAvailable();
            if ($precompiled !== null) {
                return $precompiled;
            }

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

    private static function loadPrecompiledIfAvailable(): ?ContainerInterface
    {
        $root = dirname(__DIR__, 3);
        $primary = $root . '/storage/cache/container/CompiledContainer.php';

        if (!is_file($primary)) {
            return null;
        }

        try {
            require_once $primary;
            $compiledClass = '\\Glueful\\Container\\Compiled\\CompiledContainer';
            if (class_exists($compiledClass)) {
                /** @var ContainerInterface $compiled */
                $compiled = new $compiledClass();
                return $compiled;
            }
        } catch (\Throwable $e) {
            // Ignore and fall back to runtime container
        }

        return null;
    }

    /** @return iterable<BaseServiceProvider> */
    private static function providers(TagCollector $tags): iterable
    {
        /** @var array<class-string<BaseServiceProvider>> $classes */
        $classes = [
            \Glueful\Container\Providers\CoreProvider::class,
            \Glueful\Container\Providers\ConsoleProvider::class,
            \Glueful\Container\Providers\StorageProvider::class,
        ];
        $classes = array_merge($classes, [
            \Glueful\Container\Providers\RequestProvider::class,
            \Glueful\Validation\ServiceProvider\ValidationProvider::class,
            \Glueful\Serialization\ServiceProvider\SerializerProvider::class,
            \Glueful\Http\ServiceProvider\HttpClientProvider::class,
            \Glueful\Container\Providers\RepositoryProvider::class,
            \Glueful\Events\ServiceProvider\EventProvider::class,
            \Glueful\Container\Providers\ExtensionProvider::class,
            \Glueful\Queue\ServiceProvider\QueueProvider::class,
            \Glueful\Container\Providers\LockProvider::class,
            \Glueful\Services\Archive\ServiceProvider\ArchiveProvider::class,
            \Glueful\Container\Providers\FileProvider::class,
            \Glueful\Container\Providers\SpaProvider::class,
            \Glueful\Container\Providers\HttpPsr15Provider::class,
            \Glueful\Container\Providers\VarDumperProvider::class,
            \Glueful\Container\Providers\ImageProvider::class,
            \Glueful\Container\Providers\ControllerProvider::class,
            \Glueful\Container\Providers\LazyProvider::class,
            \Glueful\Security\ServiceProvider\SecurityProvider::class,
            \Glueful\Permissions\ServiceProvider\PermissionsProvider::class,
            \Glueful\Permissions\ServiceProvider\GateProvider::class,
            \Glueful\Tasks\ServiceProvider\TasksProvider::class,
        ]);

        if ($classes === []) {
            return [];
        }

        return array_map(
            static fn (string $class): BaseServiceProvider => new $class($tags),
            $classes
        );
    }

    /**
     * Load extension service definitions from discovered providers.
     * Supports either typed defs() or DSL services().
     *
     * @return array<string, mixed>
     */
    private static function loadExtensionDefinitions(TagCollector $tags, bool $prod): array
    {
        $defs = [];
        $loader = self::dslLoader();
        foreach (ProviderLocator::all() as $providerClass) {
            if (!class_exists($providerClass)) {
                continue;
            }

            // Prefer strongly-typed defs()
            if (method_exists($providerClass, 'defs')) {
                try {
                    /** @var array<string, mixed> $typed */
                    $typed = (array) $providerClass::defs();
                    $defs += $typed;
                    // Optional: apply tags declared by provider
                    self::applyProviderTags($providerClass, $tags);
                } catch (\Throwable $e) {
                    error_log("[Container] Failed loading defs() from {$providerClass}: " . $e->getMessage());
                }
                // If both defs() and services() exist, defs() wins; skip DSL
                continue;
            }

            // Fallback to DSL services()
            if (method_exists($providerClass, 'services')) {
                try {
                    /** @var array<string, mixed> $dsl */
                    $dsl = (array) $providerClass::services();
                    // Apply tag hints from DSL to TagCollector
                    self::applyDslTags($dsl, $tags);
                    $compiled = $loader->load($dsl, $providerClass, $prod);
                    $defs += $compiled;
                } catch (\Throwable $e) {
                    error_log("[Container] Failed loading services() from {$providerClass}: " . $e->getMessage());
                }
            }
        }

        return $defs;
    }

    private static function dslLoader(): ServicesLoader
    {
        // In the future this could be configurable/replaced
        return new DefaultServicesLoader();
    }

    /**
     * @param array<string, mixed> $dsl
     */
    private static function applyDslTags(array $dsl, TagCollector $tags): void
    {
        foreach ($dsl as $id => $spec) {
            if (!is_array($spec) || !isset($spec['tags'])) {
                continue;
            }
            $tagSpec = $spec['tags'];
            if (is_array($tagSpec)) {
                foreach ($tagSpec as $t) {
                    if (is_string($t) && $t !== '') {
                        $tags->add($t, (string) $id, 0);
                    } elseif (is_array($t) && isset($t['name'])) {
                        $name = (string) $t['name'];
                        $priority = (int) ($t['priority'] ?? 0);
                        if ($name !== '') {
                            $tags->add($name, (string) $id, $priority);
                        }
                    }
                }
            }
        }
    }

    /**
     * Apply tags published by a typed provider via static tags().
     * tags() may return:
     *  [ 'tag.name' => [ 'service.id', ['service' => 'id', 'priority' => 10], ... ], ... ]
     */
    private static function applyProviderTags(string $providerClass, TagCollector $tags): void
    {
        if (!method_exists($providerClass, 'tags')) {
            return;
        }
        try {
            /** @var array<string, array<int, string|array<string,mixed>>> $map */
            $map = (array) $providerClass::tags();
        } catch (\Throwable $e) {
            error_log("[Container] Failed reading tags() from {$providerClass}: " . $e->getMessage());
            return;
        }

        foreach ($map as $name => $entries) {
            if (!is_array($entries)) {
                continue;
            }
            foreach ($entries as $entry) {
                if (is_string($entry) && $entry !== '') {
                    $tags->add((string) $name, $entry, 0);
                } elseif (is_array($entry)) {
                    $svc = isset($entry['service']) && is_string($entry['service']) ? $entry['service'] : '';
                    if ($svc !== '') {
                        $prio = (int) ($entry['priority'] ?? 0);
                        $tags->add((string) $name, $svc, $prio);
                    }
                }
            }
        }
    }
}

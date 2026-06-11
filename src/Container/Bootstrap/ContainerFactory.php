<?php

declare(strict_types=1);

namespace Glueful\Container\Bootstrap;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Container;
use Glueful\Container\Support\ParamBag;
use Glueful\Container\Definition\{ValueDefinition, TaggedIteratorDefinition};
use Glueful\Container\Compile\ContainerCompiler;
use Glueful\Container\Providers\{TagCollector, BaseServiceProvider};
use Glueful\Container\Loader\{ServicesLoader, DefaultServicesLoader};
use Glueful\Extensions\ProviderClassResolver;
use Glueful\Container\Exception\ContainerException;
use Psr\Container\ContainerInterface;

final class ContainerFactory
{
    /**
     * Service ids the framework owns and re-pins AFTER the extension merge, so an extension
     * binding the same id cannot clobber them. `ApplicationContext` and `param.bag` are
     * re-pinned in pinReservedDefinitions() before construction; `ContainerInterface` is
     * self-registered on the built container. Listed here as the single reviewable record
     * of the protected surface.
     */
    private const RESERVED_KEYS = [
        ApplicationContext::class,
        'param.bag',
        ContainerInterface::class,
    ];

    public static function create(ApplicationContext $context, bool $prod = false): ContainerInterface
    {
        $tags = new TagCollector();
        $defs = [];

        foreach (self::providers($tags, $context) as $provider) {
            /** @var BaseServiceProvider $provider */
            $defs += $provider->defs();
        }

        // Make ApplicationContext available for autowiring.
        $defs[ApplicationContext::class] = new ValueDefinition(ApplicationContext::class, $context);

        // Merge extension-provided service definitions (typed or DSL).
        // Extensions OVERRIDE core defaults for the same id (real seams); see mergeExtensionDefs.
        $defs = self::mergeExtensionDefs($defs, self::loadExtensionDefinitions($tags, $context, $prod));

        // Re-pin framework-reserved keys (see RESERVED_KEYS) that an extension must not clobber.
        self::pinReservedDefinitions($defs, $context, $prod);

        // Only tags that at least one service contributed to get a TaggedIteratorDefinition.
        // A tag with zero contributors has no binding, so `$c->get('some.tag')` throws
        // NotFoundException rather than returning [] -- consumers of an optional, extension-
        // contributed tag must `has()`-check (or catch) before iterating it on a fresh install.
        foreach ($tags->all() as $name => $entries) {
            $defs[$name] = new TaggedIteratorDefinition($name, $entries);
        }

        $container = new Container($defs);

        // Self-register so autowiring can inject the container (e.g. into CLI commands)
        $container->load([ContainerInterface::class => new ValueDefinition(ContainerInterface::class, $container)]);

        if ($prod) {
            // Prefer precompiled container dumped by CLI
            $precompiled = self::loadPrecompiledIfAvailable();
            if ($precompiled !== null) {
                return $precompiled;
            }

            try {
                $defsRef = (new \ReflectionClass($container))->getProperty('definitions');
                /** @var array<string, mixed> $normalized */
                $normalized = $defsRef->getValue($container);

                $compiler = new ContainerCompiler();
                $php = $compiler->compile($normalized, 'CompiledContainer', 'Glueful\\Container\\Compiled');
                $cacheFile = sys_get_temp_dir() . '/glueful_compiled_container.php';
                file_put_contents($cacheFile, $php);
                // Emit a simple services map artifact alongside compiled container
                $map = [];
                foreach ($normalized as $id => $def) {
                    $type = is_object($def) ? get_class($def) : gettype($def);
                    $alias = $def instanceof \Glueful\Container\Definition\AliasDefinition ? $def->getTarget() : '';
                    $map[] = ['id' => (string) $id, 'type' => $type, 'alias_of' => $alias];
                }
                file_put_contents(sys_get_temp_dir() . '/glueful_services_map.json', json_encode($map));
                require_once $cacheFile;

                $compiledClass = '\\Glueful\\Container\\Compiled\\CompiledContainer';
                if (class_exists($compiledClass)) {
                    /** @var ContainerInterface $compiled */
                    $compiled = new $compiledClass();
                    return $compiled;
                }
            } catch (\Throwable $e) {
                // Best-effort compilation: fall back to the runtime container if a definition
                // cannot be compiled (e.g. a closure factory). Log loudly -- a silent fallback
                // means production runs uncompiled (slower) with no signal as to why.
                error_log(
                    '[Container][WARNING] container compilation failed; '
                    . 'falling back to the runtime container: ' . $e->getMessage()
                );
            }
        }

        return $container;
    }

    /**
     * Merge extension-provided definitions OVER the core defaults.
     *
     * Extension bindings override core bindings for the same id (last-layer-wins),
     * which is what makes core defaults (NullUserProvider, and any seam) real,
     * overridable seams. `array_replace` -- NOT `+=`, which keeps the core key and
     * silently drops the extension's override.
     *
     * @internal
     * @param array<string,mixed> $coreDefs
     * @param array<string,mixed> $extDefs
     * @return array<string,mixed>
     */
    public static function mergeExtensionDefs(array $coreDefs, array $extDefs): array
    {
        return array_replace($coreDefs, $extDefs);
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
    private static function providers(TagCollector $tags, ApplicationContext $context): iterable
    {
        $classes = [
            \Glueful\Container\Providers\CoreProvider::class,
            \Glueful\Container\Providers\ORMProvider::class,
            \Glueful\Container\Providers\ConsoleProvider::class,
            \Glueful\Container\Providers\StorageProvider::class,
        ];
        $classes = array_merge($classes, [
            \Glueful\Container\Providers\ExceptionProvider::class,
            \Glueful\Container\Providers\RequestProvider::class,
            \Glueful\Validation\ServiceProvider\ValidationProvider::class,
            \Glueful\Serialization\ServiceProvider\SerializerProvider::class,
            \Glueful\Http\ServiceProvider\HttpClientProvider::class,
            \Glueful\Container\Providers\RepositoryProvider::class,
            \Glueful\Container\Providers\AuthProvider::class,
            \Glueful\Container\Providers\NotificationsProvider::class,
            \Glueful\Events\ServiceProvider\EventProvider::class,
            \Glueful\Container\Providers\ExtensionProvider::class,
            \Glueful\Queue\ServiceProvider\QueueProvider::class,
            \Glueful\Container\Providers\LockProvider::class,
            \Glueful\Container\Providers\FileProvider::class,
            \Glueful\Container\Providers\SpaProvider::class,
            \Glueful\Container\Providers\HttpPsr15Provider::class,
            \Glueful\Container\Providers\VarDumperProvider::class,
            \Glueful\Container\Providers\ControllerProvider::class,
            \Glueful\Container\Providers\LazyProvider::class,
            \Glueful\Security\ServiceProvider\SecurityProvider::class,
            \Glueful\Permissions\ServiceProvider\PermissionsProvider::class,
            \Glueful\Permissions\ServiceProvider\GateProvider::class,
            \Glueful\Tasks\ServiceProvider\TasksProvider::class,
            \Glueful\Container\Providers\ApiVersioningProvider::class,
        ]);

        if ($classes === []) {
            return [];
        }

        return array_map(
            static fn (string $class): BaseServiceProvider => new $class($tags, $context),
            $classes
        );
    }

    /**
     * Load extension service definitions from discovered providers.
     * Supports either typed defs() or DSL services().
     *
     * @return array<string, mixed>
     */
    private static function loadExtensionDefinitions(TagCollector $tags, ApplicationContext $context, bool $prod): array
    {
        $defs = [];
        $loader = self::dslLoader();
        // Same resolution path as ExtensionManager — the shared, stateless resolver.
        // It takes only the context, so it works during container construction
        // (no need to resolve anything from the not-yet-built container).
        $providerClasses = (new ProviderClassResolver())->resolve($context)->providers;
        foreach ($providerClasses as $providerClass) {
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
                    self::applyProviderTags($providerClass, $tags, $prod);
                } catch (ContainerException $e) {
                    throw $e; // already handled (e.g. by applyProviderTags); don't re-wrap
                } catch (\Throwable $e) {
                    self::handleProviderLoadFailure($providerClass, 'defs()', $e, $prod);
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
                    self::handleProviderLoadFailure($providerClass, 'services()', $e, $prod);
                }
            }
        }

        return $defs;
    }

    /**
     * @var array<int, array{provider: string, phase: string, error: string}>
     *      Providers whose definitions failed to load during the last container build.
     *      Populated only in production (outside production a failure is rethrown). Lets
     *      diagnostics (extensions:diagnose / a health endpoint) surface partial boot.
     */
    private static array $failedProviders = [];

    /**
     * Providers that failed to load (production only -- non-production fails loud).
     *
     * @return array<int, array{provider: string, phase: string, error: string}>
     */
    public static function failedProviders(): array
    {
        return self::$failedProviders;
    }

    public static function clearFailedProviders(): void
    {
        self::$failedProviders = [];
    }

    /**
     * Handle an extension provider failing to contribute its definitions/tags.
     *
     * Outside production: rethrow (wrapped, naming the provider + phase) so the broken
     * extension is caught at boot instead of surfacing as a missing service -- or a
     * silently-served core default -- at runtime. In production: record it and log loudly,
     * but keep booting so one broken extension cannot take the whole app down.
     */
    private static function handleProviderLoadFailure(
        string $providerClass,
        string $phase,
        \Throwable $e,
        bool $prod
    ): void {
        self::$failedProviders[] = [
            'provider' => $providerClass,
            'phase' => $phase,
            'error' => $e->getMessage(),
        ];

        if (!$prod) {
            throw new ContainerException(
                "Extension provider {$providerClass} failed to load ({$phase}): {$e->getMessage()}",
                0,
                $e
            );
        }

        error_log(
            "[Container][WARNING] Extension provider {$providerClass} failed to load ({$phase}) "
            . "and was skipped: {$e->getMessage()}"
        );
    }

    /**
     * Framework-owned service ids that extensions cannot override (re-pinned post-merge).
     * The single reviewable record of the protected surface.
     *
     * @return array<int, string>
     */
    public static function reservedKeys(): array
    {
        return self::RESERVED_KEYS;
    }

    /**
     * Re-pin the framework-owned definitions (see RESERVED_KEYS) after the extension merge,
     * so an extension that binds the same id cannot replace them. Runs before container
     * construction; `ContainerInterface` is self-registered separately on the built container.
     *
     * @param array<string, mixed> $defs
     */
    private static function pinReservedDefinitions(array &$defs, ApplicationContext $context, bool $prod): void
    {
        $defs[ApplicationContext::class] = new ValueDefinition(ApplicationContext::class, $context);
        $defs['param.bag'] = new ValueDefinition('param.bag', new ParamBag([
            'env' => $prod ? 'prod' : 'dev',
        ]));
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
    private static function applyProviderTags(string $providerClass, TagCollector $tags, bool $prod = true): void
    {
        if (!method_exists($providerClass, 'tags')) {
            return;
        }
        try {
            /** @var array<string, array<int, string|array<string,mixed>>> $map */
            $map = (array) $providerClass::tags();
        } catch (\Throwable $e) {
            self::handleProviderLoadFailure($providerClass, 'tags()', $e, $prod);
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

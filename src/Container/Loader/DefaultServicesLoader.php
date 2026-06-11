<?php

declare(strict_types=1);

namespace Glueful\Container\Loader;

use Glueful\Container\Definition\{DefinitionInterface, FactoryDefinition, AliasDefinition};
use Glueful\Container\Autowire\AutowireDefinition;

final class DefaultServicesLoader implements ServicesLoader
{
    /** @var array<string, string> */
    private static array $providerMap = [];

    /**
     * Return a map of service id => provider class for the most recent loads.
     * This is best-effort and only populated for DSL-provided services.
     * @return array<string, string>
     */
    public static function getProviderMap(): array
    {
        return self::$providerMap;
    }

    /** @inheritDoc */
    public function load(array $dsl, ?string $providerClass = null, bool $prod = false): array
    {
        $out = [];
        foreach ($dsl as $id => $spec) {
            if (!is_array($spec)) {
                throw new \InvalidArgumentException($this->ctx($providerClass, "Service '$id' must be an array"));
            }

            // Shorthands
            if (array_key_exists('singleton', $spec)) {
                $spec['shared'] = (bool) $spec['singleton'];
            }
            if (array_key_exists('bind', $spec)) {
                // Bind false → not shared; Bind true → shared
                $spec['shared'] = (bool) $spec['bind'];
            }

            $class = $spec['class'] ?? null;
            if ($class === null && is_string($id)) {
                // Infer class from id for namespaced ids or known classes (e.g., built-ins like ArrayObject)
                if (str_contains($id, '\\') || class_exists($id)) {
                    $class = $id;
                }
            }

            if (($spec['autowire'] ?? false) === true) {
                if (!is_string($class) || $class === '') {
                    throw new \InvalidArgumentException(
                        $this->ctx($providerClass, "Service '$id' has autowire=true but no class")
                    );
                }
                $out[$id] = new AutowireDefinition($id, $class, shared: (bool)($spec['shared'] ?? true));
                if (is_string($providerClass) && $providerClass !== '') {
                    self::$providerMap[$id] = $providerClass;
                }
                $this->collectAliases($id, $spec, $out);
                continue;
            }

            if (isset($spec['factory'])) {
                if ($prod && $spec['factory'] instanceof \Closure) {
                    throw new \InvalidArgumentException(
                        $this->ctx($providerClass, "Service '$id' factory closure not allowed in production")
                    );
                }
                $callable = $this->normalizeFactory($spec['factory'], $providerClass, $id);
                $factory = $this->wrapFactoryCallable($callable);
                $out[$id] = new FactoryDefinition($id, $factory, (bool)($spec['shared'] ?? true));
                if (is_string($providerClass) && $providerClass !== '') {
                    self::$providerMap[$id] = $providerClass;
                }
                $this->collectAliases($id, $spec, $out);
                continue;
            }

            if (!is_string($class) || $class === '') {
                throw new \InvalidArgumentException(
                    $this->ctx($providerClass, "Service '$id' missing class or autowire=true")
                );
            }

            // This path emits a `new $class(...)` factory, so $class MUST be instantiable.
            // An interface/abstract inferred from the id (no explicit class/factory/autowire)
            // would otherwise load green and fatal with "Cannot instantiate interface" at
            // first resolution -- possibly in production, on a cold path. Reject it now.
            if (interface_exists($class) || (class_exists($class) && (new \ReflectionClass($class))->isAbstract())) {
                throw new \InvalidArgumentException($this->ctx(
                    $providerClass,
                    "Service '$id' resolves to non-instantiable '$class' with no 'class', 'factory', "
                    . "or 'autowire'. Bind it to a concrete class (['class' => Concrete::class]) or a factory."
                ));
            }

            $args = $this->normalizeArguments(($spec['arguments'] ?? []), $providerClass, $id, $prod);
            // Emit a factory that resolves '@id' at runtime and constructs the object
            $factory = function (\Psr\Container\ContainerInterface $c) use ($class, $args) {
                $resolved = [];
                foreach ($args as $a) {
                    $resolved[] = $this->resolveRef($c, $a);
                }
                return new $class(...$resolved);
            };
            $out[$id] = new FactoryDefinition($id, $factory, (bool)($spec['shared'] ?? true));
            if (is_string($providerClass) && $providerClass !== '') {
                self::$providerMap[$id] = $providerClass;
            }
            $this->collectAliases($id, $spec, $out);
        }
        return $out;
    }

    /**
     * @param mixed $factory
     * @return callable|string|array<int|string, mixed>
     */
    private function normalizeFactory($factory, ?string $providerClass, string $id): callable|string|array
    {
        if (is_string($factory)) {
            if (!str_contains($factory, '::')) {
                throw new \InvalidArgumentException(
                    $this->ctx($providerClass, "Service '$id' factory string must be 'Class::method'")
                );
            }
            return $factory;
        }
        if (is_array($factory)) {
            if (count($factory) !== 2) {
                throw new \InvalidArgumentException(
                    $this->ctx($providerClass, "Service '$id' factory array must be [target, method]")
                );
            }
            [$target, $method] = $factory;
            if (!is_string($method) || $method === '') {
                throw new \InvalidArgumentException($this->ctx($providerClass, "Service '$id' factory method invalid"));
            }
            return [$target, $method];
        }
        if ($factory instanceof \Closure) {
            return $factory; // caller enforces prod restriction
        }
        throw new \InvalidArgumentException(
            $this->ctx($providerClass, "Service '$id' factory must be array|string|Closure")
        );
    }

    /**
     * @param callable|string|array<int|string, mixed> $callable
     */
    private function wrapFactoryCallable(callable|string|array $callable): callable
    {
        if (is_callable($callable)) {
            return $callable; // already closure/callable
        }

        // Wrap 'Class::method' or ['@service','method']/['Class','method'] into a closure
        return function (\Psr\Container\ContainerInterface $c) use ($callable) {
            if (is_string($callable)) {
                // 'Class::method' — pass container as first arg
                /** @var callable $callable */
                return $callable($c);
            }
            // array form: ['@service','method'] or ['Class','method']
            /** @var array{0: mixed, 1: string} $callable */
            [$target, $method] = $callable;
            if (is_string($target) && str_starts_with($target, '@')) {
                $sid = substr($target, 1);
                $svc = $c->get($sid);
                /** @var object $svc */
                /** @var callable $methodCallable */
                $methodCallable = [$svc, $method];
                return $methodCallable($c);
            }
            // Class name or object
            /** @var callable $targetCallable */
            $targetCallable = [$target, $method];
            return $targetCallable($c);
        };
    }

    /**
     * @param array<int, mixed> $args
     * @return array<int, mixed>
     */
    private function normalizeArguments(array $args, ?string $providerClass, string $id, bool $prod): array
    {
        foreach ($args as $a) {
            if (is_object($a) && !$a instanceof \UnitEnum) {
                if ($prod) {
                    throw new \InvalidArgumentException(
                        $this->ctx($providerClass, "Service '$id' has object argument; not allowed in production")
                    );
                }
            }
        }
        return $args;
    }

    /**
     * Register the `'alias'` ids of a service.
     *
     * DIRECTION (read carefully -- this is a common footgun): `Id => ['alias' => X]` makes
     * the NAME `X` resolve to `Id`. The alias points AT `$id`; it does NOT make `$id`
     * resolve to `X`. So to bind an interface to a concrete class you write it on the
     * CONCRETE entry:
     *
     *     Concrete::class => ['class' => Concrete::class, 'alias' => [SomeInterface::class]]
     *
     * The intuitive-looking `SomeInterface::class => ['alias' => Concrete::class]` does the
     * OPPOSITE -- it leaves `SomeInterface` bound to `new SomeInterface()` (which the
     * load-time non-instantiable guard in load() now rejects) and creates an alias named
     * `Concrete` pointing back at the interface.
     *
     * @param array<string, mixed> $spec
     * @param array<string, DefinitionInterface> $out
     */
    private function collectAliases(string $id, array $spec, array &$out): void
    {
        if (!isset($spec['alias'])) {
            return;
        }
        $aliases = is_array($spec['alias']) ? $spec['alias'] : [$spec['alias']];
        foreach ($aliases as $alias) {
            if (!is_string($alias) || $alias === '') {
                continue;
            }
            $out[$alias] = new AliasDefinition($alias, $id);
            // Alias inherits provider attribution
            if (isset(self::$providerMap[$id])) {
                self::$providerMap[$alias] = self::$providerMap[$id];
            }
        }
    }

    private function resolveRef(\Psr\Container\ContainerInterface $c, mixed $value): mixed
    {
        if (is_string($value) && str_starts_with($value, '@')) {
            $id = substr($value, 1);
            if ($id === '') {
                throw new \InvalidArgumentException("Invalid reference '@'");
            }
            return $c->get($id);
        }
        return $value;
    }

    private function ctx(?string $provider, string $msg): string
    {
        return ($provider !== null ? ($provider . ': ') : '') . $msg;
    }
}

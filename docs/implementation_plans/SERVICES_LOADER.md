# ServicesLoader — Extension DSL → Container Definitions

Status: Draft

This document defines the ServicesLoader abstraction and the DSL schema used by extensions to declare services that compile into the new PSR‑11 container.

## Purpose

- Provide a friendly, Laravel‑style DSL for extensions while preserving the new container’s strongly‑typed DefinitionInterface model.
- Enforce production compile rules (no closures; predictable value types).
- Keep dev/prod parity with clear validation and error reporting.

## Interface (proposal)

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions;

use Glueful\Container\Definition\DefinitionInterface;

interface ServicesLoader
{
    /**
     * Translate an extension DSL map into container definitions.
     *
     * @param array<string, mixed> $dsl  The raw DSL map (serviceId => spec)
     * @param string|null $providerClass For error context (who provided these defs)
     * @param bool $prod                 True when compiling for production (stricter rules)
     * @return array<string, DefinitionInterface>
     */
    public function load(array $dsl, ?string $providerClass = null, bool $prod = false): array;
}
```

Notes:
- Implementations MAY be stateless; pass `$prod` to toggle compile‑time rules.
- Errors should reference `$providerClass` to aid debugging.

## DSL Schema — Quick Reference

Supported top‑level form:

```php
return [
    'service.id' => [ /* spec */ ],
    FQCN::class   => [ /* spec */ ],
];
```

Allowed keys per spec:

- `class: string`
  - Fully‑qualified class name; defaults to the map key if the key is a FQCN.
- `shared: bool` (default: true)
  - Whether the instance is a singleton.
- `autowire: bool` (default: false)
  - If true, emit an AutowireDefinition for `class`; `arguments` are ignored.
- `arguments: array`
  - Constructor arguments; values may be scalars, arrays, or service references via `'@serviceId'`.
  - No `%param%` placeholders — use `config()` inside a FactoryDefinition if you need dynamic values.
- `factory: array|string`
  - `['@serviceId', 'method']` or `'ClassName::method'`. Closures are NOT allowed in production.
- `alias: string|array<string>`
  - One or many additional IDs that resolve to this service.
- `tags: array<string>|array<array<string,mixed>>`
  - Simple form: `['tag.name', 'other.tag']`
  - Extended: `[['name' => 'tag.name', 'priority' => 10, ...], ...]`
- `decorate: string|array` (optional/future)
  - Decorator metadata; loader may ignore if not supported yet.

### Service References

- Use `'@serviceId'` to indicate a reference in `arguments` or as the first element of a `factory` array.
- `'@@...'` and `'@'` alone are invalid and must be rejected early.

### Production Compile Rules

- Closures are not allowed in `factory` in production.
- Only scalars/arrays/service refs in `arguments` (no arbitrary objects).
- Provide clear error messages that include the provider class.

## Examples

### 1) Simple class with references

```php
use Vendor\Blog\BlogService;

return [
    BlogService::class => [
        'class' => BlogService::class,
        'shared' => true,
        'arguments' => ['@db', '@cache'],
        'tags' => [['name' => 'lazy.background', 'priority' => 5]],
    ],
];
```

→ Emits a definition for BlogService singleton with `db` and `cache` references, tagged for background warmup.

### 2) Factory + alias

```php
return [
    'blog.client' => [
        'class' => Vendor\Blog\Http\Client::class,
        'factory' => ['@http.client', 'forBlog'],
        'shared' => true,
        'alias' => [Vendor\Blog\Http\Client::class, 'blog.http'],
    ],
];
```

### 3) Autowire shortcut

```php
return [
    Vendor\Search\Indexer::class => [
        'autowire' => true,
        'shared' => true,
    ],
];
```

### 4) Shorthands and fluency

To improve DX, the loader may accept a few tiny shorthands that map to the same Definition types:

- `singleton => true` is treated as `shared => true`
- `bind => false` is treated as `shared => false`
- `autowire => true` emits an AutowireDefinition (ignores `arguments`)

Example with shorthands:

```php
return [
    'mailer' => [
        'class' => Vendor\Mail\Mailer::class,
        'singleton' => true,               // maps to shared => true
        'arguments' => ['@transport'],
        'tags' => ['lazy.background'],
    ],
    Vendor\Search\Indexer::class => [
        'autowire' => true,                // emits AutowireDefinition
        'bind' => false,                   // maps to shared => false (aka prototype)
    ],
];
```

The shorthands are optional sugar; the canonical keys remain `shared` and `autowire`.

### 5) Fluent tag() in strongly‑typed providers

When using the strongly‑typed `defs()` approach from a provider that extends
`Glueful\Container\Providers\BaseServiceProvider`, you can add tags fluently
with `$this->tag($serviceId, $tag, $priority)`:

```php
use Glueful\Container\Providers\BaseServiceProvider;
use Glueful\Container\Definition\{FactoryDefinition, DefinitionInterface};

final class Provider extends BaseServiceProvider
{
    /** @return array<string, DefinitionInterface|callable|mixed> */
    public function defs(): array
    {
        $defs = [];

        $defs['db.pool'] = new FactoryDefinition(
            'db.pool',
            fn(\Psr\Container\ContainerInterface $c) =>
                \Vendor\Db\Pool::fromConfig((array) config('database.pool', []))
        );

        // Warm the pool in the background
        $this->tag('db.pool', 'lazy.background', 10);

        return $defs;
    }
}
```

## Error Reporting

- On invalid entries, the loader should throw with context:
  - `"DemoProvider: Service 'blog.client' has invalid factory (closure not allowed in prod)"`
  - `"DemoProvider: Invalid reference '@' for service 'blog.service'"`
- Missing service references can be validated after merge (best effort) or surfaced during compile.

## ContainerFactory Integration

During `Glueful\Container\Bootstrap\ContainerFactory::create()`:

1) Discover extension providers (e.g., `Glueful\Extensions\ProviderLocator`).
2) For each provider class:
   - If it exposes `defs()`: merge returned `DefinitionInterface[]` directly.
   - Else if it exposes `services()`: pass the returned DSL to `ServicesLoader::load()` and merge the result.
3) Proceed with container creation/compilation.

This preserves dev/prod parity and gives extension authors the choice between a very friendly DSL or full‑power typed definitions.

## Rationale

- The loader keeps DX high while preserving a single, predictable container model.
- Avoids re‑introducing Symfony parameters and runtime mutation, ensuring PSR‑11 purity.
- Scales to compiled production builds with clear constraints and early validation.

## Implementation Sketch

Below is a sketch of a simple implementation that translates the DSL into `DefinitionInterface` objects. It keeps validation centralized and leaves tag application to the caller (typically a provider that has access to `TagCollector`).

```php
namespace Glueful\Extensions;

use Glueful\Container\Definition\{
    DefinitionInterface,
    FactoryDefinition,
    ValueDefinition,
    AliasDefinition
};
use Glueful\Container\Autowire\AutowireDefinition;

final class SimpleServicesLoader implements ServicesLoader
{
    /** @inheritDoc */
    public function load(array $dsl, ?string $providerClass = null, bool $prod = false): array
    {
        $out = [];
        foreach ($dsl as $id => $spec) {
            if (!is_array($spec)) {
                throw new \InvalidArgumentException($this->ctx($providerClass, "Service '$id' must be an array"));
            }

            // Resolve shorthands
            if (array_key_exists('singleton', $spec)) {
                $spec['shared'] = (bool) $spec['singleton'];
            }
            if (array_key_exists('bind', $spec)) {
                $spec['shared'] = (bool) $spec['bind']; // typically false
            }

            // Determine class
            $class = $spec['class'] ?? (is_string($id) && str_contains($id, '\\') ? $id : null);

            // Autowire fast-path
            if (($spec['autowire'] ?? false) === true) {
                if (!is_string($class) || $class === '') {
                    throw new \InvalidArgumentException($this->ctx($providerClass, "Service '$id' has autowire=true but no class"));
                }
                $def = new AutowireDefinition($id, $class, shared: (bool)($spec['shared'] ?? true));
                $out[$id] = $def;
                $this->collectAliases($id, $spec, $out);
                continue;
            }

            // Factory path
            if (isset($spec['factory'])) {
                if ($prod && $spec['factory'] instanceof \Closure) {
                    throw new \InvalidArgumentException($this->ctx($providerClass, "Service '$id' factory closure not allowed in production"));
                }
                $callable = $this->normalizeFactory($spec['factory'], $providerClass, $id);
                $def = new FactoryDefinition($id, $callable, (bool)($spec['shared'] ?? true));
                $out[$id] = $def;
                $this->collectAliases($id, $spec, $out);
                continue;
            }

            // Class + arguments path (no autowire)
            if (!is_string($class) || $class === '') {
                throw new \InvalidArgumentException($this->ctx($providerClass, "Service '$id' missing class or autowire=true"));
            }

            $args = $this->normalizeArguments(($spec['arguments'] ?? []), $providerClass, $id, $prod);

            // In the new container, a typical class+args can be emitted as a small factory
            $def = new FactoryDefinition(
                $id,
                /** @return object */
                function (\Psr\Container\ContainerInterface $c) use ($class, $args) {
                    $resolved = [];
                    foreach ($args as $a) {
                        $resolved[] = $this->resolveRef($c, $a);
                    }
                    return new $class(...$resolved);
                },
                (bool)($spec['shared'] ?? true)
            );
            $out[$id] = $def;
            $this->collectAliases($id, $spec, $out);
        }
        return $out;
    }

    /** @param mixed $factory */
    private function normalizeFactory($factory, ?string $providerClass, string $id): callable|string|array
    {
        if (is_string($factory)) {
            // 'Class::method'
            if (!str_contains($factory, '::')) {
                throw new \InvalidArgumentException($this->ctx($providerClass, "Service '$id' factory string must be 'Class::method'"));
            }
            return $factory;
        }
        if (is_array($factory)) {
            // ['@service', 'method'] or ['Class', 'method']
            if (count($factory) !== 2) {
                throw new \InvalidArgumentException($this->ctx($providerClass, "Service '$id' factory array must be [target, method]"));
            }
            [$target, $method] = $factory;
            if (!is_string($method) || $method === '') {
                throw new \InvalidArgumentException($this->ctx($providerClass, "Service '$id' factory method invalid"));
            }
            if (is_string($target) && str_starts_with($target, '@')) {
                $svcId = substr($target, 1);
                if ($svcId === '') {
                    throw new \InvalidArgumentException($this->ctx($providerClass, "Service '$id' factory '@' must be followed by service id"));
                }
                // Defer service resolution to runtime; keep factory as ['@id','method']
                return [$target, $method];
            }
            // Allow direct ['Class','method'] for static factories
            return [$target, $method];
        }
        if ($factory instanceof \Closure) {
            return $factory; // caller decides if allowed (prod=false)
        }
        throw new \InvalidArgumentException($this->ctx($providerClass, "Service '$id' factory must be array|string|Closure"));
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
                    throw new \InvalidArgumentException($this->ctx($providerClass, "Service '$id' has object argument; not allowed in production"));
                }
            }
        }
        return $args;
    }

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
        return ($provider ? ($provider . ': ') : '') . $msg;
    }
}
```

Tag application: because tags are consumed by `TagCollector` during provider assembly, the caller (provider) can scan the original DSL and apply `$this->tag($id, $name, $priority)` accordingly after calling `load()`.

## Example Unit Tests (sketch)

Using PHPUnit‑style pseudocode to verify DSL keys and shorthands map correctly.

```php
final class ServicesLoaderTest extends \PHPUnit\Framework\TestCase
{
    private ServicesLoader $loader;

    protected function setUp(): void
    {
        $this->loader = new \Glueful\Extensions\SimpleServicesLoader();
    }

    public function testSimpleClassWithRefs(): void
    {
        $defs = $this->loader->load([
            Vendor\Blog\BlogService::class => [
                'class' => Vendor\Blog\BlogService::class,
                'shared' => true,
                'arguments' => ['@db', '@cache'],
            ],
        ], 'DemoProvider', false);

        $this->assertArrayHasKey(Vendor\Blog\BlogService::class, $defs);
        $def = $defs[Vendor\Blog\BlogService::class];
        $this->assertInstanceOf(\Glueful\Container\Definition\FactoryDefinition::class, $def);
    }

    public function testFactoryArray(): void
    {
        $defs = $this->loader->load([
            'blog.client' => [
                'class' => Vendor\Blog\Http\Client::class,
                'factory' => ['@http.client', 'forBlog'],
                'shared' => true,
            ],
        ]);
        $this->assertArrayHasKey('blog.client', $defs);
    }

    public function testAutowireShortcut(): void
    {
        $defs = $this->loader->load([
            Vendor\Search\Indexer::class => [
                'autowire' => true,
                'shared' => true,
            ],
        ]);
        $this->assertInstanceOf(
            \Glueful\Container\Autowire\AutowireDefinition::class,
            $defs[Vendor\Search\Indexer::class]
        );
    }

    public function testShorthands(): void
    {
        $defs = $this->loader->load([
            'mailer' => [
                'class' => Vendor\Mail\Mailer::class,
                'singleton' => true,
                'arguments' => ['@transport'],
            ],
            Vendor\Search\Indexer::class => [
                'autowire' => true,
                'bind' => false,
            ],
        ]);

        $this->assertArrayHasKey('mailer', $defs);
        $this->assertArrayHasKey(Vendor\Search\Indexer::class, $defs);
    }

    public function testProdRejectsClosureFactories(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->loader->load([
            'bad' => [
                'class' => StdClass::class,
                'factory' => function () {},
            ],
        ], 'DemoProvider', true);
    }
}
```

These tests demonstrate the intended mapping surface; actual runtime assertions may additionally inspect the produced definition internals or behavior under a small container harness.

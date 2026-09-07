<?php

declare(strict_types=1);

namespace Glueful\Container\Compile;

use Glueful\Container\Definition\DefinitionInterface;
use Glueful\Container\Definition\ValueDefinition;
use Psr\Container\ContainerInterface;
use Glueful\Container\Definition\FactoryDefinition;
use Glueful\Container\Definition\TaggedIteratorDefinition;
use Glueful\Container\Definition\AliasDefinition;
use Glueful\Container\Autowire\AutowireDefinition;

final class ContainerCompiler
{
    /**
     * @param array<string, DefinitionInterface> $definitions
     */
    public function compile(
        array $definitions,
        string $className = 'CompiledContainer',
        string $namespace = 'Glueful\\Container\\Compiled'
    ): string {
        $methods = [];
        $hasCases = [];
        $getCases = [];
        $singletonInits = [];
        $unsupported = [];
        $runtimeIds = [];
        $runtimeFactoryIds = [];

        foreach ($definitions as $id => $definition) {
            $method = $this->methodName($id);
            $hasCases[] = '            case ' . var_export($id, true) . ': return true;';

            if ($definition instanceof ValueDefinition) {
                $value = $definition->getValue();
                if ($value instanceof ContainerInterface) {
                    // The container's self-reference: in the compiled world that is $this.
                    $methods[] = $this->emitSelfReference($method);
                } elseif (is_object($value) && !$this->isExportable($value)) {
                    // Live objects (ApplicationContext, …) cannot become code; they are handed
                    // in after construction via withRuntimeValues() — see RUNTIME_VALUE_IDS.
                    $runtimeIds[] = (string) $id;
                    $methods[] = $this->emitRuntimeValue($id, $method);
                } else {
                    $methods[] = $this->emitValue($id, $definition, $method);
                }
                $getCases[] = $this->emitGetCase($id, $method, true);
                $singletonInits[] = var_export($id, true) . ' => null';
                continue;
            }

            if ($definition instanceof AutowireDefinition) {
                $methods[] = $this->emitAutowire($id, $definition, $method);
                $getCases[] = $this->emitGetCase($id, $method, $definition->isShared());
                if ($definition->isShared()) {
                    $singletonInits[] = var_export($id, true) . ' => null';
                }
                continue;
            }

            if ($definition instanceof TaggedIteratorDefinition) {
                $methods[] = $this->emitTaggedIterator($id, $definition, $method);
                $getCases[] = $this->emitGetCase($id, $method, true);
                $singletonInits[] = var_export($id, true) . ' => null';
                continue;
            }

            if ($definition instanceof AliasDefinition) {
                $getCases[] = $this->emitAliasCase($id, $definition);
                continue;
            }

            if ($definition instanceof FactoryDefinition) {
                $call = $this->staticFactoryCall($definition->getFactory());
                if ($call === null) {
                    // A closure / instance factory cannot become code; the live callable is
                    // handed in after construction via withRuntimeFactories(). The service is
                    // still served by the compiled container — the win is every autowired
                    // service around it compiling to plain constructor calls.
                    $runtimeFactoryIds[] = (string) $id;
                    $missing = "Runtime factory '{$id}' was not provided — call withRuntimeFactories() "
                        . 'on the compiled container';
                    $call = '($this->runtimeFactories[' . var_export($id, true) . '] ?? $this->fail('
                        . var_export($missing, true) . '))';
                }
                $methods[] = $this->emitFactory($id, $call, $method, $definition->isShared());
                $getCases[] = $this->emitGetCase($id, $method, $definition->isShared());
                if ($definition->isShared()) {
                    $singletonInits[] = var_export($id, true) . ' => null';
                }
                continue;
            }

            $unsupported[] = $id . ' (' . get_class($definition) . ')';
        }

        if ($unsupported !== []) {
            throw new \RuntimeException(
                "Cannot compile the following definitions:\n" .
                implode("\n", $unsupported) .
                "\nConvert to AutowireDefinition or ValueDefinition."
            );
        }

        $singletons = $this->formatSingletons($singletonInits);

        return $this->generateClassCode(
            $namespace,
            $className,
            $singletons,
            $hasCases,
            $getCases,
            $methods,
            $runtimeIds,
            $runtimeFactoryIds
        );
    }

    /**
     * A factory compiles only when it is a static callable we can name in code.
     * Returns the call expression (without arguments) or null.
     *
     * @param callable|string|array{0: mixed, 1: string} $factory
     */
    private function staticFactoryCall(mixed $factory): ?string
    {
        if (is_string($factory) && str_contains($factory, '::')) {
            [$class, $method] = explode('::', $factory, 2);
            return $this->staticCallIfValid($class, $method);
        }
        if (is_array($factory) && count($factory) === 2 && is_string($factory[0]) && is_string($factory[1])) {
            return $this->staticCallIfValid($factory[0], $factory[1]);
        }

        return null; // Closure, invokable object, [$instance, 'method'] …
    }

    private function staticCallIfValid(string $class, string $method): ?string
    {
        $class = ltrim($class, '\\');
        if (!class_exists($class) || !method_exists($class, $method)) {
            return null;
        }
        if (!(new \ReflectionMethod($class, $method))->isStatic()) {
            return null;
        }

        return '\\' . $class . '::' . $method;
    }

    private function emitFactory(string $id, string $call, string $method, bool $shared): string
    {
        $build = <<<PHP
    private function {$method}(): mixed
    {
        return {$call}(\$this);
    }
PHP;
        if ($shared) {
            $idExport = var_export($id, true);
            $build .= "\n\n    private function get_{$method}(): mixed\n" .
                "    {\n" .
                "        return \$this->singletons[{$idExport}] ??= \$this->{$method}();\n" .
                "    }";
        }

        return $build;
    }

    private function emitSelfReference(string $method): string
    {
        return <<<PHP
    private function {$method}(): mixed
    {
        return \$this;
    }

    private function get_{$method}(): mixed
    {
        return \$this;
    }
PHP;
    }

    private function emitRuntimeValue(string $id, string $method): string
    {
        $idExport = var_export($id, true);
        $message = var_export(
            "Runtime value '{$id}' was not provided — call withRuntimeValues() on the compiled container",
            true
        );

        return <<<PHP
    private function {$method}(): mixed
    {
        return \$this->runtimeValues[{$idExport}] ?? \$this->fail({$message});
    }

    private function get_{$method}(): mixed
    {
        return \$this->{$method}();
    }
PHP;
    }

    private function isExportable(object $value): bool
    {
        try {
            $this->exportValue($value);
            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }

    /**
     * Build a lightweight services index for tooling.
     * Produces an associative array keyed by service id with fields we can infer:
     *  - shared: bool
     *  - tags:  list<string>
     *  - provider: string|null (only when provided by DSL loader)
     *  - type: definition class for diagnostics
     *  - alias_of: when the id is an alias, the target id
     *
     * @param array<string, DefinitionInterface> $definitions
     * @param array<string, string> $providerMap service id => provider class
     * @return array<string, array<string, mixed>>
     */
    public function buildServicesIndex(array $definitions, array $providerMap = []): array
    {
        // First pass: collect tag relations (serviceId => [tagName,...]) via tagged iterator defs
        $serviceTags = [];
        foreach ($definitions as $id => $definition) {
            if ($definition instanceof \Glueful\Container\Definition\TaggedIteratorDefinition) {
                foreach ($definition->getTagged() as $entry) {
                    $sid = (string) $entry['service'];
                    $serviceTags[$sid] = $serviceTags[$sid] ?? [];
                    $serviceTags[$sid][] = (string) $id; // $id is the tag name
                }
            }
        }

        $index = [];
        foreach ($definitions as $id => $definition) {
            $row = [
                'shared' => (bool) $definition->isShared(),
                'tags' => $serviceTags[$id] ?? [],
                'provider' => $providerMap[$id] ?? null,
                'type' => get_class($definition),
            ];

            if ($definition instanceof \Glueful\Container\Definition\AliasDefinition) {
                $row['alias_of'] = $definition->getTarget();
            }

            $index[(string) $id] = $row;
        }

        return $index;
    }

    private function emitValue(string $id, ValueDefinition $def, string $method): string
    {
        $value = $this->exportValue($def->getValue());
        $idExport = var_export($id, true);

        return <<<PHP
    private function {$method}(): mixed
    {
        return {$value};
    }

    private function get_{$method}(): mixed
    {
        return \$this->singletons[{$idExport}] ??= \$this->{$method}();
    }
PHP;
    }

    private function emitAutowire(string $id, AutowireDefinition $def, string $method): string
    {
        $class = $def->getClass();
        $args = $this->emitCtorArgs($def);
        $build = <<<PHP
    private function {$method}(): mixed
    {
        return new \\{$class}({$args});
    }
PHP;

        if ($def->isShared()) {
            $idExport = var_export($id, true);
            $build .= "\n\n    private function get_{$method}(): mixed\n" .
                "    {\n" .
                "        return \$this->singletons[{$idExport}] ??= \$this->{$method}();\n" .
                "    }";
        }

        return $build;
    }

    private function emitTaggedIterator(string $id, TaggedIteratorDefinition $def, string $method): string
    {
        $entries = $def->getTagged();
        usort($entries, static fn(array $a, array $b) => $b['priority'] <=> $a['priority']);
        $items = [];
        foreach ($entries as $e) {
            $items[] = '$this->get(' . var_export($e['service'], true) . ')';
        }
        $itemsCode = implode(', ', $items);

        $build = <<<PHP
    private function {$method}(): array
    {
        return [{$itemsCode}];
    }
PHP;

        // Tagged iterators are shared
        $idExport = var_export($id, true);
        $build .= "\n\n    private function get_{$method}(): array\n" .
            "    {\n" .
            "        return \$this->singletons[{$idExport}] ??= \$this->{$method}();\n" .
            "    }";

        return $build;
    }

    private function emitAliasCase(string $id, AliasDefinition $def): string
    {
        $alias = var_export($id, true);
        $target = var_export($def->getTarget(), true);
        // Redirect to the target's get() so it uses target's caching semantics
        return "            case {$alias}: return \$this->get({$target});";
    }

    private function emitCtorArgs(AutowireDefinition $definition): string
    {
        $class = $definition->getClass();
        if (!class_exists($class)) {
            throw new \RuntimeException("Cannot compile autowire definition for unknown class: {$class}");
        }

        $reflection = new \ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return '';
        }

        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $arguments[] = $this->compileParameter($parameter);
        }

        return implode(', ', $arguments);
    }

    private function methodName(string $id): string
    {
        return 'build_' . preg_replace('/[^A-Za-z0-9_]/', '_', $id);
    }

    /**
     * @param array<string> $hasCases
     * @param array<string> $getCases
     * @param array<string> $methods
     * @param array<string> $runtimeIds
     * @param array<string> $runtimeFactoryIds
     */
    private function generateClassCode(
        string $namespace,
        string $className,
        string $singletons,
        array $hasCases,
        array $getCases,
        array $methods,
        array $runtimeIds = [],
        array $runtimeFactoryIds = []
    ): string {
        $hasCasesStr = implode("\n", $hasCases);
        $getCasesStr = implode("\n", $getCases);
        $methodsStr = implode("\n\n", $methods);
        $runtimeIdsStr = var_export(array_values($runtimeIds), true);
        $runtimeFactoryIdsStr = var_export(array_values($runtimeFactoryIds), true);

        return <<<PHP
<?php
namespace {$namespace};

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

final class {$className} implements ContainerInterface, \\Glueful\\Container\\RebindableContainer
{
    /** Service ids whose live objects must be handed in via withRuntimeValues(). */
    public const RUNTIME_VALUE_IDS = {$runtimeIdsStr};

    /** Service ids whose factories are closures handed in via withRuntimeFactories(). */
    public const RUNTIME_FACTORY_IDS = {$runtimeFactoryIdsStr};

    /** @var array<string, mixed> */
    private array \$singletons = [{$singletons}];

    /** @var array<string, mixed> */
    private array \$runtimeValues = [];

    /** @var array<string, callable> */
    private array \$runtimeFactories = [];

    /** Definitions loaded AFTER construction (boot-time re-pins); they win over compiled ones. */
    /** @var array<string, \\Glueful\\Container\\Definition\\DefinitionInterface> */
    private array \$overrides = [];

    /** @param array<string, \\Glueful\\Container\\Definition\\DefinitionInterface|callable|mixed> \$defs */
    public function load(array \$defs): void
    {
        foreach (\$defs as \$id => \$d) {
            \$this->overrides[\$id] = \$d instanceof \\Glueful\\Container\\Definition\\DefinitionInterface ? \$d
                : (is_callable(\$d)
                    ? new \\Glueful\\Container\\Definition\\FactoryDefinition(\$id, \$d)
                    : new \\Glueful\\Container\\Definition\\ValueDefinition(\$id, \$d));
            unset(\$this->singletons[\$id]); // a re-pin must not serve the stale compiled instance
        }
    }

    /** @param array<string, callable> \$factories */
    public function withRuntimeFactories(array \$factories): static
    {
        foreach (\$factories as \$id => \$factory) {
            \$this->runtimeFactories[\$id] = \$factory;
        }
        return \$this;
    }

    /** @param array<string, mixed> \$values */
    public function withRuntimeValues(array \$values): static
    {
        foreach (\$values as \$id => \$value) {
            \$this->runtimeValues[\$id] = \$value;
        }
        return \$this;
    }

    public function has(string \$id): bool
    {
        if (isset(\$this->overrides[\$id])) {
            return true;
        }
        switch (\$id) {
{$hasCasesStr}
            default: return false;
        }
    }

    public function get(string \$id): mixed
    {
        if (isset(\$this->singletons[\$id])) {
            return \$this->singletons[\$id];
        }
        if (isset(\$this->overrides[\$id])) {
            \$def = \$this->overrides[\$id];
            \$val = \$def->resolve(\$this);
            if (\$def->isShared()) {
                \$this->singletons[\$id] = \$val;
            }
            return \$val;
        }
        switch (\$id) {
{$getCasesStr}
        }
        throw new class("Service '" . \$id . "' not found") extends \RuntimeException 
            implements NotFoundExceptionInterface {};
    }

{$methodsStr}

    private function fail(string \$message): never
    {
        throw new \Glueful\Container\Exception\ContainerException(\$message);
    }
}
PHP;
    }

    private function emitGetCase(string $id, string $method, bool $shared): string
    {
        $idExport = var_export($id, true);
        if ($shared) {
            return '            case ' . $idExport . ': return $this->get_' . $method . '();';
        }

        return '            case ' . $idExport . ': return $this->' . $method . '();';
    }

    /**
     * @param array<string> $singletons
     */
    private function formatSingletons(array $singletons): string
    {
        if ($singletons === []) {
            return '';
        }

        return "\n        " . implode(",\n        ", $singletons) . "\n    ";
    }

    private function compileParameter(\ReflectionParameter $parameter): string
    {
        $injectAttributes = $parameter->getAttributes(\Glueful\Container\Autowire\Inject::class);
        if ($injectAttributes !== []) {
            /** @var \Glueful\Container\Autowire\Inject $inject */
            $inject = $injectAttributes[0]->newInstance();
            if ($inject->id !== null) {
                return '$this->get(' . var_export($inject->id, true) . ')';
            }

            if ($inject->param !== null) {
                return '$this->get(' . var_export('param.bag', true) . ')->get(' .
                    var_export($inject->param, true) . ')';
            }
        }

        $type = $parameter->getType();
        if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
            $id = var_export($type->getName(), true);
            // Mirror the runtime autowirer (ReflectionResolver): a typed dependency the container
            // does not hold falls back to the parameter's default, then to null — decided at
            // RUNTIME, because load() may add the id after compilation.
            if ($parameter->isDefaultValueAvailable()) {
                return '($this->has(' . $id . ') ? $this->get(' . $id . ') : '
                    . $this->exportDefault($parameter) . ')';
            }
            if ($parameter->allowsNull()) {
                return '($this->has(' . $id . ') ? $this->get(' . $id . ') : null)';
            }

            return '$this->get(' . $id . ')';
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $this->exportDefault($parameter);
        }

        if ($parameter->allowsNull()) {
            return 'null';
        }

        $class = $parameter->getDeclaringClass();
        $context = $class !== null ? $class->getName() : 'unknown context';
        $message = sprintf(
            "Cannot resolve parameter '%s' for %s",
            $parameter->getName(),
            $context
        );

        return '$this->fail(' . var_export($message, true) . ')';
    }

    /**
     * A parameter default as code. Scalars, arrays and the exportable object kinds become
     * literals; anything else (an object built in the initializer, `= new Foo()`) is evaluated
     * at RUNTIME through reflection — the same value the runtime autowirer would hand over.
     */
    private function exportDefault(\ReflectionParameter $parameter): string
    {
        try {
            return $this->exportValue($parameter->getDefaultValue());
        } catch (\RuntimeException) {
            $class = $parameter->getDeclaringClass()?->getName();
            $function = $parameter->getDeclaringFunction()->getName();
            $owner = $class !== null
                ? '[' . var_export($class, true) . ', ' . var_export($function, true) . ']'
                : var_export($function, true);

            return '(new \\ReflectionParameter(' . $owner . ', ' . var_export($parameter->getName(), true)
                . '))->getDefaultValue()';
        }
    }

    private function exportValue(mixed $value): string
    {
        // Scalars and arrays
        if (!is_object($value)) {
            return var_export($value, true);
        }

        // ParamBag special-case
        if ($value instanceof \Glueful\Container\Support\ParamBag) {
            return 'new \\Glueful\\Container\\Support\\ParamBag(' . var_export($value->all(), true) . ')';
        }

        // Enums (PHP 8.1+)
        if ($value instanceof \UnitEnum) {
            $enumClass = '\\' . ltrim(get_class($value), '\\');
            if ($value instanceof \BackedEnum) {
                return $enumClass . '::from(' . var_export($value->value, true) . ')';
            }
            return $enumClass . '::' . $value->name;
        }

        // Date/Time
        if ($value instanceof \DateTimeImmutable) {
            // Preserve exact instant and timezone via ISO 8601
            return 'new \\DateTimeImmutable(' . var_export($value->format('c'), true) . ')';
        }
        if ($value instanceof \DateTime) {
            return 'new \\DateTime(' . var_export($value->format('c'), true) . ')';
        }
        if ($value instanceof \DateTimeZone) {
            return 'new \\DateTimeZone(' . var_export($value->getName(), true) . ')';
        }
        if ($value instanceof \DateInterval) {
            $spec = $this->buildIntervalSpec($value);
            return 'new \\DateInterval(' . var_export($spec, true) . ')';
        }

        // PSR-7 URI as Nyholm Uri
        if ($value instanceof \Psr\Http\Message\UriInterface) {
            return 'new \\Nyholm\\Psr7\\Uri(' . var_export((string) $value, true) . ')';
        }

        throw new \RuntimeException(
            'Cannot compile ValueDefinition for object of type ' . get_class($value)
        );
    }

    private function buildIntervalSpec(\DateInterval $i): string
    {
        $date = '';
        if ($i->y !== 0) {
            $date .= $i->y . 'Y';
        }
        if ($i->m !== 0) {
            $date .= $i->m . 'M';
        }
        if ($i->d !== 0) {
            $date .= $i->d . 'D';
        }

        $time = '';
        if ($i->h !== 0) {
            $time .= $i->h . 'H';
        }
        if ($i->i !== 0) {
            $time .= $i->i . 'M';
        }
        // Seconds with fraction if available
        $seconds = $i->s;
        $fraction = (float) $i->f;
        if ($seconds !== 0 || $fraction > 0.0) {
            $sec = $seconds;
            if ($fraction > 0.0) {
                $sec = rtrim(rtrim(number_format($seconds + $fraction, 6, '.', ''), '0'), '.');
            }
            $time .= $sec . 'S';
        }

        if ($date === '' && $time === '') {
            $time = '0S';
        }

        $spec = 'P' . $date . ($time !== '' ? 'T' . $time : '');
        if ($i->invert === 1) {
            $spec = '-' . $spec;
        }
        return $spec;
    }
}

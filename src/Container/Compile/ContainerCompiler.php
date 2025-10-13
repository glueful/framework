<?php

declare(strict_types=1);

namespace Glueful\Container\Compile;

use Glueful\Container\Definition\DefinitionInterface;
use Glueful\Container\Definition\ValueDefinition;
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

        foreach ($definitions as $id => $definition) {
            $method = $this->methodName($id);
            $hasCases[] = '            case ' . var_export($id, true) . ': return true;';

            if ($definition instanceof ValueDefinition) {
                $methods[] = $this->emitValue($id, $definition, $method);
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
                $unsupported[] = $id . ' (FactoryDefinition)';
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
            $methods
        );
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
                'tags' => array_values($serviceTags[$id] ?? []),
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
        $reflection = new \ReflectionClass($definition->getClass());
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
     */
    private function generateClassCode(
        string $namespace,
        string $className,
        string $singletons,
        array $hasCases,
        array $getCases,
        array $methods
    ): string {
        $hasCasesStr = implode("\n", $hasCases);
        $getCasesStr = implode("\n", $getCases);
        $methodsStr = implode("\n\n", $methods);

        return <<<PHP
<?php
namespace {$namespace};

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

final class {$className} implements ContainerInterface
{
    /** @var array<string, mixed> */
    private array \$singletons = [{$singletons}];

    public function has(string \$id): bool
    {
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
            return '$this->get(' . var_export($type->getName(), true) . ')';
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $this->exportValue($parameter->getDefaultValue());
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

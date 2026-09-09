<?php

declare(strict_types=1);

namespace Glueful\Container\Compile;

use Glueful\Container\Autowire\AutowireDefinition;
use Glueful\Container\Definition\AliasDefinition;
use Glueful\Container\Definition\FactoryDefinition;
use Glueful\Container\Definition\TaggedIteratorDefinition;
use Glueful\Container\Definition\ValueDefinition;
use Glueful\Support\Version;

/**
 * A cheap, process-stable signature of a definition set: what the compiled container was
 * built FROM. Names the compiled artifact, so a boot reuses the artifact while nothing changed
 * and compiles a new one — under a path OPcache has never cached — when a service, alias,
 * factory, tag or the framework version changes. Closures are identified by where they are
 * declared, live objects by their class: state is never serialized.
 */
final class DefinitionSignature
{
    /** @param array<string, mixed> $definitions */
    public static function of(array $definitions): string
    {
        $lines = [];
        foreach ($definitions as $id => $definition) {
            $lines[] = (string) $id . '=' . self::describe($definition);
        }
        sort($lines, SORT_STRING);
        $lines[] = 'framework=' . Version::VERSION;
        $lines[] = 'php=' . PHP_VERSION_ID;

        return substr(hash('sha256', implode("\n", $lines)), 0, 32);
    }

    private static function describe(mixed $definition): string
    {
        return match (true) {
            $definition instanceof AutowireDefinition
                => 'autowire:' . $definition->getClass() . ($definition->isShared() ? '' : ':transient'),
            $definition instanceof AliasDefinition
                => 'alias:' . $definition->getTarget(),
            $definition instanceof FactoryDefinition
                => 'factory:' . self::callable($definition->getFactory())
                    . ($definition->isShared() ? '' : ':transient'),
            $definition instanceof ValueDefinition
                => 'value:' . self::valueType($definition->getValue()),
            $definition instanceof TaggedIteratorDefinition
                => 'tagged:' . implode(',', array_map(
                    static fn (array $entry): string => $entry['service'] . '@' . $entry['priority'],
                    $definition->getTagged(),
                )),
            is_object($definition) => 'other:' . get_class($definition),
            default => 'raw:' . gettype($definition),
        };
    }

    private static function callable(mixed $factory): string
    {
        if ($factory instanceof \Closure) {
            $ref = new \ReflectionFunction($factory);
            return 'closure@' . $ref->getFileName() . ':' . $ref->getStartLine();
        }
        if (is_string($factory)) {
            return $factory;
        }
        if (is_array($factory) && count($factory) === 2) {
            [$target, $method] = $factory;
            return (is_object($target) ? get_class($target) : (string) $target) . '::' . (string) $method;
        }
        if (is_object($factory)) {
            return get_class($factory) . '::__invoke';
        }
        return gettype($factory);
    }

    private static function valueType(mixed $value): string
    {
        if (is_object($value)) {
            return get_class($value);
        }
        if (is_scalar($value) || $value === null) {
            return gettype($value) . ':' . var_export($value, true);
        }
        return gettype($value);
    }
}

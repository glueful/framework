<?php

declare(strict_types=1);

namespace Glueful\Extensions;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Reads and normalizes a config `enabled` list of provider FQCN strings.
 *
 * The single place that turns the raw `enabled` array into clean provider FQCNs,
 * so every consumer (resolver, CLI, container) interprets the list identically.
 * Entries must be plain string FQCNs (no `::class`); the only normalization is
 * trimming a leading backslash so `\Foo\Bar` and `Foo\Bar` are treated alike.
 */
final class EnabledProviders
{
    /**
     * @return list<string> normalized provider FQCNs, in declared order
     */
    public static function from(ApplicationContext $context, string $configKey = 'extensions.enabled'): array
    {
        return array_values(array_map(
            self::normalize(...),
            array_filter((array) config($context, $configKey, []), 'is_string')
        ));
    }

    /**
     * Normalize one entry: strip a leading backslash.
     */
    public static function normalize(string $provider): string
    {
        return ltrim($provider, '\\');
    }
}

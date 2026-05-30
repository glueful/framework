<?php

declare(strict_types=1);

namespace Glueful\Extensions;

/**
 * A composer-discovered Glueful extension candidate (not yet activated).
 */
final class ExtensionCandidate
{
    /**
     * @param string $name Composer package name (e.g. "glueful/aegis")
     * @param string $provider Provider FQCN (string, no leading backslash)
     * @param string|null $requiresGlueful Framework version constraint, or null
     * @param list<string> $requiresExtensions Provider FQCNs this extension depends on
     * @param string|null $version Installed package version (pretty, e.g. "v1.5.0"), or null
     */
    public function __construct(
        public readonly string $name,
        public readonly string $provider,
        public readonly ?string $requiresGlueful = null,
        public readonly array $requiresExtensions = [],
        public readonly ?string $version = null,
    ) {
    }
}

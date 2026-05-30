<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Extensions\Concerns;

use Glueful\Extensions\ExtensionCandidate;

trait ResolvesExtensionNeedle
{
    /**
     * Map a user-supplied needle to a candidate's provider FQCN, or null if no
     * candidate matches. Accepts the package name ("glueful/aegis"), the provider
     * FQCN, or the trailing slug (last path segment of the package name) — the slug
     * is matched case-insensitively, so "Aegis" and "aegis" both resolve.
     *
     * @param array<string, ExtensionCandidate> $candidates package name => candidate
     */
    protected function resolveNeedle(string $needle, array $candidates): ?string
    {
        $needle = ltrim($needle, '\\');
        foreach ($candidates as $name => $c) {
            $slug = substr((string) strrchr($name, '/') ?: $name, 1) ?: $name;
            if ($needle === $name || $needle === $c->provider || strcasecmp($needle, $slug) === 0) {
                return $c->provider;
            }
        }
        return null;
    }
}

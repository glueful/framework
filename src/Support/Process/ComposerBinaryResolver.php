<?php

declare(strict_types=1);

namespace Glueful\Support\Process;

use Symfony\Component\Process\ExecutableFinder;

/**
 * The single source of the composer binary path.
 *
 * Reused by BOTH the install preflight (HostCapability) and the install runner
 * (InstallRunCommand) so they can never diverge — preflight passing must mean the
 * runner uses the same binary. Honors the COMPOSER_BINARY env override, else finds
 * `composer` on PATH.
 */
final class ComposerBinaryResolver
{
    /** Absolute path to the composer binary, or null when none is resolvable. */
    public function resolve(): ?string
    {
        $candidate = getenv('COMPOSER_BINARY') ?: 'composer';

        // An explicit path override is honored when it points at an executable.
        if (str_contains($candidate, '/')) {
            return is_executable($candidate) ? $candidate : null;
        }

        return (new ExecutableFinder())->find($candidate) ?? null;
    }
}

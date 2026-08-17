<?php

declare(strict_types=1);

namespace Glueful\Extensions\Schema;

use Glueful\Database\Connection;

/**
 * Package-owned structural verifier (schema policy spec B7): proves a specific migration's
 * effects are actually present before its receipt may be adopted. Declared in the manifest
 * (descriptor `verifier`), so it stays discoverable while the owning extension is disabled.
 * Implementations MUST be instantiable with a public zero-required-argument constructor and
 * report the descriptor's exact source identity.
 */
interface StructuralVerifierInterface
{
    /** Must equal the descriptor source that names this verifier. */
    public function source(): string;

    public function verify(Connection $db, string $migrationBasename): bool;
}

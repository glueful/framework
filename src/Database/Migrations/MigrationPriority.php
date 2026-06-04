<?php

declare(strict_types=1);

namespace Glueful\Database\Migrations;

/**
 * Named migration ordering tiers. Lower runs first.
 *
 * These are *deterministic ordering* hints, NOT a dependency system — where a
 * real dependency exists it must be declared/enforced separately. `loadMigrationsFrom()`
 * also accepts any raw int for finer ordering between tiers.
 */
final class MigrationPriority
{
    /** Reserved for framework foundation migrations (core ships none today). */
    public const FOUNDATION = -200;

    /** Identity/auth schema (glueful/users). */
    public const IDENTITY = -100;

    /** App / skeleton and ordinary feature migrations. */
    public const DEFAULT = 0;

    /** Extensions commonly paired on top of the app (e.g. aegis), ordered for seeders. */
    public const DEPENDENT = 100;

    private function __construct()
    {
    }
}

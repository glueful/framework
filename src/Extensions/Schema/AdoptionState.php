<?php

declare(strict_types=1);

namespace Glueful\Extensions\Schema;

/**
 * Adoption classification (schema policy spec B7) — distinct from ReadinessState: Adoptable
 * requires structural verification to PASS for every missing receipt, never mere registration.
 */
enum AdoptionState: string
{
    case Ready = 'ready';
    case Adoptable = 'adoptable';
    /** Nothing applied and nothing claimed — the healthy not-yet-migrated state. */
    case Pending = 'pending';
    case Divergent = 'divergent';
}

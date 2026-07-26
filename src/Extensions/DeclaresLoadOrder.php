<?php

declare(strict_types=1);

namespace Glueful\Extensions;

/**
 * Declarative, cross-phase provider load order. STATIC on purpose: the metadata must be
 * readable from class strings during container compilation and cache generation, without
 * constructing providers. Implementers get ONE order used identically by service-definition
 * compilation, register, boot, diagnostics, and the extensions cache.
 *
 * Contrast {@see OrderedProvider}: instance-level, consulted only by the development-time boot
 * sorter — kept for backward compatibility, but it cannot order the compile/cache phases and
 * MUST NOT be combined with this contract on the same provider.
 */
interface DeclaresLoadOrder
{
    /**
     * Providers that MUST load before this one. Edges naming classes absent from the resolved
     * installation are ignored (soft dependency). A cycle among present providers is a
     * resolution ERROR — it fails cache generation and production boot, never a silent fallback.
     *
     * @return list<class-string>
     */
    public static function loadAfter(): array;

    /** Tie-break within the same dependency level; lower loads first. Default 0. */
    public static function loadPriority(): int;
}

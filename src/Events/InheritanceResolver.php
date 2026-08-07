<?php

declare(strict_types=1);

namespace Glueful\Events;

final class InheritanceResolver
{
    /**
     * Event type names for a class: itself, then parents and interfaces. Accepts
     * arbitrary strings (the provider's debug-tooling path passes unverified names);
     * an unknown class simply yields itself with no ancestry.
     *
     * @return list<string>
     */
    public function getEventTypes(string $class): array
    {
        $types = [$class];

        // class_parents / class_implements return false on failure
        $parents = class_parents($class);
        if ($parents === false) {
            $parents = [];
        }
        $interfaces = class_implements($class);
        if ($interfaces === false) {
            $interfaces = [];
        }

        foreach ($parents as $p) {
            $types[] = $p;
        }
        foreach ($interfaces as $i) {
            $types[] = $i;
        }

        // unique while preserving order
        return array_values(array_unique($types));
    }
}

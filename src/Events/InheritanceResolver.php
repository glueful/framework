<?php

declare(strict_types=1);

namespace Glueful\Events;

final class InheritanceResolver
{
    /** @return list<class-string> */
    public function getEventTypes(string $class): array
    {
        $types = [$class];

        // class_parents / class_implements return false on failure
        $parents = class_parents($class) !== false ? class_parents($class) : [];
        $interfaces = class_implements($class) !== false ? class_implements($class) : [];

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

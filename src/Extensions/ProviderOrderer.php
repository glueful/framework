<?php

declare(strict_types=1);

namespace Glueful\Extensions;

/**
 * Pure, deterministic orderer for the merged provider class list (spec: one declarative class
 * order for every phase). No construction, no container, no I/O — safe during container
 * compilation and cache generation. Applied by {@see ProviderClassResolver::resolve()}.
 */
final class ProviderOrderer
{
    /**
     * Orders provider FQCNs. Accepts unverified strings by contract: the resolver
     * passes config-declared names through without existence checks (instantiation
     * layers own that concern), and is_subclass_of() is false for unknown classes.
     *
     * @param list<string> $classes
     * @return list<string>
     */
    public static function order(array $classes): array
    {
        // Stable seed: priority ASC, then original position.
        $rows = [];
        foreach ($classes as $i => $class) {
            $prio = is_subclass_of($class, DeclaresLoadOrder::class)
                ? $class::loadPriority()
                : 0;
            $rows[] = [$class, $prio, $i];
        }
        usort($rows, static fn (array $a, array $b): int => [$a[1], $a[2]] <=> [$b[1], $b[2]]);
        $seeded = array_column($rows, 0);

        $present = array_flip($seeded);
        $edges = [];
        $indegree = array_fill_keys($seeded, 0);
        foreach ($seeded as $class) {
            $edges[$class] = [];
        }
        foreach ($seeded as $class) {
            if (!is_subclass_of($class, DeclaresLoadOrder::class)) {
                continue;
            }
            foreach ($class::loadAfter() as $dep) {
                if (isset($present[$dep])) {
                    $edges[$dep][] = $class;
                    $indegree[$class]++;
                }
            }
        }

        // Kahn, consuming the seeded order so unconstrained providers keep their seed position.
        $queue = [];
        foreach ($seeded as $class) {
            if ($indegree[$class] === 0) {
                $queue[] = $class;
            }
        }
        $out = [];
        while ($queue !== []) {
            $class = array_shift($queue);
            $out[] = $class;
            foreach ($edges[$class] as $next) {
                if (--$indegree[$next] === 0) {
                    // Insert respecting seed order among currently ready nodes.
                    $queue[] = $next;
                    usort($queue, static function (string $a, string $b) use ($seeded): int {
                        return array_search($a, $seeded, true) <=> array_search($b, $seeded, true);
                    });
                }
            }
        }

        if (count($out) !== count($seeded)) {
            $blocked = array_values(array_diff($seeded, $out));
            throw new ProviderOrderCycleException($blocked);
        }

        return $out;
    }
}

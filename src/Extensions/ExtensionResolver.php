<?php

declare(strict_types=1);

namespace Glueful\Extensions;

use Composer\Semver\Semver;

/**
 * Pure resolution: given composer candidates + the enabled allow-list, select,
 * validate, and topologically order the providers to load. Never throws — returns
 * a ResolverResult carrying providers + errors; callers choose severity.
 */
final class ExtensionResolver
{
    /**
     * @param array<string, ExtensionCandidate> $candidates package name => candidate
     * @param list<string> $enabled provider FQCNs (string), in declared order
     * @param string $frameworkVersion e.g. Version::VERSION
     */
    public function resolve(array $candidates, array $enabled, string $frameworkVersion): ResolverResult
    {
        // Index candidates by provider FQCN (the enabled list is provider FQCNs).
        $byProvider = [];
        foreach ($candidates as $c) {
            $byProvider[$c->provider] = $c;
        }

        $errors = [];
        $selected = [];           // provider FQCN => ExtensionCandidate (in enabled order)
        foreach ($enabled as $provider) {
            $provider = EnabledProviders::normalize((string) $provider);
            if (!isset($byProvider[$provider])) {
                $errors[] = new ResolverError(
                    ResolverError::MISSING_PROVIDER,
                    $provider,
                    "Enabled provider {$provider} is not an installed/discovered extension."
                );
                continue;
            }
            $selected[$provider] = $byProvider[$provider];
        }

        // Validate framework version + dependency presence (deps must be enabled too).
        foreach ($selected as $provider => $cand) {
            if ($cand->requiresGlueful !== null && !Semver::satisfies($frameworkVersion, $cand->requiresGlueful)) {
                $errors[] = new ResolverError(
                    ResolverError::VERSION_MISMATCH,
                    $provider,
                    "{$provider} requires Glueful {$cand->requiresGlueful}, running {$frameworkVersion}."
                );
            }
            foreach ($cand->requiresExtensions as $dep) {
                $dep = ltrim($dep, '\\');
                if (!isset($selected[$dep])) {
                    $errors[] = new ResolverError(
                        ResolverError::MISSING_DEPENDENCY,
                        $provider,
                        "{$provider} requires extension {$dep}, which is not enabled."
                    );
                }
            }
        }

        $ordered = $this->topoSort($selected, $errors);

        return new ResolverResult($ordered, $errors);
    }

    /**
     * Stable topological sort over requires-extensions edges among the selected set.
     * Dependencies that are present in $selected are ordered before their dependents.
     * On cycle, records a DEPENDENCY_CYCLE error and falls back to enabled order for
     * the cyclic remainder (never throws).
     *
     * @param array<string, ExtensionCandidate> $selected provider FQCN => candidate (enabled order)
     * @param list<ResolverError> $errors (by-ref accumulator)
     * @return list<string>
     */
    private function topoSort(array $selected, array &$errors): array
    {
        $result = [];
        $state = []; // provider => 0 unvisited, 1 visiting, 2 done
        $order = array_keys($selected);

        $visit = function (string $p) use (&$visit, &$state, &$result, $selected, &$errors): void {
            if (($state[$p] ?? 0) === 2) {
                return;
            }
            if (($state[$p] ?? 0) === 1) {
                $errors[] = new ResolverError(
                    ResolverError::DEPENDENCY_CYCLE,
                    $p,
                    "Dependency cycle involving {$p}."
                );
                return;
            }
            $state[$p] = 1;
            foreach ($selected[$p]->requiresExtensions as $dep) {
                $dep = ltrim($dep, '\\');
                if (isset($selected[$dep])) {
                    $visit($dep);
                }
            }
            $state[$p] = 2;
            $result[] = $p;
        };

        foreach ($order as $p) {
            $visit($p);
        }
        return $result;
    }
}

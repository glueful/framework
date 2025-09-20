<?php

declare(strict_types=1);

namespace Glueful\Permissions;

/**
 * Registry maps resource slugs (e.g., 'posts') or FQCNs to policy classes.
 */
final class PolicyRegistry
{
    /** @var array<string, class-string<PolicyInterface>> */
    private array $map = [];

    /** @param array<string, class-string<PolicyInterface>> $map */
    public function __construct(array $map = [])
    {
        $this->map = $map;
    }

    public function register(string $resourceOrClass, string $policyClass): void
    {
        $this->map[$resourceOrClass] = $policyClass;
    }

    public function get(string $resourceOrClass): ?PolicyInterface
    {
        $cls = $this->map[$resourceOrClass] ?? null;
        if ($cls === null) {
            return null;
        }
        /** @var PolicyInterface $p */
        $p = new $cls();
        return $p;
    }
}

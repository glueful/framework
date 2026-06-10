<?php

declare(strict_types=1);

namespace Glueful\Entitlements\Contracts;

/**
 * Commercial entitlement gate: "does this tenant's plan include this capability?"
 *
 * Entitlements are paywall/capability gates, NOT security boundaries -- they are
 * absent-allow (see NullEntitlementChecker), the opposite of authorization (aegis)
 * and tenancy, which fail closed. The checker takes an explicit tenant uuid so it
 * works in jobs, CLI, webhooks, and admin flows outside a request.
 */
interface EntitlementCheckerInterface
{
    /**
     * @param array<string,mixed> $context optional extras (e.g. a resource id)
     */
    public function allows(string $tenantUuid, string $entitlement, array $context = []): bool;

    /**
     * @param array<string,mixed> $context optional extras (e.g. a resource id)
     */
    public function limit(string $tenantUuid, string $entitlement, array $context = []): ?int;
}

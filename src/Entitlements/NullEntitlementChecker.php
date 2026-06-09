<?php

declare(strict_types=1);

namespace Glueful\Entitlements;

use Glueful\Entitlements\Contracts\EntitlementCheckerInterface;

/**
 * Absent-allow default bound by core when no entitlements provider is installed.
 *
 * Entitlements are commercial gates, not security boundaries, so the absence of a
 * subscriptions/entitlements extension must never lock an app out of its own routes.
 * glueful/subscriptions (or any provider) overrides the container binding with a real
 * tenant-aware checker.
 */
final class NullEntitlementChecker implements EntitlementCheckerInterface
{
    public function allows(string $tenantUuid, string $entitlement, array $context = []): bool
    {
        return true;
    }

    public function limit(string $tenantUuid, string $entitlement, array $context = []): ?int
    {
        return null;
    }
}

# Entitlements

The **entitlement seam** is a core extension point for gating *commercial
capabilities* -- "does this tenant's plan include this feature?" Core publishes
the contract and an allow-all default; a subscriptions/entitlements extension
(reference: `glueful/subscriptions`) provides the real, tenant-aware checker by
overriding the container binding.

## What it is -- and what it is NOT

Entitlements are **paywall / capability gates**, not security boundaries.

| Concern | Direction | When absent |
|---|---|---|
| **Entitlements** (this seam) | absent-**allow** | everything is allowed -- the app keeps working |
| **Authorization** (aegis / `Glueful\Permissions`) | fail-**closed** | access is denied |
| **Tenancy** (`glueful/tenancy`) | fail-**closed** | no tenant context -> denied |

Authorization and tenancy fail closed because they protect data. Entitlements
fail **open** because they protect *revenue*: the absence of a subscriptions
extension must never lock an app out of its own routes. If you need to deny
access for security reasons, use authorization (aegis), not entitlements.

## The contract

`Glueful\Entitlements\Contracts\EntitlementCheckerInterface`:

```php
interface EntitlementCheckerInterface
{
    /** @param array<string,mixed> $context optional extras (e.g. a resource id) */
    public function allows(string $tenantUuid, string $entitlement, array $context = []): bool;

    /** @param array<string,mixed> $context optional extras (e.g. a resource id) */
    public function limit(string $tenantUuid, string $entitlement, array $context = []): ?int;
}
```

- `allows()` answers a yes/no capability question (e.g. `reports.export`).
- `limit()` returns a numeric quota for metered capabilities (e.g.
  `projects.limit`), or `null` for "unlimited / no opinion".
- The **tenant uuid is explicit and first**. The checker never reads an ambient
  request to discover the tenant, so it works unchanged in queue jobs, CLI
  commands, webhooks, and admin flows that act on behalf of a tenant outside a
  normal HTTP request.

## The absent-allow default

When no entitlements provider is installed, core binds
`Glueful\Entitlements\NullEntitlementChecker`:

```php
final class NullEntitlementChecker implements EntitlementCheckerInterface
{
    public function allows(string $tenantUuid, string $entitlement, array $context = []): bool
    {
        return true; // every capability is allowed
    }

    public function limit(string $tenantUuid, string $entitlement, array $context = []): ?int
    {
        return null; // no quota / unlimited
    }
}
```

This is the mirror image of `UserProviderInterface -> NullUserProvider`, which
fails *closed*. The binding lives in `Glueful\Container\Providers\CoreProvider`.

## Overriding the binding

Any provider can replace the default by binding
`EntitlementCheckerInterface` to its own implementation. Because extension
service definitions override core defaults (container precedence), the override
just works through the normal provider path:

```php
use Glueful\Container\Definition\FactoryDefinition;
use Glueful\Entitlements\Contracts\EntitlementCheckerInterface;

public function defs(): array
{
    return [
        EntitlementCheckerInterface::class => new FactoryDefinition(
            EntitlementCheckerInterface::class,
            fn($c) => new MyPlanAwareEntitlementChecker(/* ... */)
        ),
    ];
}
```

Consumers type against the **core contract** and never depend on
`glueful/subscriptions` (or any other concrete provider) directly.

## Core ships no consumer

Core publishes the contract and the null default -- nothing more. There is no
core subsystem that reads entitlements; in particular, **rate limiting is
untouched** and still depends only on its own `TierResolverInterface`.

Wiring that crosses domains -- for example, an entitlement-driven rate-limit
`TierResolver` that maps a tenant's plan to a request tier -- is *coupling*
between two domains (entitlements and rate limiting). That coupling belongs in
the extension that knows both, not in core. The reference bridge
(`EntitlementTierResolver`) lives in `glueful/subscriptions`. Keeping core
domain-ignorant is the whole point: any extension can reuse the entitlement
contract without dragging in subscription, tenant, or plan vocabulary.

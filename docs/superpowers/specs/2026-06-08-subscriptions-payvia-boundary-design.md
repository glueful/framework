# `glueful/subscriptions` + Payvia Boundary - Design Note

**Status:** Draft boundary decision; no code yet.  
**Date:** 2026-06-08  
**Scope:** `glueful/subscriptions` as a future first-party extension, `glueful/payvia` v-next boundary changes, and the optional core entitlement seam that lets framework code and extensions consume plan gates without coupling to subscription storage.

## Problem

Glueful already has most of the reusable SaaS spine as focused extensions:

| SaaS concern | Existing package |
|---|---|
| Tenant isolation, tenant context, memberships | `glueful/tenancy` |
| Identity and account lifecycle | `glueful/users` |
| Social login / SSO | `glueful/entrada` |
| Authorization / RBAC | `glueful/aegis` |
| Payment gateway bridge | `glueful/payvia` |

The missing package is not a monolithic `glueful/saas`, and it should not be named `glueful/billing`. The reusable gap is tenant subscription lifecycle and entitlement checks:

- Which plan is a tenant on?
- Is the tenant subscription active, trialing, past due, canceled, or in grace?
- Which features and limits are available to the tenant?
- How much quota remains for a metered capability?
- Which provider-side payment/subscription objects map to this tenant state?

The correct package name is **`glueful/subscriptions`**.

The boundary is materially affected by Payvia's current surface. Payvia is not just a one-off payment rail today:

- `PaymentGatewayInterface` exists, but only exposes `verify(string $reference, array $options = []): array`.
- `PaystackGateway` is the only implemented gateway, despite docs describing Stripe and Flutterwave as future/possible gateways.
- `PaymentService`, `PaymentRepository`, and the `payments` table handle verified payment recording.
- `InvoiceService`, `InvoiceRepository`, and the `invoices` table already exist.
- `BillingPlanService`, `BillingPlanRepository`, and the `billing_plans` table already exist.
- `billing_plans.features` stores JSON feature flags / usage limits and is queryable through `features_contains`.
- There is no webhook ingestion surface.
- There is no recurring or provider-subscription abstraction.

So the future subscriptions package cannot be specified cleanly until Payvia's "plan + features" overlap is resolved.

## Decision

Build **`glueful/subscriptions`** as the tenant-scoped subscription and entitlement package, but first reconcile Payvia's boundary.

The package split should be:

| Package | Owns | Does not own |
|---|---|---|
| `glueful/tenancy` | Tenants, memberships, tenant context, tenant isolation | Plans, subscription state, payments, entitlements |
| `glueful/payvia` | Gateway payments, gateway customer/price/subscription objects, payment verification, payment webhooks, invoices, priced plans | Tenant entitlement plans, feature gates, quota policy |
| `glueful/subscriptions` | Tenant subscriptions, entitlement plans, entitlement resolution, plan overrides, subscription lifecycle projection, quota/metering API | Payment verification, gateway-specific API calls, raw provider webhook parsing |
| App code | Signup UX, checkout screens, onboarding, invite flows, product-specific upgrade flows | Reusable framework subscription primitives |

This preserves Glueful's extension philosophy: focused first-party packages with narrow ownership, composed by applications.

## The Plan Split

There are two distinct "plan" concepts. They must not share one table or one source of truth.

### Payvia Priced Plan

Payvia's plan should represent the money side:

- amount
- currency
- interval
- trial days if this is represented by the provider pricing model
- gateway product ID
- gateway price/plan ID
- status
- lightweight provider/app metadata

Payvia should **not** own product capability semantics such as:

- `reports.export = true`
- `projects.limit = 50`
- `team_members.limit = 20`
- `api.requests.monthly = 100000`

Those are entitlements, not gateway pricing.

### Subscriptions Entitlement Plan

Subscriptions should represent the product access side:

- plan key / display name
- entitlement defaults
- quota defaults
- optional reference to a Payvia priced plan
- per-tenant overrides
- app-facing subscription state

Example:

```text
tenant_uuid: acme
subscription status: active
entitlement plan: pro
payvia priced plan: payvia_plan_abc123
gateway subscription: stripe:sub_456 or paystack:...

entitlements:
  reports.export = true
  projects.limit = 50
  team_members.limit = 20
  api.requests.monthly = 100000
```

## Payvia Boundary Changes

Payvia mostly needs to grow, not shrink.

| Payvia surface | Decision |
|---|---|
| `PaymentGatewayInterface`, gateway manager, payment verification | Stay in Payvia |
| `payments` table and payment recording | Stay in Payvia |
| `invoices` table and invoice services | Stay in Payvia for now; do not over-fragment |
| `billing_plans` as priced plans | Stay in Payvia, but clarify as priced/gateway plans |
| `billing_plans.features` | Deprecate/migrate away from entitlement semantics |
| Webhook ingestion | Add to Payvia |
| Normalized provider event stream | Add to Payvia |
| Provider-side recurring/subscription objects | Add to Payvia |
| Tenant entitlements and quota policy | Belongs to `glueful/subscriptions` |

Payvia v-next should introduce a normalized event seam before subscriptions relies on provider state. For example:

```php
interface PaymentProviderEventInterface
{
    public function gateway(): string;
    public function type(): string;
    public function providerEventId(): string;
    public function occurredAt(): \DateTimeImmutable;
    /** @return array<string,mixed> */
    public function payload(): array;
}
```

The exact shape can change in the Payvia spec, but the requirement is stable: subscriptions should consume normalized Payvia events rather than parse Stripe, Paystack, or Flutterwave webhook payloads directly.

## Subscription Ownership Split

"Subscription" itself has two meanings. The packages should own different objects.

| Object | Owner | Meaning |
|---|---|---|
| Provider subscription | `glueful/payvia` | Gateway-side recurring billing object, provider IDs, provider status, webhook sync |
| Tenant subscription | `glueful/subscriptions` | App-facing tenant subscription, entitlement plan, lifecycle projection, overrides |

The tenant subscription may reference Payvia objects:

```text
subscriptions.tenant_uuid
subscriptions.plan_key
subscriptions.status
subscriptions.payvia_gateway
subscriptions.payvia_customer_id
subscriptions.payvia_subscription_id
subscriptions.payvia_priced_plan_uuid
```

But the tenant subscription remains the app-facing source for entitlement resolution.

**Entitlements decouple from payment.** A tenant subscription can exist with no Payvia object at all -- a free tier, an in-app trial (no card), a comped enterprise account, or an internal tenant. The `payvia_*` fields are nullable, and entitlement resolution depends **only** on the tenant subscription + plan, never on a live provider object. This keeps free/trial/comp flows working without `glueful/payvia` installed, and cleanly separates "what can this tenant do" from "is this tenant paying."

## Entitlement Contract Placement

The reusable runtime value is a small contract -- `EntitlementCheckerInterface` -- that lets framework systems and other extensions ask "is this capability in the tenant's plan?" without coupling to subscription tables. **Resolved: the contract is promoted to framework core** (`Glueful\Entitlements\`), contract-only -- `EntitlementCheckerInterface` + an allow-all `NullEntitlementChecker` default bound by `CoreProvider`. `glueful/subscriptions` *consumes* it (binds a real checker over the default) and provides the first consumer, the `EntitlementTierResolver` rate-limit bridge. See "Promotion to a core seam" for why now and the rule.

### Contract shape

Namespace `Glueful\Entitlements` (`Plans` conflates with the subscriptions-owned catalog; a generic name collides with Aegis/authz). The checker takes an explicit `tenantUuid` as its first argument -- not a generic context bag -- mirroring `PermissionManager::can($userUuid, ...)`; `context` carries only optional extras.

```php
namespace Glueful\Entitlements\Contracts;

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
```

### Default: allow-all

The default is **allow-all / unlimited**, because entitlements are commercial gates, not security boundaries -- installing no subscriptions package must never lock an app out of its own routes (the opposite of Aegis/tenancy, which fail closed). **Core** binds this `NullEntitlementChecker` via `CoreProvider`; `glueful/subscriptions` binds a real tenant-aware checker **over** it (last-provider-wins, enabled by the framework container-precedence fix).

```php
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
```

### Promotion to a core seam (DONE -- contract only)

The contract is now in framework core. It earned promotion because a concrete consumer exists: the **`EntitlementTierResolver` rate-limit bridge** (in `glueful/subscriptions`) consumes both `EntitlementCheckerInterface` and the framework's `TierResolverInterface`. Two framework changes carry it:

1. A **container-precedence fix** -- `ContainerFactory` merged extension defs with `+=`, which silently dropped any extension override of a core-bound key, so core defaults (incl. `UserProviderInterface` and this new seam) were un-overridable. Now `array_replace` (extension-over-core), with `ApplicationContext` re-pinned.
2. The **entitlement seam** itself -- `EntitlementCheckerInterface` + `NullEntitlementChecker` + the `CoreProvider` binding + docs. No consumer in core.

**The rule going forward:** *promote the contract to core as a shared extension point; keep consumers extension-side unless a consumer is naturally generic and tenancy-free.* Core never learns about tenants, plans, or subscription vocabulary -- the cross-domain wiring (tenant -> entitlement -> rate tier) lives in the extension that already knows both domains. The rate-limit bridge is the proof: it sits in the extension via the existing `TierResolverInterface` seam, and core rate limiting still depends only on `TierResolverInterface`, ignorant of entitlements.

### Keep Entitlements Orthogonal to Aegis

Aegis answers:

```text
Can this user perform this action?
```

Entitlements answer:

```text
Does this tenant's commercial plan include this capability?
```

They compose, but they should not share one gate or default behavior:

- Aegis / permissions are authorization and should fail closed.
- Entitlements are paywall/capability limits and should be absent-allow.

A gated action may require both:

```text
user has aegis permission: reports.export
tenant has entitlement: reports.export
```

## Usage Metering Is Separate

Stateless entitlement checks are cheap:

```php
$entitlements->allows($tenantUuid, 'reports.export');
$entitlements->limit($tenantUuid, 'projects');
```

Stateful usage checks are harder:

```php
$usage->remaining($tenantUuid, 'api.requests');
$usage->consume($tenantUuid, 'api.requests', 1);
```

Usage metering needs atomic counters, reset windows, idempotency keys, concurrency handling, and probably Redis-backed hot counters with durable reconciliation. It should be designed as a bounded second component, likely `UsageMeterInterface`, rather than forcing all of that into v1 of entitlement checks.

Recommended phasing:

1. `glueful/subscriptions` v1: plans, tenant subscriptions, stateless entitlements, numeric limits, route middleware.
2. `glueful/subscriptions` v1.1/v2: usage metering and quota consumption.

## Data Model Direction

V1 should prefer a config-driven entitlement catalog, with per-tenant overrides:

```php
// config/subscriptions.php
return [
    'plans' => [
        'free' => [
            'entitlements' => [
                'reports.export' => false,
                'projects.limit' => 3,
            ],
        ],
        'pro' => [
            'entitlements' => [
                'reports.export' => true,
                'projects.limit' => 50,
            ],
        ],
    ],
];
```

This keeps the product catalog versioned with code and avoids making v1 a full plan-management CMS. DB-defined entitlement plans can be a later feature.

Minimum tables for `glueful/subscriptions`:

| Table | Purpose |
|---|---|
| `subscriptions` | One current/app-facing subscription per tenant, with status and Payvia references |
| `subscription_overrides` | Per-tenant entitlement overrides for custom deals |
| `subscription_events` | Internal lifecycle audit log / projection history |

Later usage-metering tables or Redis-backed counters should be specified separately.

Subscription tables are tenant-keyed, but they should not be treated as ordinary tenant-isolated domain rows. Billing/admin/system jobs need cross-tenant queries. Use explicit `tenant_uuid` keys and explicit checker APIs rather than relying solely on request-scoped tenant filters.

`tenant_uuid` is an **opaque indexed key with no foreign key** to tenancy's `tenants` table -- mirroring how `glueful/tenancy` stores `user_uuid` with no FK to the user store. `glueful/tenancy` is therefore a **soft** dependency: subscriptions needs *a* tenant uuid, not necessarily that specific extension, and there are no cross-package FKs.

`subscriptions.status` is an **eventually-consistent projection** of provider state delivered via Payvia events, so it needs a reconciliation path (a `subscriptions:reconcile` job / periodic pull) to recover from missed or out-of-order webhooks. `subscription_events` backs this.

## Runtime Integration

The main runtime payoff is a small set of shared integration points:

```php
$entitlements->allows($tenantUuid, 'reports.export');
$entitlements->limit($tenantUuid, 'projects');
```

Potential consumers:

- route middleware: `RequireEntitlement('reports.export')`
- controllers and services
- rate-limit tier resolution
- API feature gates
- extensions that need commercial gating without depending on `glueful/subscriptions`

The checker takes `tenantUuid` as an explicit first argument so it works in jobs, CLI commands, webhooks, and admin workflows outside a request -- a thin "current tenant" convenience (reading tenancy's request context) and the `RequireEntitlement` middleware cover the in-request path.

Resolved entitlement sets should be cacheable per tenant and invalidated when subscription state, plan config version, or overrides change.

## Sequencing

This is two coordinated specs, not one implementation plan.

1. **Payvia v-next boundary spec**
   - Clarify `billing_plans` as priced plans.
   - Deprecate or migrate `billing_plans.features` away from entitlement semantics.
   - Add webhook ingestion and idempotency.
   - Add normalized provider events.
   - Add gateway recurring/provider-subscription concepts.
   - Decide migration and backward-compatibility strategy for existing Payvia consumers.

2. **Subscriptions v1 spec**
   - Define tenant subscription schema.
   - Define config entitlement catalog and overrides.
   - Bind `EntitlementCheckerInterface`.
   - Add a no-op default; promote it to core only if the seam is accepted.
   - Add `RequireEntitlement` middleware.
   - Consume Payvia priced plans/events once Payvia exposes the required surfaces.

## Breaking Change Policy

Payvia is an official extension, so changing `billing_plans.features` is a breaking or at least migration-relevant change.

Preferred path:

1. Keep the column temporarily as deprecated metadata.
2. Stop documenting it as feature flags or usage limits.
3. Introduce replacement Payvia metadata fields only for gateway/pricing metadata.
4. Let `glueful/subscriptions` own entitlement definitions.
5. Provide a migration guide for apps that stored entitlements in Payvia plans.

Do not pull invoices or priced plans out of Payvia just for conceptual purity. The important correction is that Payvia owns money/provider objects, while subscriptions owns app access and plan capabilities.

## Spec Anchor

`glueful/subscriptions` provides tenant-scoped subscription lifecycle and entitlement management for SaaS apps. It resolves entitlements from an explicit `tenant_uuid` -- a soft dependency on `glueful/tenancy`, not a hard one -- and optionally integrates with `glueful/payvia` for priced plans and payment-provider subscription events. It owns the entitlement plan, the app-facing tenant subscription state, and per-tenant overrides, and resolves entitlements without a live payment object, so free/trial/comp tenants work with no Payvia installed. Its primary runtime value is an `EntitlementCheckerInterface` (allow-all no-op default) that apps, middleware, rate limiting, and other extensions consume without knowing subscription internals; the contract now lives in **framework core** (`Glueful\Entitlements\`, contract only) and this extension binds the real checker over the core default and adds the first consumer (the `EntitlementTierResolver` rate-limit bridge). Entitlements are commercial paywall gates and remain orthogonal to Aegis permissions, which are authorization gates.

## Resolved Decisions

- **Core seam namespace:** `Glueful\Entitlements` (`Plans` conflates with the subscriptions-owned catalog; a generic name collides with Aegis/authz).
- **Contract signature:** explicit `tenantUuid` first, not a generic context bag -- `allows(string $tenantUuid, string $entitlement, array $context = [])` -- mirroring `PermissionManager::can($userUuid, ...)`.
- **`billing_plans` rename:** no. Keep the table, clarify it as priced/gateway plans, and deprecate the `features` column -- renaming a published table is a large migration for cosmetic gain.
- **Provider subscription state:** Payvia **persists** provider objects (durable, queryable, reconcilable) **and** emits normalized events; not events-only.
- **Usage metering:** out of subscriptions v1 -> v1.1/v2. It is the part most likely to balloon (atomic counters, reset windows, Redis), and keeping it out protects v1's scope.
- **Core seam adoption:** **DONE -- promoted to framework core (contract only).** The concrete consumer is the `EntitlementTierResolver` rate-limit bridge (extension-side). Carried by two framework changes: the container-precedence fix (extension-over-core overrides) and the entitlement seam (`EntitlementCheckerInterface` + `NullEntitlementChecker` + `CoreProvider` binding). **Rule:** promote the contract to core; keep consumers extension-side unless naturally generic and tenancy-free. (Supersedes the earlier "ship inside the extension, promote when a second consumer appears" position.)

## Still Open

- Exact normalized Payvia event shape (`PaymentProviderEventInterface`) -- finalized in the Payvia v-next spec.
- Reconciliation cadence/strategy for `subscriptions:reconcile` (provider pull frequency, drift handling) -- a Subscriptions v1 spec detail.

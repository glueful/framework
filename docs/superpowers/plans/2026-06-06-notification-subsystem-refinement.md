# Notification Subsystem Refinement — Implementation Plan

> **For agentic workers:** implement phase-by-phase with TDD (write the failing test first, then the minimal code). Each phase is independently shippable and reviewable. Do not start a phase before the previous one is green.

**Goal:** Land the refinements in `docs/superpowers/specs/2026-06-06-notification-subsystem-refinement-design.md` without breaking post-1.0 contracts — one source of truth for channels, a safe no-DB path, an injectable queue, and a richer (opt-in) result contract.

**Architecture:** Each phase is additive or internal. New code lives under `src/Notifications/{Channels,Stores,Results,Exceptions}/`. `NotificationChannel::send(): bool`, the `QueueManager` fallback, and the `notifications` capability default (`true`) all stay. Tech: PHP 8.3, PHPUnit 10.5, lightweight SQLite `Connection` harness for library tests (extend `PHPUnit\Framework\TestCase`, not the app `TestCase`).

**Spec decisions referenced below by §** (Decisions section of the design note).

---

## Phase 1 — Core `DatabaseChannel` + remove the hardcoded channel list

**Create**
- `src/Notifications/Channels/DatabaseChannel.php` — implements `NotificationChannel`. `getChannelName(): 'database'`. **Role correction:** the notification is *already persisted by `NotificationService` before dispatch* (`NotificationService.php:141`), and `send()` only receives `$notifiable` + formatted `$data` — **no `Notification` object/UUID** (`NotificationDispatcher.php:154`). So this channel is a **pure acknowledge** — it performs **no DB writes at all**. The service already owns *both* persistence (`save`, `:141`) *and* delivery records (`ensureDeliveryRecords` pre-dispatch + `recordDeliveryAttempt` post-dispatch, `:167`); a channel-side delivery write would double-write. `send()` returns `true` when persistence is active; `format()` returns data unchanged. `isAvailable()`: **false when persistence is off** — a database channel without persistence must not report success (§3). `send(): bool`: returns `true` only when persistence is active. (§1)
- `tests/Unit/Notifications/Channels/DatabaseChannelTest.php`
- `tests/Unit/Notifications/NotificationServiceChannelValidationTest.php`

**Modify**
- `src/Container/Providers/NotificationsProvider.php` — register `DatabaseChannel` into `ChannelManager` by default.
- `src/Notifications/Services/NotificationService.php` — delete `$validChannels` array + the `default_channels` membership normalizer (`:1160–1167`); keep only structural normalization: **trim + non-empty + dedupe, no lowercase** (§2); still reject an empty array.

**Behavior**
- A default `send()` with `['database']` now persists *and* dispatches successfully (no more `channel_not_found` on the default).
- Unknown channels fail at **dispatch** with the dispatcher's existing `channel_not_found` (`NotificationDispatcher.php:116`); registered-but-unavailable fail with `channel_unavailable` (`:127`). Construction no longer rejects channel names.

**Tests** (all implemented & green)
- *Unit* (`DatabaseChannelTest`): `send()` acknowledges (returns `true`) with persistence on; `isAvailable()` is **false** with persistence off; `format()` returns data unchanged.
- *Structural no-double-persist* (`DatabaseChannelTest::testHoldsNoPersistenceDependency`): the constructor holds no `Connection`/repository, so it cannot write a second notification/delivery row. (The service remains the sole writer — see note below.)
- *Dispatch outcomes, DB-free* (`DatabaseChannelDispatchTest`): registered+available `database` → `success`; unregistered channel → `channel_not_found`; persistence-off `database` → `channel_unavailable` (never success).
- *Normalizer* (`NotificationServiceChannelValidationTest`): custom mixed-case name preserved (no lowercasing); exact duplicates removed; whitespace trimmed/blank dropped; empty `default_channels` throws; non-string throws.

> **Row-count note:** "exactly one `notifications` row after a default send" is a property of the **service's** `save()` (unchanged in Phase 1), not the channel — and the channel structurally cannot write. The full end-to-end row-count assertion needs a booted-DB harness, so it is deferred to **Phase 2's** store tests (where that harness is introduced) rather than duplicated here.

**Rollback risk:** Low. No new public contracts. Only behavioral change: validation moves from construction to dispatch. Revert = restore the `$validChannels` block + drop the channel/provider line.

---

## Phase 2 — `NotificationStoreInterface` + Null store + provider binding ✅ implemented

> **Two deviations from the original plan, made during implementation:**
> 1. **No separate `DatabaseNotificationStore` class.** Since the compat decision is "`NotificationRepository implements NotificationStoreInterface`", the repo *is* the database store — a wrapper would be dead weight. The provider binds the interface to the repo directly (or the null store).
> 2. **Added `isPersistent(): bool` to the interface** (repo → `true`, null → `false`). It's the persistence-state signal the scheduled/dispatch/retry guards need, and unlike a capability re-read it needs no `ApplicationContext`.

**Created**
- `src/Notifications/Contracts/NotificationStoreInterface.php` — `isPersistent()` + the 15 ops the service/retry actually use: `save`, `findByUuid`, `findForNotifiable(+WithPagination)`, `findPendingScheduled`, `findRecentByIdempotencyKey`, `ensureDeliveryRecords`, `getChannelsNeedingDispatch`, `recordDeliveryAttempt`, `getFailedDeliveryChannels`, `savePreference`, `findPreferencesForNotifiable`, `countForNotifiable`, `markAllAsRead`, `deleteOldNotifications`. Signatures mirror the repo so it can `implements` cleanly.
- `src/Notifications/Stores/NullNotificationStore.php` — explicit per-op (§6).
- `src/Notifications/Exceptions/NotificationPersistenceDisabledException.php`
- Tests: `NullNotificationStoreTest`, `NotificationServiceStoreTest`, `NotificationNoDbGuardsTest`.

**Modified**
- `src/Repository/NotificationRepository.php` — `implements NotificationStoreInterface`; `isPersistent() → true` (the compat move, §G4).
- `src/Notifications/Services/NotificationService.php` — ctor param widened to `NotificationStoreInterface`; `getStore()` added; `getRepository()` kept (throws `NotificationPersistenceDisabledException` when the store is not a repo); **scheduled** (`send()` with a `DateTime` schedule), **`processScheduledNotifications()`** (the worker side), and **`dispatchStoredNotification`** throw when `!isPersistent()`.
- `src/Notifications/Services/NotificationRetryService.php` — store widened to the interface; `queueForRetry`/`processDueRetries` throw when `!isPersistent()`.
- `src/Container/Providers/NotificationsProvider.php` — binds `NotificationStoreInterface` → repo or `NullNotificationStore` by the `notifications` capability; the service consumes the binding.
- Direct sites (§5): `NotificationRetryTask` (gained a `?ApplicationContext` param, resolves the container service/store), `NotificationRetryJob` (passes its context), `DispatchNotificationChannels`, `ProcessRetriesCommand` — all resolve the capability-aware store via `app($context, NotificationStoreInterface::class)` instead of `new NotificationRepository()`.

**Behavior (no-DB matrix, §6)** — with persistence disabled:
- sync `send()` → allowed **over non-persistent channels only**, ephemeral. A `database`-channel send yields `channel_unavailable` (`DatabaseChannel::isAvailable()` is false);
- `dispatchStoredNotification`, scheduled, retry (incl. `NotificationRetryService`), preferences, read/unread, `deleteOldNotifications` → throw `NotificationPersistenceDisabledException`;
- reads/counts → empty/null/zero; transient writes (`save`, `ensureDeliveryRecords`, `recordDeliveryAttempt`) → no-op.
- Idempotency (`findRecentByIdempotencyKey`) is **unavailable** under the null store (duplicates possible).

**Verified:** full suite green; `composer analyse` (src) clean; phpcs clean.

**Rollback risk:** Low–Medium. The compat move is non-breaking; the main risk was the no-DB *semantics* (which ops throw vs no-op) — covered by `NullNotificationStoreTest` + `NotificationNoDbGuardsTest`.

**Still deferred:** the end-to-end **row-count** assertion (needs a booted-DB harness). And `ProcessRetriesCommand`/`DispatchNotificationChannels` still hand-build a `ChannelManager` without the core `database` channel (low impact — they target async channels; `NotificationRetryTask` resolves the container service instead) — follow-up.

---

## Phase 3 — Injectable queue dispatch (additive, keep fallback)

**Create**
- `src/Notifications/Contracts/NotificationQueueDispatcherInterface.php` — minimal: enqueue a notification job (wraps `QueueManager::push`/`later`).
- `src/Notifications/Queue/QueueManagerNotificationDispatcher.php` — default impl over `QueueManager`.
- `tests/Unit/Notifications/QueueDispatchInjectionTest.php`

**Modify**
- `src/Notifications/Services/NotificationService.php` — accept an optional `NotificationQueueDispatcherInterface`; replace inline `new QueueManager(...)` / `QueueManager::createDefault()` (`:1093,:1101`) with the injected dispatcher, **keeping the current construction as the fallback** when none is injected (§G4/G5).
- `src/Container/Providers/NotificationsProvider.php` — bind the default dispatcher.

**Behavior:** Identical default behavior; queueing is now overridable and testable. `send()` never requires a queue.

**Tests**
- Injected dispatcher is used when provided (spy asserts enqueue called, `QueueManager` not constructed).
- With no dispatcher injected, the fallback path still enqueues (back-compat).

**Rollback risk:** Low. Purely additive; default path unchanged. Revert = drop the optional param + binding.

---

## Phase 4 — `NotificationResult` + `RichNotificationChannel` adapter + parser cleanup

**Create**
- `src/Notifications/Results/NotificationResult.php` — readonly DTO: `success`, `providerMessageId`, `retryable`, `errorCode`, `latencyMs`, `metadata`.
- `src/Notifications/Contracts/RichNotificationChannel.php` — **separate opt-in** interface with `sendNotification(Notifiable, array): NotificationResult` (NOT an override of `send(): bool`, §3).
- `tests/Unit/Notifications/Results/NotificationResultNormalizationTest.php`

**Modify**
- `src/Notifications/Services/NotificationDispatcher.php` — single normalization point: `instanceof RichNotificationChannel` → call `sendNotification()`; else call legacy `send(): bool` and adapt to a `NotificationResult`.
- `src/Notifications/Utils/NotificationResultParser.php` — shrink to a generic `bool → NotificationResult` adapter; provider-specific knowledge moves into channels (§G6).
- `src/Queue/Jobs/SendNotification.php:125` — stop calling `parseEmailResult()` unconditionally; use the dispatcher-normalized result regardless of channel/type.

**Behavior:** Legacy bool channels keep working unchanged; channels may opt into rich results. No signature change to `NotificationChannel`.

**Tests**
- A `RichNotificationChannel` returns its `NotificationResult` through the dispatcher intact (message id/error code preserved).
- A legacy bool channel is adapted to a `NotificationResult` with matching `success`.
- `SendNotification` job records the correct result for a non-email channel (regression for the `parseEmailResult` bug).

**Rollback risk:** Medium (new contract) but non-breaking. Revert = dispatcher ignores `RichNotificationChannel`, restore parser.

---

## Phase 5 — Channel auto-registration (separate spec, not implemented here)

Write `docs/superpowers/specs/2026-06-XX-notification-channel-registration-design.md`: route channel packages through the existing Glueful service-provider/extension lifecycle into the shared `ChannelManager` (§G7); scope/fold the `NotificationExtension` contract so there is no parallel system; optionally introduce `getRegisteredChannelNames()` / `getActiveChannelNames()` with the current methods kept as aliases (§7). No code in this plan.

---

## Cross-cutting

- **Verification gate per phase:** `composer test` (targeted suite green), `composer run analyse` (no new PHPStan errors), `composer run phpcs`.
- **Capability default stays `true`** (`config/capabilities.php:25`); Phase 2 only makes `false` *safe* — no default change.
- **Docs:** update `docs/MIGRATIONS_AND_CAPABILITIES.md` (Phase 2, the no-DB matrix) and add a short notifications channels/result note (Phase 4). CHANGELOG `[Unreleased]` entry per phase.

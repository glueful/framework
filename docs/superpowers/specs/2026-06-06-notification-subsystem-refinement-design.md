# Notification Subsystem Refinement — Design Note

**Status:** Draft for review (no code yet) · **Scope:** `src/Notifications/**`, the persistence + queue seams. Channel packages (Notiva, email-notification) are out of scope except as contract consumers.

## Problem

Five confirmed smells in core notifications:

1. **Two sources of truth for channels** — `NotificationService` validates against a hardcoded list (`Services/NotificationService.php:1160`); dispatch/metrics use the live `ChannelManager` registry (`:886`).
2. **`NOTIFICATIONS_DATABASE_STORE=false` isn't safe** — core ships default `true` (`config/capabilities.php:25`), and the service is hard-coupled to a concrete `NotificationRepository`, calling `save()`/`ensureDeliveryRecords()`/`findPendingScheduled()` directly. Nothing reads the capability.
3. **Weak channel contract** — `NotificationChannel::send(): bool` carries no message id / retryability / error code / latency. `NotificationResultParser` already works around this (with provider-specific knowledge leaking into core).
4. **Queue built inside the service** — `new QueueManager()` / `createDefault()` inline (`:1093,:1101`).
5. **No first-class channel auto-registration** — `ChannelManager::registerChannel()` exists but core registers nothing; risk of a parallel "notification extension" system.

## Guardrails

- **G1** Don't validate channel *registration* at construction — core registers no channel yet but defaults to `['database']`, so eager validation breaks the default service. Normalize names at construction; validate at dispatch.
- **G2** Three distinct failure modes: invalid config vs registered-but-unavailable vs not-installed. Dispatcher already has `channel_not_found` (`NotificationDispatcher.php:116`) and `channel_unavailable` (`:127`) — reuse them.
- **G3** No-DB behavior is explicit per operation, never silently successful.
- **G4** Post-1.0 compatible: additive contracts/adapters only; no signature change to `send()`, no removing the queue fallback.
- **G5** Async queueing stays optional — `send` must not require a queue.
- **G6** Keep provider-specific knowledge out of core (`NotificationResultParser` must not become a provider registry).
- **G7** One registration path — normal service providers contributing to the shared `ChannelManager`.

## Workstreams (priority order)

1. **Ship a core `database` channel + remove the hardcoded list.**
   - Add a core `DatabaseChannel` (persist notification, optionally create a delivery row, return success when persistence succeeded) and register it by default in `NotificationsProvider`, so the `['database']` default is coherent end-to-end — *without* waiting for #5.
   - Delete the `$validChannels` array + ctor membership check; keep only structural normalization (trim, non-empty, dedupe — **not** lowercase, see Decisions §2); let the dispatcher's existing `channel_not_found`/`channel_unavailable` validate at send.

   *Low risk; ships first.* (G1, G2)
2. **Graceful no-DB store.** Add `NotificationStoreInterface` with `DatabaseNotificationStore` (wraps current repo) and `NullNotificationStore` — explicit per op: fire-and-forget → no-op; durability-implying (preferences, scheduling, retry) → `NotificationPersistenceDisabledException`; reads → empty. Provider binds by the `notifications` capability. Default stays `true`; this just makes `false` safe. (G3)
3. **Inject queue dispatch (additive).** Add `NotificationQueueDispatcherInterface`; inject it but keep the current `QueueManager` fallback. (G4, G5)
4. **`NotificationResult` value object.** Add the DTO + an opt-in `RichNotificationChannel` exposing a **new** `sendNotification(...): NotificationResult` — *not* an override of `send(): bool` (overriding is an incompatible signature; see Decisions §3). Dispatcher probes for `RichNotificationChannel`, else falls back to `send(): bool`. As channels adopt it, provider-specific parsing moves into the channels and `NotificationResultParser` shrinks to a legacy `bool → NotificationResult` adapter — this *is* G6. Concrete cleanup: `Queue/Jobs/SendNotification.php:125` calls `parseEmailResult()` unconditionally regardless of channel/type — fix here.
5. **Standardize channel auto-registration.** Channels register via the existing provider/extension lifecycle into the shared `ChannelManager`; fold/scope the `NotificationExtension` contract so there's no parallel system. *Largest surface — own follow-up spec before code.* (G7)

## Decisions (resolved)

1. **Core ships a real `database` channel** (folded into workstream #1). It persists the notification, optionally creates a delivery row, and returns success if persistence succeeded. External channels stay as packages. This makes the existing `['database']` default coherent and lets #1 complete without #5 — *the load-bearing decision.*
2. **Normalization = trim + non-empty + dedupe only.** No lowercasing in a post-1.0 framework; custom channel names are preserved exactly. Canonical lower-case slugs, if ever wanted, arrive later behind a compatibility layer.
3. **`RichNotificationChannel` is a separate opt-in interface** with `sendNotification(): NotificationResult`. `NotificationChannel::send(): bool` stays untouched; the dispatcher probes `instanceof RichNotificationChannel`, otherwise calls the legacy bool method and adapts it.
4. **Broad `NotificationStoreInterface` mirroring current repository behavior** — a persistence seam, not a domain redesign. `DatabaseNotificationStore` wraps the current repo unchanged; `NullNotificationStore` implements explicit no-DB behavior. Idempotency (`findRecentByIdempotencyKey`) is documented as **unavailable** with the null store — duplicates become possible.
5. **Container-first resolution at the direct sites** (`NotificationRetryTask:63`, `DispatchNotificationChannels:98`, `ProcessRetriesCommand:135`): try the container for `NotificationService`; the fallback builds via the *same* store/queue factories the provider uses — never hardcoded `new NotificationRepository()`.
6. **No-DB matrix:** sync `send()` allowed (no persistence/idempotency/delivery tracking); async channels fail clearly unless the queue payload can carry the full notification without a DB lookup; `scheduled` / `retry` / `dispatchStoredNotification` / preferences / read-unread throw `NotificationPersistenceDisabledException`; reads/counts return empty/zero.
7. **Registry naming:** ~~keep current methods now; longer-term add clearer `getRegisteredChannelNames()` / `getActiveChannelNames()` with the old methods kept as aliases.~~ **Superseded by Phase 5** (`docs/superpowers/specs/2026-06-06-notification-channel-registration-design.md`): the rename is now a clean break — `getAvailableChannels()` → `getRegisteredChannelNames()` (+ new `getActiveChannelNames()`), all framework call sites updated, **no aliases** (project is not live). (`getAvailableChannels()` = registered names, `:117`; `getActiveChannels()` = `isAvailable()`-filtered, `:127`.)

## Sequencing & non-goals

- Order: **1 → 2 → 3/4 (any time) → 5 (separate spec).** #1 and #4 are self-contained; #2 is the real "optional persistence" fix; #5 is biggest.
- Everything is additive/internal — **no breaking public-contract changes in Phases 1–4.** `send(): bool`, the queue fallback, and the `true` capability default all stay. (Phase 5 — channel registration — is a separate, *intentionally* breaking pass: the `ChannelManager` name-method rename and context-required jobs; see §7 and the Phase 5 spec.)
- **Non-goals:** changing `send()`'s signature, removing the queue fallback, changing the capability default, per-package registration work (that's #5's spec), external provider/channel packages. (The core `database` channel in #1 is explicitly in scope.)

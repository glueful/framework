# Notification Channel Auto-Registration — Design Note

**Status:** Draft for review (no code yet) · **Scope:** the registration path for notification channels + `NotificationExtension` hooks across core and extension packages. This is Phase 5 (the §5 follow-up) of `2026-06-06-notification-subsystem-refinement-design.md`.

## Problem

There are **two parallel registries** and an **ad-hoc wiring path**:

1. **Channels** live in `ChannelManager` (`registerChannel(NotificationChannel)`), a shared container singleton. Core registers `DatabaseChannel` in `NotificationsProvider` (Phase 1).
2. **`NotificationExtension`** hooks (`beforeSend`/`afterSend`, supported types) live on `NotificationDispatcher` (`registerExtension()`), a separate registry.
3. **Extension channels are wired manually inside jobs/commands.** Several consumers build their **own** `new ChannelManager()` / `NotificationDispatcher` and hand-call `$provider->register($channelManager)` + `$dispatcher->registerExtension($provider)` (initializing `EmailNotificationProvider` by class-string):
   - `Queue/Jobs/DispatchNotificationChannels` — always hand-builds.
   - `Console/Commands/Notifications/ProcessRetriesCommand` — always hand-builds.
   - `Queue/Jobs/SendNotification` (`getNotificationService()`, ~`:276`) — prefers the container dispatcher, but **falls back** to the manual build + provider `register()`/`registerExtension()` (`:315–342`).
   - `Tasks/NotificationRetryTask` — mostly fixed (Phase 2 prefers the container service); only its **no-context fallback** still hand-builds.

   Because the hand-built managers don't use the container's shared `ChannelManager`, boot-time registrations (incl. core `DatabaseChannel`) are invisible to them — the Phase-2 "hand-built ChannelManager lacks the database channel" gap.

Consequences: a channel package can't "just install and work" — it needs job-level glue; the shared singleton and the hand-built managers diverge; and there's no single source of truth for which channels exist.

What's already available to build on: the container supports **tagging** (`BaseServiceProvider::tag()` + `TaggedIteratorDefinition::getTagged()`, used for `console.commands`, `cache.pool`); extensions have a real **`boot(ApplicationContext)`** lifecycle (`ExtensionManager` → `$provider->boot()`); `ChannelManager` is already a shared singleton.

## Options considered

**A. Tagged-iterator channel collection (container-native).** Tag channel services `notification.channel`; the `ChannelManager` factory collects all tagged channels via a `TaggedIteratorDefinition`. Fully declarative, channels known at build time. *But* the extension `ServiceProvider` base does not expose `tag()`, and cross-extension compile-time tag collection is the most plumbing; extensions register via `services()` which would need a tagging affordance.

**B. `boot()`-time registration into the shared `ChannelManager` (lifecycle-native).** Each extension's `boot()` resolves the shared `ChannelManager` and calls `registerChannel(...)` (and `registerExtension(...)` for hooks). Requires that **all consumers use the shared container `ChannelManager`/dispatcher** (fix the jobs/commands). Minimal new plumbing; uses the lifecycle extensions already have; runtime registration happens at boot, before requests.

**C. Hybrid: Option B + a helper on the extension `ServiceProvider` base.** Same as B, but add `registerNotificationChannel(NotificationChannel)` / `registerNotificationExtension(NotificationExtension)` helpers so extensions don't reach into the container by hand.

## Recommendation — **C (boot-time via a provider helper), with the jobs fixed to use the shared manager**

Rationale: it leans on the extension `boot()` lifecycle that already exists, needs no new compile-time tag machinery for extensions, and — critically — its prerequisite (everyone uses the shared `ChannelManager`) **also closes the deferred Phase-2 gap**. Tagged iteration (A) is a reasonable *future* enhancement for core channels but isn't needed to unify the path.

Shape:
- **One registration path.** An extension registers its channel(s) and any `NotificationExtension` hook in its `boot()` via helpers on the extension `ServiceProvider` base — no job-level glue. Core's `DatabaseChannel` continues to register in `NotificationsProvider` (already shared).
- **Kill the parallel job-glue in *all* fallback consumers, and require context.** `DispatchNotificationChannels`, `ProcessRetriesCommand`, `SendNotification` (the `getNotificationService()` fallback), and `NotificationRetryTask`'s no-context fallback resolve the **container** `NotificationDispatcher`/`ChannelManager` (which already carry core + extension registrations). The ad-hoc `new ChannelManager()` + manual `$provider->register()`/`registerExtension()` paths are **removed entirely** — not replaced with another hand-built manager. Notification dispatch **requires** an `ApplicationContext`; if none is available, throw a clear runtime exception rather than building ad-hoc managers.
- **`NotificationExtension` stays** as the before/after-hook contract, but is registered through the lifecycle, not hand-wired in jobs. No second discovery system (§G7).
- **Registry naming (§7) — rename, no aliases.** Rename `getAvailableChannels()` → `getRegisteredChannelNames()` and the active-channel name access → `getActiveChannelNames()`, and update all framework call sites. **Do not** keep aliases unless an external-package migration specifically requires a temporary one (the project is not live, so a clean rename is preferred over alias cruft).

## Implementation notes (for the plan)

- **Duplicate-registration policy** (`ChannelManager::registerChannel($channel)`):
  - name not registered → register;
  - same name, **same concrete class** → no-op (idempotent across repeated boots/test reboots);
  - same name, **different class** → throw `ChannelAlreadyRegisteredException` (a real package conflict — e.g. two packages both claiming `email` — must not be silently shadowed).
  - Add `replaceChannel($channel)` that always overwrites by name (tests/advanced overrides).
  - New exception: `src/Notifications/Exceptions/ChannelAlreadyRegisteredException.php`.
- Add to `src/Extensions/ServiceProvider.php`: `protected function registerNotificationChannel(NotificationChannel $channel): void` and `protected function registerNotificationExtension(NotificationExtension $ext): void`, each resolving the shared service from `$this->app`. **Document that `registerNotificationChannel()` throws `ChannelAlreadyRegisteredException` on a name conflict with a different class** — so package authors get a clear failure instead of silently shadowing each other.
- **Fix all fallback consumers** to resolve `app($context, NotificationDispatcher::class)` / its shared `ChannelManager`, **deleting** the inline `new ChannelManager()` + manual `$emailProvider->register()/registerExtension()` blocks entirely: `DispatchNotificationChannels`, `ProcessRetriesCommand`, `SendNotification::getNotificationService()` fallback, and `NotificationRetryTask`'s no-context fallback. **Require `ApplicationContext`:** when a consumer has no context, throw a clear runtime exception (e.g. `RuntimeException`/a dedicated `NotificationContextRequiredException`) instead of hand-building managers.
- **Rename** `ChannelManager::getAvailableChannels()` → `getRegisteredChannelNames()` and the active-name access → `getActiveChannelNames()`, updating every framework call site. No back-compat aliases (add one only if an external package migration forces it). Audit usages first (`getAvailableChannels`/`getActiveChannels` in `NotificationService`, `NotificationDispatcher`, etc.).
- Tests: an extension-style provider whose `boot()` registers a channel makes it dispatchable through the container dispatcher; the fallback consumers see core + registered channels; re-registering the same class is a no-op while a different class on the same name throws; `replaceChannel()` overwrites; the renamed `getRegisteredChannelNames()`/`getActiveChannelNames()` return the expected registered/active names, and no old `getAvailableChannels()`/`getActiveChannels()` call sites remain.

## Decisions (resolved)

1. **Duplicate-registration policy:** idempotent on same-class, throw on conflict — name absent → register; same concrete class → no-op; different class on the same name → throw `ChannelAlreadyRegisteredException`. Plus an explicit `replaceChannel()` for tests/overrides. (Not blind skip — real package conflicts must surface.)
2. **Helper (C), not tagged-iterator (A), now.** Extensions already have `boot()`; the immediate bug is consumer divergence from the shared `ChannelManager`, which C fixes with minimal plumbing. Tags remain a possible *later* internal/declarative optimization (core channels could be tagged without committing extensions to it).
3. **`services()` does not gain a tagging affordance** this phase — don't expand the public extension surface for a future design. The extension story stays: register channels/hooks in `boot()` via the helpers.
4. **Registry rename is a clean break — no aliases.** `getAvailableChannels()`/`getActiveChannels()` are renamed and all framework call sites updated; the project is not live, so aliases are avoided (one may be added only if an external package migration forces it). This is a deliberate break, unlike the additive Phases 1–4.
5. **Notification dispatch requires `ApplicationContext`.** The no-context "fallback" paths are removed, not re-implemented: a consumer without a context throws a clear runtime exception instead of building ad-hoc managers.

## Non-goals

- Migrating specific extension packages (email-notification, Conversa, Notiva, …) to the new path — that happens in each package after this lands.
- Migrating channels to `RichNotificationChannel` (Phase 4 already shipped the contract; adoption is per-package).
- A new extension *discovery* system — registration rides the existing `ServiceProvider`/`ExtensionManager` lifecycle.
- Changing `NotificationChannel`/`NotificationExtension` method signatures.

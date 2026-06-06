# Notification Channel Auto-Registration — Implementation Plan (Phase 5)

**Status: ✅ implemented** (Tasks 5a–5d). Verified: full suite green, `composer analyse` (src) clean, phpcs clean.

> **For agentic workers:** implement task-by-task with TDD (failing test first). Each task is independently green and reviewable; don't start a task before the previous is green. Design: `docs/superpowers/specs/2026-06-06-notification-channel-registration-design.md`.

**Goal:** One registration path for notification channels (and `NotificationExtension` hooks) through the existing `ServiceProvider`/`ExtensionManager` `boot()` lifecycle into the shared container `ChannelManager`/dispatcher — removing the parallel job-glue and the hand-built managers.

**Compatibility:** Unlike additive Phases 1–4, this phase **deliberately breaks** two things (project is not live): the `ChannelManager` channel-name methods are renamed (no aliases), and notification jobs/commands now **require** an `ApplicationContext` (no ad-hoc fallback). Tech: PHP 8.3, PHPUnit 10.5; library tests extend `PHPUnit\Framework\TestCase`.

---

## Task 5a — `ChannelManager` duplicate policy + rename (do together)

**Create**
- `src/Notifications/Exceptions/ChannelAlreadyRegisteredException.php` — thrown on a name conflict with a *different* class.
- `tests/Unit/Notifications/ChannelManagerRegistrationTest.php`

**Modify**
- `src/Notifications/Services/ChannelManager.php`:
  - `registerChannel($channel)`: name absent → register; same name + **same concrete class** → no-op (idempotent); same name + **different class** → throw `ChannelAlreadyRegisteredException`.
  - Add `replaceChannel(NotificationChannel $channel): self` — always overwrite by name.
  - Rename `getAvailableChannels(): array` → `getRegisteredChannelNames(): array` (registered names).
  - Add `getActiveChannelNames(): array` = `array_keys(getActiveChannels())`. Keep `getActiveChannels()` (returns channel objects).
- Call sites of the renamed method (only two — `TemplateManager:162` is its *own* unrelated method, leave it):
  - `src/Notifications/Services/NotificationService.php:942` — `…getChannelManager()->getAvailableChannels()` → `getRegisteredChannelNames()`.
  - `src/Notifications/Services/NotificationDispatcher.php:347` — `$this->channelManager->getAvailableChannels()` → `getRegisteredChannelNames()`.

**Behavior:** registration is idempotent for the same class (safe across repeated boots/test reboots) but loudly rejects a different class claiming a registered name; `replaceChannel()` is the explicit override; the registry exposes clear `getRegisteredChannelNames()` / `getActiveChannelNames()`.

**Tests**
- Registering a new channel makes `hasChannel()`/`getRegisteredChannelNames()` reflect it.
- Re-registering the *same instance/class* under the same name is a no-op (no throw, single entry).
- A *different* class on an existing name throws `ChannelAlreadyRegisteredException`.
- `replaceChannel()` overwrites the existing channel for a name.
- `getActiveChannelNames()` returns only available channels' names (`isAvailable()`); `getRegisteredChannelNames()` returns all.

**Rollback risk:** Medium — breaking rename. Mitigated by the tiny call-site surface (2). Revert = restore the method name + the throw-always `registerChannel`.

---

## Task 5b — Extension `ServiceProvider` notification helpers

**Modify**
- `src/Extensions/ServiceProvider.php` — add:
  - `protected function registerNotificationChannel(NotificationChannel $channel): void` — resolves the shared `ChannelManager` from `$this->app` and calls `registerChannel($channel)`. Document that it throws `ChannelAlreadyRegisteredException` on a different-class name conflict.
  - `protected function registerNotificationExtension(NotificationExtension $ext): void` — resolves the shared `NotificationDispatcher` and calls `registerExtension($ext)`.

**Create**
- `tests/Unit/Extensions/NotificationRegistrationHelpersTest.php`

**Behavior:** an extension registers its channel(s)/hook(s) in `boot()` via these helpers — no reaching into the container by hand, no job-level glue. Both target the shared singletons, so registrations are visible everywhere that uses the container services.

**Tests** (build a tiny anonymous/stub `ServiceProvider` subclass over a real container holding the shared `ChannelManager`/`NotificationDispatcher`):
- `registerNotificationChannel()` makes the channel resolvable via the shared `ChannelManager`.
- `registerNotificationExtension()` registers the hook on the shared dispatcher (`getExtension()` finds it).
- A different-class name conflict surfaces as `ChannelAlreadyRegisteredException` through the helper.

**Rollback risk:** Low — additive helpers. Revert = remove the two methods.

---

## Task 5c — Rewire all fallback consumers; require context

**Create**
- `src/Notifications/Exceptions/NotificationContextRequiredException.php`

**Modify** (delete the ad-hoc `new ChannelManager()` + `new NotificationDispatcher()` + manual `EmailNotificationProvider` `register()`/`registerExtension()` blocks; resolve the container `NotificationDispatcher`/service instead):
- `src/Queue/Jobs/DispatchNotificationChannels.php`
- `src/Console/Commands/Notifications/ProcessRetriesCommand.php`
- `src/Queue/Jobs/SendNotification.php` (`getNotificationService()` fallback, `:~276`, `:315–342`)
- `src/Tasks/NotificationRetryTask.php` (the no-context fallback branch)

**Require context:** create `src/Notifications/Exceptions/NotificationContextRequiredException.php` (a dedicated type, for catchability). When any of the four consumers has no `ApplicationContext`, it throws `NotificationContextRequiredException` instead of building managers — no ad-hoc fallback.

**Behavior:** every consumer dispatches through the shared, container-wired `ChannelManager`/dispatcher, so it sees core (`DatabaseChannel`) **and** all extension-registered channels/hooks. No path silently builds a manager missing those registrations. No-context is a hard, legible error.

**Tests**
- Each rewired consumer, given a context whose container has the shared dispatcher, uses it (assert the shared `ChannelManager` instance is the one dispatched through — e.g. a channel registered on the shared manager is visible to the consumer).
- A consumer constructed without a context throws `NotificationContextRequiredException` (no ad-hoc manager built). **Unit-covered for `DispatchNotificationChannels` + `SendNotification`** (reflection-invoke the private resolver on a context-less instance — `NotificationJobsContextTest`) **and `NotificationRetryTask`** (`NotificationRetryTaskContextTest`). **`ProcessRetriesCommand` is intentionally not tested for this (unit *or* integration):** `BaseCommand::getContext()` always returns a context (`buildDefaultContext()` fallback), so it has no clean "no context" path — its `NotificationContextRequiredException` throw is the *container-missing-service* branch, a defensive guard that only fires on a partial container (the real container always binds `NotificationService`). Triggering it would require fabricating a broken container, which adds no real confidence; left explicitly unasserted.

**Rollback risk:** Medium — removes fallbacks. Verify no production path constructs these without a context (the queue jobs/commands carry context; `NotificationRetryJob` passes it). Revert = restore the fallback branches.

---

## Task 5d — Integration coverage

**Create**
- `tests/Integration/Notifications/ExtensionChannelRegistrationTest.php`

**Behavior proven:**
- A stub extension `ServiceProvider` with a real overridden `boot(ApplicationContext)` that calls `registerNotificationChannel(...)` makes that channel dispatchable through the **container-wired** `NotificationDispatcher`, with the core `database` channel present alongside it. *Coverage boundary:* `boot()` is invoked **directly** in the test; the `ExtensionManager` auto-discovery that calls `boot()` during app boot is the extension system's concern (its own tests), not re-proven here.
- `DispatchNotificationChannels` (the one consumer constructible with an explicit context) is shown to resolve the **same** shared `NotificationService` the container holds — not a local build.

**Coverage boundaries (not asserted here):**
- **`ProcessRetriesCommand`** — its positive path uses the same container-resolution pattern, but it manages its own context via `BaseCommand::getContext()`/`buildDefaultContext()`, so it can't be pointed at the test's booted container without the console kernel; and its `NotificationContextRequiredException` branch is a **defensive guard** that only fires on a partial container (the real container always binds `NotificationService`). Left intentionally unasserted rather than faked.
- `SendNotification` / `NotificationRetryTask` no-context throws are unit-covered (Task 5c); their shared-resolution positive path mirrors `DispatchNotificationChannels`.

**Rollback risk:** Low — test-only.

---

## Cross-cutting

- **Verification gate per task:** `composer test` (targeted green), `composer run analyse` (no new PHPStan errors), `composer run phpcs`.
- **Audit before the rename:** confirm only `NotificationService:942` + `NotificationDispatcher:347` call `ChannelManager::getAvailableChannels()` (TemplateManager's same-named method is unrelated). No `getActiveChannels()` callers exist today.
- **Docs/CHANGELOG:** CHANGELOG `[Unreleased]` entry (note the rename + context-required as the two breaks); a short "registering a channel from an extension" note (the `boot()` + helper pattern). Sync the main refinement plan's Phase 5 from placeholder → implemented when done.
- **Watch item carried from Phase 4:** `NotificationResult::$retryable`/`$metadata` consumption by retry policy/delivery recording remains deferred — out of scope here.

## Self-review (completed during planning)

- **Spec coverage:** duplicate policy + `replaceChannel` (5a) ✓; helpers (5b) ✓; all four consumers + context-required (5c) ✓; rename no-aliases (5a) ✓; integration proof (5d) ✓.
- **No placeholders:** every task names exact files and concrete test cases.
- **Type consistency:** `getRegisteredChannelNames(): array`, `getActiveChannelNames(): array`, `replaceChannel(NotificationChannel): self`, `registerNotificationChannel(NotificationChannel): void`, `registerNotificationExtension(NotificationExtension): void` are used consistently across tasks.

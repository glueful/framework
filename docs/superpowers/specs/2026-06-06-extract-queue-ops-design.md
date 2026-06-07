# Extract Queue Ops (autoscaling / supervision / monitoring) → `glueful/queue-ops` — Design Note

**Status:** Draft v3 — v2 review folded in, plus consistency pass (`--connection` in WS2/upgrade notes; removed commands are *absent* w/ generic command-not-found, no actionable-stub; lifecycle wording corrected to "zero active workers on plain core"; `\Throwable`→`\Exception` wrapping noted); no code yet · **Scope:** `src/Queue/Process/**`, `src/Queue/Monitoring/**`, `src/Console/Commands/Queue/{WorkCommand,AutoScaleCommand}.php`, `src/Queue/ServiceProvider/QueueProvider.php`, `src/Queue/Jobs/QueueMaintenance.php`, `src/Controllers/HealthController.php` (queue-health seam), `config/queue.php` (the `workers.*` operational block). The queue *primitive* — `QueueManager`, the queue/driver contracts, `DatabaseQueue`/`RedisQueue`, `Job`, `WorkerOptions`, `FailedJobProvider` — stays in core. Channel/driver packages are out of scope except as the contract is reused.

## Problem

Per the agreed boundary policy: **core = primitives, stable contracts, zero-infra reference implementations, and capabilities core itself depends on.** Operational product surface — fleet management, autoscaling, process supervision, worker monitoring — belongs in an extension. Today the queue subsystem violates this in one load-bearing way:

**There is no lean worker loop. `queue:work` *is* the supervised process manager.** `WorkCommand` imports and holds `ProcessManager`, `ProcessFactory`, and `WorkerMonitor` as properties (`src/Console/Commands/Queue/WorkCommand.php:7-11,35-37`) and instantiates `ProcessFactory`/`ProcessManager` in its run path (`:195-203`). The default `work` action spawns a multi-process fleet under a distributed lock (`:206-246`, `spawnWorkersWithLock` `:648-672`); the only place an actual pop→handle→retry loop exists is the `process` sub-action (`executeProcess` `:254-355`) — which is itself an internal leaf-worker contract that `ProcessFactory::buildWorkerCommand` shells out to via `glueful queue:work process …` (`src/Queue/Process/ProcessFactory.php:104-149`). So you cannot move supervision out without first giving core a worker it can run on its own.

That single fact makes this **the highest design-prerequisite extraction in the boundary program**: it is a *build-then-extract*, not a *lift-then-move*. Everything else (the `Process/*` and `Monitoring/*` trees, `AutoScaleCommand`) is cleanly contained and would lift easily — but only after core has a worker.

### What's coupled (verified — the surface is small and contained)

Every reference to the supervision/monitoring classes outside their own directories:

- `WorkCommand` — holds `ProcessManager`/`WorkerMonitor`, news up `ProcessFactory`/`ProcessManager` (`:7-11,35-37,195-203`). **The long pole.**
- `AutoScaleCommand` — imports the entire `Process/*` tree + `WorkerMonitor` (`src/Console/Commands/Queue/AutoScaleCommand.php:7-14`), news them all up in `initializeServices()` (`:195-240`). Pure ops command; lifts wholesale.
- `QueueProvider` — registers `WorkerMonitor` in DI via `autowire()` (`src/Queue/ServiceProvider/QueueProvider.php:28-29`). Only the *concrete* `WorkerMonitor` binding; nothing else from `Process/*` is in core DI.
- `QueueMaintenance` (`src/Queue/Jobs/QueueMaintenance.php:7,31-32,48`) — news up `WorkerMonitor` and calls `cleanupOldWorkers()` (`:95`), `cleanupOldMetrics()` (`:114`), `getActiveWorkers()` (`:248`).
- `HealthController::getQueueHealth()` (`src/Controllers/HealthController.php:723,729`) — resolves `WorkerMonitor` from the container and calls `getActiveWorkers()`. Backs `GET /health/queue` (`routes/health.php:222-228`, controller `:769-776`).
- `ProcessManager` (`src/Queue/Process/ProcessManager.php:8,17`) — depends on `WorkerMonitor` (constructor `:25-30`). Internal to the `Process/*` tree; moves with it.

**Surprises worth flagging (all confirming the extraction is clean):**

1. **`QueueManager` does NOT depend on `WorkerMonitor` or any `Process/*` class.** Grepped `src/Queue/QueueManager.php` — zero monitoring references. The primitive is already decoupled from the ops layer. Job-execution metrics (`recordJobStart/Success/Failure`) on `WorkerMonitor` are **dead in core**: nothing in `src/` calls them (only the moved `process` loop emits text monitoring lines to stdout, `WorkCommand.php:308-313`).
2. **No core path other than `queue:work` (+`AutoScaleCommand`) touches `ProcessManager`/`ProcessFactory`.** Confirmed by full-tree grep. `ServeCommand` shells out to `queue:work` as a string (`src/Console/Commands/ServeCommand.php:373-378`) — it depends on the *command name*, not the classes, so it's a behavioral coupling, not a code one (see Risks R4).
3. **The whole `Process/*` tree only depends on core primitives** that stay: `WorkerOptions`, `QueueManager`, `WorkerMonitor`, plus `Symfony\Process`, `Psr\Log`, and `Cron\CronExpression` (ScheduledScaler `:8`). So once `WorkerMonitorInterface` lands in core, the tree relocates with no inbound core edges left.
4. **Commands self-deregister on move.** `ConsoleProvider` auto-discovers commands by scanning `src/Console/Commands/**` for `#[AsCommand]` (`src/Container/Providers/ConsoleProvider.php:79-115`). Deleting `WorkCommand`/`AutoScaleCommand` from that tree removes them from core automatically; the extension re-registers via its own `discoverCommands()`/`commands()`.
5. **`WorkerMonitor` self-provisions its tables.** It lazily `CREATE TABLE`s `queue_workers` / `queue_job_metrics` on first write (`src/Queue/Monitoring/WorkerMonitor.php:76-77,515-555,562-596`); there are **no migrations** for these tables anywhere in the repo (grep returned nothing). So the schema travels *inside the moved class* — no migration extraction needed, though the extension should ship proper migrations (see Workstream 5).

## Guardrails

- **G1 — Plain workers keep working with core only.** After extraction, `composer require glueful/framework` alone must still run a worker: `php glueful queue:work` (lean, single-process) drains the queue with retry/backoff. No `queue-ops` needed for the zero-infra path.
- **G2 — Core depends on no ops class.** `HealthController`, `QueueMaintenance`, and DI must depend on a core **interface** (`WorkerMonitorInterface`), never `Glueful\Queue\Monitoring\WorkerMonitor`. Core ships a no-op default binding so health/maintenance degrade gracefully when `queue-ops` is absent.
- **G3 — Sequence is fixed: build the seams BEFORE the move.** `QueueWorker` + `WorkerMonitorInterface` land and are wired in core *first*; only then do `Process/*`, `Monitoring/*`, and the ops commands relocate. Reversing the order leaves core uncompilable.
- **G4 — No silent capability loss (but no core stub either).** A moved capability is never a silent success/no-op: the supervised `queue:work` sub-actions and `queue:autoscale` are simply **gone**, so invoking them produces a standard command-not-found / unknown-argument error — visible, not silent. Per the no-shims decision (G6), core ships **no** stub command that prints an actionable "install queue-ops" message — that would be a back-compat shim. The discoverability burden lives in the upgrade notes, not a runtime stub.
- **G5 — The leaf-worker IPC contract is internal and goes WITH supervision.** `ProcessFactory` shelling to `queue:work process --with-monitoring --emit-heartbeat` (`ProcessFactory.php:104-149`; consumed by `WorkCommand::executeProcess` `:254-355`) is a *private protocol between the supervisor and its children*. It must not become the lean core worker; it relocates intact into the extension so the supervisor still spawns its own leaf workers.
- **G6 — Intentional clean break.** The framework is past 1.0; we've chosen *no backward compatibility* for these extractions, so this is a deliberate refinement/next-major break — not "pre-1.0." No back-compat shims for the removed `queue:work` sub-actions (`spawn/scale/status/stop/restart/health`) or for `queue:autoscale`. Provide upgrade notes, not aliases.
- **G7 — `WorkerOptions` stays in core, with one intentional change.** It remains the shared value object for the lean worker, the supervisor, and the autoscaler (no fork). But its constructor currently clamps `maxJobs = max(1, …)` and `maxRuntime = max(60, …)` (`WorkerOptions.php:96,103`), which **cannot express the existing `0 = unlimited` CLI contract** (`WorkCommand.php:133,258-259,332,337`). Relax those two to `max(0, …)` so `0` means unlimited, matching `queue:work`'s long-standing semantics. All other clamps (`sleep>=1`, `memory>=32`, etc.) stay. This is the *only* sanctioned `WorkerOptions` change (see Decisions §8); it's additive to its accepted range, so the supervisor/autoscaler are unaffected.

## New core seams

These are the prerequisite primitives. Both land in core **before** anything moves.

### 1. `Glueful\Queue\QueueWorker` (new, core)

A lean pop → handle → retry/backoff loop over `QueueManager`. **No dependency on `Process/*` or `Monitoring/*`.** It is essentially `WorkCommand::executeProcess` (`:254-355`) lifted into a reusable, testable class with the stdout IPC stripped out (that IPC belongs to the supervisor, per G5).

```php
namespace Glueful\Queue;

final class QueueWorker
{
    public function __construct(
        private QueueManager $manager,
        private WorkerMonitorInterface $monitor,   // no-op by default (G2)
        private \Psr\Log\LoggerInterface $logger,
    ) {}

    /**
     * Daemon loop: pop → fire → retry/backoff, until a stop condition.
     * Honors WorkerOptions (sleep, memory, timeout, maxJobs, maxRuntime,
     * maxAttempts, stopWhenEmpty) and SIGTERM/SIGINT graceful shutdown.
     *
     * @param array<int,string> $queues
     */
    public function daemon(string $connection, array $queues, WorkerOptions $options): int;

    /** Process at most one ready job across $queues; returns true if one ran. */
    public function runOnce(string $connection, array $queues, WorkerOptions $options): bool;
}
```

**Lean `queue:work` CLI contract.** The rewritten core command drops the supervisor sub-actions (`spawn/scale/status/...`) and exposes exactly the lean worker's inputs. Because `QueueWorker::daemon()`/`runOnce()` take `$connection`, the command **must expose connection selection** — today's `WorkCommand` only has an `action` argument + `--queue` and **no `--connection`** (`WorkCommand.php:44-57`), which would leave operators unable to target a non-default connection. Add:
- `--connection=` — defaults to `config('queue.default')`; selects the `QueueManager` connection.
- `--queue=` — comma-separated queue list (existing).
- `--once` — single job via `runOnce()` (replaces the old `process` one-shot semantics).
- `--max-jobs=0`, `--max-runtime=0` — `0 = unlimited` (preserved via the relaxed `WorkerOptions`, G7).
- `--memory=`, `--sleep=`, `--tries=`, `--stop-when-empty` — map to `WorkerOptions`.

(Either a `--connection` option or a positional connection argument is fine; pick one in the plan. The point: connection targeting must not silently regress.)

Behavior (ported from the verified `executeProcess` loop):

- Resolve the driver via `QueueManager::connection($connection)` and `pop($queue)` per queue (`WorkCommand.php:266-267,297`).
- **Before processing each popped job, call `$this->monitor->recordJobStart($job)`.** This is mandatory ordering, not optional: `recordJobSuccess()`/`recordJobFailure()` **update an existing metrics row by `job_uuid`** (`WorkerMonitor.php:209-218,236-245` — `->where('job_uuid', $job->getUuid())->update(...)`), so without a prior `recordJobStart()` to insert the row, the success/failure update touches zero rows and metrics silently vanish. (No-op under `NullWorkerMonitor`, so a plain core install is unaffected.)
- On success: `$job->fire()`, increment processed count, `$this->monitor->recordJobSuccess($job, $secs)`.
- On throw: catch `\Throwable`. If `attempts < min(job max, options max)` → `$job->release($backoff)`, else `$job->failed($e)` (`:314-330`); `$this->monitor->recordJobFailure($job, $e, $secs)`. **Wrap non-`Exception` throwables:** both `JobInterface::failed(\Exception)` (`JobInterface.php:91`) and `WorkerMonitorInterface::recordJobFailure(\Exception)` accept `\Exception`, but the loop catches `\Throwable`, so pass `$e instanceof \Exception ? $e : new \RuntimeException($e->getMessage(), 0, $e)` — exactly as `executeProcess` does today (`WorkCommand.php:318`). Keep that wrapping consistent across both calls.
- Backoff derives from `config('queue.workers.performance.backoff_*')` (`config/queue.php:345-351`) — *new* wiring; the old loop used a flat `--sleep`. Keep flat-sleep fallback.
- Stop conditions: `maxJobs`, `maxRuntime`, `stopWhenEmpty` (`:332-343`); signal handling via `pcntl_*` (`:275-282,349-351`).
- All `recordJob*` calls go through `WorkerMonitorInterface`, so a plain core install records nothing and a `queue-ops` install gets full metrics — **this is what finally makes the dead monitoring hooks live** (surprise #1). The `start → success|failure` pairing is what makes those rows coherent.

DI: registered in `QueueProvider` as `autowire(QueueWorker::class)`.

### 2. `Glueful\Queue\Contracts\WorkerMonitorInterface` (new, core)

The seam that lets `HealthController`, `QueueMaintenance`, and `QueueWorker` depend on monitoring without depending on the concrete (ops-owned) implementation. The method set is **exactly what core needs** — live core call sites plus the lifecycle/recording hooks `QueueWorker` drives — and **no more**. Rich ops *reporting* methods are deliberately **excluded**:

| Method | Required by (verified call site) |
| --- | --- |
| `getActiveWorkers(): array` | `HealthController.php:729`; `QueueMaintenance.php:248` |
| `cleanupOldWorkers(int $days = 7): bool` | `QueueMaintenance.php:95` |
| `cleanupOldMetrics(int $days = 30): bool` | `QueueMaintenance.php:114` |
| `recordJobStart(JobInterface $job): void` | `QueueWorker` per-job hook (seeds the metrics row) |
| `recordJobSuccess(JobInterface $job, float $secs): void` | `QueueWorker` per-job hook |
| `recordJobFailure(JobInterface $job, \Exception $e, float $secs): void` | `QueueWorker` per-job hook |
| `registerWorker(string $uuid, array $data): void` | `QueueWorker` self-registration (so `getActiveWorkers()` is meaningful) |
| `updateWorkerHeartbeat(string $uuid, array $data): void` | `QueueWorker` heartbeat |
| `unregisterWorker(string $uuid, array $final = []): void` | `QueueWorker` shutdown |
| `isEnabled(): bool` | gate checks |

**Excluded from the interface — ops reporting only, stay on the concrete `WorkerMonitor`:** `getWorkerStats()`, `getJobMetrics()`, `getPerformanceStats()`. Their only callers are the ops `status`/reporting commands, which **move to `queue-ops` and type-hint the concrete `WorkerMonitor` directly** — so these never need to be in core's contract. Keeping them out is the difference between a lean core seam and re-importing the whole reporting product through the interface.

> Lifecycle methods (`registerWorker`/heartbeat/`unregisterWorker`) are **in** the interface so that the lean `QueueWorker` can register/heartbeat/unregister through it — which lets `queue-ops` *observe* lean workers (via `getActiveWorkers()`) **when it's installed**. On a plain core install these calls hit `NullWorkerMonitor` and no-op, so `GET /health/queue` and `QueueMaintenance` simply report **zero active workers** — correct and harmless. (Reads return empty/zero, writes no-op, in the null implementation.)

**Core ships `NullWorkerMonitor`** (`Glueful\Queue\Monitoring\NullWorkerMonitor`, new) — every method a no-op / empty-return. `QueueProvider` binds `WorkerMonitorInterface => NullWorkerMonitor` by default (replacing the current concrete autowire at `QueueProvider.php:28-29`). `queue-ops` overrides the binding to the real monitor.

This makes `getQueueHealth()` degrade cleanly: with core only, `workers.active` is `0` and the `no_active_workers_with_pending_jobs` signal (`HealthController.php:739-742`) still computes correctly against real queue stats — health is honest, not broken.

## Workstreams (priority order — sequencing is the whole point)

1. **[PREREQUISITE] Introduce `WorkerMonitorInterface` + `NullWorkerMonitor` in core; repoint consumers.**
   - Add the contract and null impl. Have the existing concrete `WorkerMonitor` `implements WorkerMonitorInterface` (no behavior change yet).
   - Repoint `HealthController.php:723` and `QueueMaintenance.php:48` to resolve/typehint the interface. `QueueMaintenance` currently `new WorkerMonitor(...)` directly (`:48`) — switch to container resolution of the interface (it already takes `?ApplicationContext`).
   - `QueueProvider`: bind `WorkerMonitorInterface => WorkerMonitor` *for now* (still concrete, but behind the interface). *Low risk; nothing moves yet; core stays fully functional.*

2. **[PREREQUISITE] Build the lean `QueueWorker` and rewrite `queue:work` onto it.**
   - Add `QueueWorker` (spec above), register in `QueueProvider`.
   - Rewrite `WorkCommand` to a **lean command**: `php glueful queue:work [--connection=] [--queue=] [--once] [--sleep=] [--memory=] [--timeout=] [--max-jobs=] [--max-runtime=] [--stop-when-empty]`. `--connection` defaults to `config('queue.default')` (Decision §9). Default = single-process daemon calling `QueueWorker::daemon()`; `--once` calls `runOnce()`. **Delete** the `work/process/spawn/scale/status/stop/restart/health` action dispatch (`WorkCommand.php:163-188`) and all `ProcessManager`/`ProcessFactory`/`WorkerMonitor` properties + `initializeServices()` (`:35-37,190-204`).
   - This is where the load-bearing risk lives (R1). Land it with full test coverage against the in-memory/SQLite driver *before* touching the `Process/*` tree. *Largest core change; gates everything after.*

3. **Default-bind `NullWorkerMonitor` in core.**
   - Flip `QueueProvider` to bind `WorkerMonitorInterface => NullWorkerMonitor`. Core no longer references the concrete `WorkerMonitor` anywhere. Verify `GET /health/queue` and `QueueMaintenance` still pass with the null monitor. *Core is now free of the ops layer; the move can proceed.*

4. **Relocate the ops tree to `glueful/queue-ops`.**
   - Move `src/Queue/Process/*` (7 classes), `src/Queue/Monitoring/WorkerMonitor.php`, and `AutoScaleCommand` into the extension (new namespace, e.g. `Glueful\Extensions\QueueOps\…`).
   - Move the supervised worker mode: the extension ships a **`queue:supervise`** command (the old `WorkCommand` `work/spawn/scale/status/stop/restart/health` actions + `spawnWorkersWithLock`) and the leaf-worker IPC loop (old `executeProcess`) as `queue:supervise process` — keeping `ProcessFactory::buildWorkerCommand`'s shell contract intact (G5). `LockManagerInterface` stays a core service the extension consumes.
   - Extension `ServiceProvider`: `services()` binds `WorkerMonitorInterface => WorkerMonitor` (override), registers `ProcessManager`/`ProcessFactory`/`AutoScaler`/`ScheduledScaler`/`ResourceMonitor`/`StreamingMonitor`; `boot()` `discoverCommands()` for `queue:supervise` + `queue:autoscale`.

5. **Own the ops schema + config in the extension.**
   - Ship real migrations for `queue_workers` / `queue_job_metrics` (currently auto-created inside `WorkerMonitor`, surprise #5) via `loadMigrationsFrom()`; keep the lazy-create as a fallback.
   - Move the operational `config/queue.php` blocks — `workers.process`, `workers.auto_scaling`, `workers.resource_limits`, `workers.resource_thresholds`, `workers.supervisor` (`config/queue.php:194-365`) — into an extension config (`config/queue_ops.php`) merged via `mergeConfig()`.
   - **Per-queue keys (`workers.queues.<name>.*`) split explicitly** (each queue entry today has `workers`, `max_workers`, `priority`, `memory_limit`, `timeout`, `max_jobs`, `auto_scale` — `config/queue.php`):
     - **Stay in core** (lean-worker per-queue defaults): `priority`, `memory_limit`, `timeout`, `max_jobs`. The lean `QueueWorker` reads these for a given queue.
     - **Move to `queue-ops`**: `workers` (static fleet size), `max_workers` (scale ceiling), `auto_scale`. These only mean anything to the supervisor/autoscaler.
   - **Keep in core `queue.php`:** `connections`, `default`, `monitoring.*` (alert-rule data the health layer reads), and `workers.performance.*` (backoff — now consumed by the lean `QueueWorker`). See Decisions §4 on the `monitoring` block.

## Decisions (resolved)

1. **Lean worker is core; supervision is the extension.** `queue:work` becomes single-process (`QueueWorker`). The fleet/supervisor/autoscaler become `queue:supervise` + `queue:autoscale` in `queue-ops`. This is the only split that satisfies G1 (plain worker on core alone) while honoring the boundary policy.
2. **The leaf-worker `process` protocol moves with the supervisor, not into core** (G5). The lean `QueueWorker` is a clean in-process loop with monitoring behind the interface; the stdout `[JOB_COMPLETED]`/`[HEARTBEAT]`/`[METRICS]` lines (`WorkCommand.php:308-313,287`) are a supervisor↔child IPC and stay private to `queue-ops`.
3. **`WorkerMonitorInterface` is the minimal core-needed subset, not the full concrete surface.** It covers live core call sites + the lifecycle/recording hooks `QueueWorker` drives, and **deliberately excludes** the ops-reporting methods `getWorkerStats()`/`getJobMetrics()`/`getPerformanceStats()` (their only callers are ops commands that move to `queue-ops` and type-hint the concrete `WorkerMonitor`). Core binds `NullWorkerMonitor`; ops binds the real one. This is the seam that decouples `HealthController` + `QueueMaintenance` (G2) without dragging the reporting product into core's contract.
4. **`monitoring.*` (alert rules) stays in core `queue.php`; it's data, not the engine.** The alert-rule definitions (`config/queue.php:110-173`) are read by the health/observability layer and carry no dependency on the ops classes. The *scaling/process/resource* config blocks move (Workstream 5). Borderline but deliberate: keep the declarative thresholds queryable on a plain install; move only the code-bearing knobs.
5. **Intentional clean break, no shims** (G6). Removed `queue:work` sub-actions and `queue:autoscale` are simply **absent** — invoking them yields a standard command-not-found / unknown-argument error, **not** an actionable core stub message (a stub would be a shim, violating this decision). The upgrade note carries the "install `queue-ops`" guidance. No deprecation aliases — a deliberate next-major/refinement break (framework is past 1.0; we've opted out of back-compat for these extractions), **not** "pre-1.0."
6. **`WorkerProcess` is internal to supervision and moves with it.** It wraps `Symfony\Process` and is only referenced by `ProcessFactory`/`ProcessManager` (both moving). Not a core seam.
7. **`QueueMaintenance` stays in core but loses hard coupling.** It keeps cleaning failed jobs (`FailedJobProvider` stays core) and optimizing connections; **it must stop `new WorkerMonitor(...)`-ing directly** (`QueueMaintenance.php:48`) and instead resolve `WorkerMonitorInterface` from the container — so its worker/metric cleanup no-ops cleanly on a plain install and runs for real under `queue-ops`.
8. **One sanctioned `WorkerOptions` change: `0 = unlimited`.** Relax `maxJobs`/`maxRuntime` clamps from `max(1,…)`/`max(60,…)` to `max(0,…)` so the lean worker preserves `queue:work`'s existing `0 = unlimited` contract (`WorkCommand.php:133,258-259`). Every other clamp is unchanged; the VO is not forked. (G7)
9. **Lean `queue:work` exposes connection selection.** `QueueWorker::daemon()`/`runOnce()` take `$connection`, so the command adds `--connection` (default `config('queue.default')`) — today's command has none, and dropping it would silently prevent targeting non-default connections. A positional connection argument is an acceptable alternative; the plan picks one.

## Files: move vs stay-in-core

| Path | Disposition |
| --- | --- |
| `src/Queue/QueueManager.php` | **Stay** (primitive) |
| `src/Queue/WorkerOptions.php` | **Stay** (shared VO, G7) |
| `src/Queue/Drivers/DatabaseQueue.php` | **Stay** (required zero-infra reference) |
| `src/Queue/Drivers/RedisQueue.php` | **Stay** (optional second reference) |
| `src/Queue/Failed/FailedJobProvider.php` | **Stay** (core retry primitive) |
| `src/Queue/Jobs/QueueMaintenance.php` | **Stay**, repointed to `WorkerMonitorInterface` (WS1, WS7) |
| `src/Controllers/HealthController.php` (`getQueueHealth`/`queue`) | **Stay**, repointed to `WorkerMonitorInterface` (WS1) |
| `src/Queue/ServiceProvider/QueueProvider.php` | **Stay**, drops concrete `WorkerMonitor` bind, adds `QueueWorker` + null monitor (WS1–3) |
| `config/queue.php` — `connections`, `default`, `monitoring.*`, `workers.performance.*` | **Stay** (Decision §4) |
| **`src/Queue/Contracts/WorkerMonitorInterface.php`** | **New (core)** |
| **`src/Queue/Monitoring/NullWorkerMonitor.php`** | **New (core)** |
| **`src/Queue/QueueWorker.php`** | **New (core)** — the prerequisite |
| `src/Console/Commands/Queue/WorkCommand.php` | **Rewrite (core)** → lean `queue:work` on `QueueWorker`; supervised actions removed |
| `src/Queue/Monitoring/WorkerMonitor.php` | **Move** → `queue-ops` (binds `WorkerMonitorInterface`) |
| `src/Queue/Process/ProcessManager.php` | **Move** → `queue-ops` |
| `src/Queue/Process/ProcessFactory.php` | **Move** → `queue-ops` (leaf-worker IPC, G5) |
| `src/Queue/Process/WorkerProcess.php` | **Move** → `queue-ops` (Decision §6) |
| `src/Queue/Process/AutoScaler.php` | **Move** → `queue-ops` |
| `src/Queue/Process/ScheduledScaler.php` | **Move** → `queue-ops` |
| `src/Queue/Process/ResourceMonitor.php` | **Move** → `queue-ops` |
| `src/Queue/Process/StreamingMonitor.php` | **Move** → `queue-ops` |
| `src/Console/Commands/Queue/AutoScaleCommand.php` | **Move** → `queue-ops` (`queue:autoscale`) |
| supervised `work/spawn/scale/status/stop/restart/health` + `executeProcess` (from `WorkCommand`) | **Move** → `queue-ops` as `queue:supervise` |
| `config/queue.php` — `workers.{process,auto_scaling,resource_limits,resource_thresholds,supervisor}` + per-queue `workers`/`max_workers`/`auto_scale` | **Move** → `queue-ops` `config/queue_ops.php` (WS5). Per-queue `priority`/`memory_limit`/`timeout`/`max_jobs` **stay** in core. |
| `queue_workers` / `queue_job_metrics` tables (lazy-created in `WorkerMonitor`) | **Move** schema → `queue-ops` migrations (WS5) |

## Proposed extension layout (`glueful/queue-ops`)

```
glueful/queue-ops/
  composer.json            # canonical Glueful extension manifest: type glueful-extension,
                           # glueful/framework in require-dev, extra.glueful.provider =
                           # Glueful\Extensions\QueueOps\QueueOpsServiceProvider, classmap migrations/
  src/                     # PSR-4: Glueful\Extensions\QueueOps\
    QueueOpsServiceProvider.php       # services(): WorkerMonitorInterface bind + Process/* DI; boot(): discoverCommands
    Monitoring/WorkerMonitor.php      # implements core WorkerMonitorInterface
    Process/{ProcessManager,ProcessFactory,WorkerProcess,AutoScaler,ScheduledScaler,ResourceMonitor,StreamingMonitor}.php
    Console/{SuperviseCommand,AutoScaleCommand}.php   # queue:supervise, queue:autoscale
  config/queue_ops.php     # merged via mergeConfig('queue_ops', ...)
  migrations/              # queue_workers, queue_job_metrics
```

`services()` binds `WorkerMonitorInterface => WorkerMonitor` (overriding core's null default — last provider wins) and the `Process/*` graph. `boot()` calls `discoverCommands()` (the commands carry `#[AsCommand]`, so they slot into the existing tagged-iterator pipeline at `Console/Application.php:62-75`). `LockManagerInterface`, `QueueManager`, `WorkerOptions`, `LoggerInterface` are all consumed from core.

## Upgrade notes (for the eventual CHANGELOG / migration guide)

- **Plain workers are unchanged in spirit, leaner in fact.** `php glueful queue:work` still drains the default queue with retry/backoff — now single-process and dependency-free. Flags `--queue`, `--sleep`, `--memory`, `--timeout`, `--max-jobs`, `--max-runtime`, `--stop-when-empty` are retained; **new**: `--once` and `--connection` (defaults to `config('queue.default')`, so existing invocations are unaffected). `ServeCommand` keeps auto-starting `queue:work --sleep=3` (now the lean worker).
- **Removed from core (clean break, no aliases):** the `queue:work` sub-actions `work`(multi)/`spawn`/`scale`/`status`/`stop`/`restart`/`health`, and the entire `queue:autoscale` command. **`php glueful queue:work` with NO action now means "run one lean worker," not "spawn 2 supervised workers."**
- **Supervised fleets / autoscaling / worker metrics now require:** `composer require glueful/queue-ops`. It restores `queue:supervise` (multi-worker process management) and `queue:autoscale` (load/scheduled/resource scaling), binds the real `WorkerMonitor` (so `GET /health/queue` reports active workers and `QueueMaintenance` cleans worker/metric tables), and ships migrations for `queue_workers`/`queue_job_metrics`.
- **Config moved:** `queue.workers.{process,auto_scaling,resource_limits,resource_thresholds,supervisor}` and the per-queue **`workers`/`max_workers`/`auto_scale`** keys now live in `queue-ops` (`queue_ops.*`). The env vars that fed them are unchanged but read by the extension: `QUEUE_AUTO_SCALING`, `QUEUE_SCALE_*`, `QUEUE_*_WORKERS`/`*_MAX_WORKERS` (so `NOTIFICATIONS_QUEUE_WORKERS`/`*_MAX_WORKERS`, `EMAIL_QUEUE_WORKERS`, etc. — the fleet knobs — are read by `queue-ops`), and `QUEUE_PROCESS_*`. **Stay in core:** the per-queue **`priority`/`memory_limit`/`timeout`/`max_jobs`** keys (lean-worker defaults — e.g. `*_QUEUE_MEMORY`, `*_QUEUE_TIMEOUT`, `*_QUEUE_MAX_JOBS`), `queue.monitoring.*` (alert rules), and `queue.workers.performance.*` (backoff — now drives the lean worker).
- **Health endpoint stays up either way.** Without `queue-ops`, `GET /health/queue` reports queue stats with `workers.active = 0` (honest — no monitoring layer installed); with it, full worker telemetry returns.

## Risks

- **R1 — `QueueWorker` correctness is load-bearing (highest).** It replaces the *only* job-draining loop in the framework. A regression in retry/backoff/stop-condition/signal handling breaks every consumer's queue. Mitigation: build it in WS2 with exhaustive tests (success, throw→release, throw→failed at max attempts, `maxJobs`/`maxRuntime`/`stopWhenEmpty`, SIGTERM) against the DatabaseQueue/SQLite harness, *before* the move. Port semantics 1:1 from the verified `executeProcess` loop (`WorkCommand.php:284-352`).
- **R2 — Sequencing inversion makes core uncompilable.** If the `Process/*` tree moves before `WorkerMonitorInterface` is wired, `HealthController`/`QueueMaintenance`/`QueueProvider` reference a missing class. Mitigation: WS1→WS2→WS3 are hard gates before WS4 (G3); CI on a *plain checkout* (no extensions) after WS3.
- **R3 — Last-binding-wins ordering for `WorkerMonitorInterface`.** The extension must override core's `NullWorkerMonitor` binding. Mitigation: confirm extension providers register after core providers (they do — boot order), and add an integration test asserting the real monitor resolves when `queue-ops` is present.
- **R4 — `ServeCommand` behavioral coupling.** `ServeCommand` shells `queue:work --sleep=3` (`ServeCommand.php:373-378`); after the rewrite that's a single lean worker (previously it implicitly meant 2 supervised workers). Acceptable for a dev convenience server; note it in upgrade docs. No code change required.
- **R5 — Schema previously self-created; first install of `queue-ops` may race the lazy-create.** `WorkerMonitor` still lazily creates tables; the new migrations are authoritative. Mitigation: keep the `hasTable` guard (`WorkerMonitor.php:517,564`) so migration + lazy-create are idempotent.
- **R6 — Per-queue config split confusion.** A queue's identity (`connections`) stays in core while its worker/scaling knobs move. Mitigation: document the split table above; have the extension `mergeConfig` so operators keep a single mental model of "queue settings."

## Verification (required coverage)

The implementation plan must include these. **All of group 1–2 run on a plain core checkout (no extensions)** — that's the gate that core is self-sufficient and decoupled.

**1. `QueueWorker` correctness (core, SQLite/DatabaseQueue harness)** — port-1:1 semantics from `executeProcess`:
- success: job pops, `fire()` runs, processed count increments, job removed;
- release: a throwing job with `attempts < max` is `release()`d (re-queued) with backoff;
- fail-at-max-attempts: a throwing job at the attempts ceiling goes to `failed()` (FailedJobProvider), not re-queued;
- `stopWhenEmpty`: loop exits when the queue drains;
- `maxJobs`: stops after N jobs; `maxJobs = 0` runs unbounded (relaxed `WorkerOptions`, Decision §8);
- `maxRuntime`: stops after the window; `maxRuntime = 0` runs unbounded;
- `runOnce()` processes at most one job and returns the right bool;
- monitoring ordering: with a spy monitor, assert `recordJobStart` is called **before** `recordJobSuccess`/`recordJobFailure` for the same `job_uuid` (the row-seed contract);
- `--connection` selects the target connection (default = `config('queue.default')`).

**2. Decoupling acceptance (core, plain checkout)**:
- a grep of runtime/core source (`src/`, excluding `docs/`/specs/test fixtures) finds **no** references to the concrete `Glueful\Queue\Monitoring\WorkerMonitor`, nor to any `Glueful\Queue\Process\*`, after WS3;
- `WorkerMonitorInterface` resolves to `NullWorkerMonitor` from the container;
- `GET /health/queue` returns a valid response with the null monitor (zero active workers, no error);
- `QueueMaintenance` runs to completion with the null monitor (worker/metric cleanup steps no-op, failed-job cleanup still works);
- the lean `queue:work` registers (and on exit unregisters) under a real monitor but no-ops under the null one;
- removed `queue:work` sub-actions / `queue:autoscale` are absent from `commands:list`; invoking them yields a standard command-not-found / unknown-argument error (no core stub, no actionable-message shim).

**3. Extension override (with `glueful/queue-ops` installed)**:
- a booted container resolves `WorkerMonitorInterface` to the real `WorkerMonitor` (last-provider-wins over core's null binding);
- `queue:supervise` + `queue:autoscale` appear and spawn **supervised leaf workers** (via the extension's own `queue:supervise process` IPC loop, per G5 — *not* the core lean `queue:work`); `getActiveWorkers()`/metrics populate; reporting commands (`getWorkerStats`/`getJobMetrics`/`getPerformanceStats`) work against the concrete monitor.

## Sequencing & non-goals

- **Order (non-negotiable): WS1 → WS2 → WS3 → WS4 → WS5.** The first three are core-only and leave a fully working plain install; only after WS3 does core stop referencing any ops class, unlocking the move. **This is the highest design-prerequisite extraction in the boundary program — it is build-then-extract, not lift-then-move.**
- **Non-goals:** changing `QueueManager`/driver/`Job` contracts; redesigning the autoscaler/resource-monitor internals (lift as-is); adding new ops features; extracting the Redis driver (stays as the optional second reference); touching `queue.monitoring` alert semantics. (The *only* sanctioned `WorkerOptions` change is the `0 = unlimited` clamp relaxation, Decision §8 — otherwise the VO is untouched.)

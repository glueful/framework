# Extract Queue Ops → glueful/queue-ops — Implementation Plan

> **For agentic workers:** implement task-by-task with TDD (failing test first). Each task is independently green and reviewable; **do not start a task before the previous is green.** Design (authoritative — do not re-litigate): `docs/superpowers/specs/2026-06-06-extract-queue-ops-design.md`. This is the **highest design-prerequisite extraction in the boundary program — build-then-extract, not lift-then-move.**

**Goal:** Give core a lean, dependency-free `QueueWorker` + a minimal `WorkerMonitorInterface`/`NullWorkerMonitor` seam, rewrite `queue:work` onto it, then relocate all autoscaling / process-supervision / worker-monitoring product surface (`Process/*`, the concrete `WorkerMonitor`, `queue:autoscale`, and the supervised worker mode as `queue:supervise`) into a new `glueful/queue-ops` extension — with `php glueful queue:work` still draining the queue on core alone.

**Compatibility/Tech:** Intentional clean break (project past 1.0, no back-compat for these extractions): the `queue:work` sub-actions (`work`(multi)/`process`/`spawn`/`scale`/`status`/`stop`/`restart`/`health`) and the entire `queue:autoscale` command are **removed from core with no aliases and no actionable stub** — invoking them yields a standard command-not-found / unknown-argument error. PHP 8.3, PHPUnit 10.5; framework/library tests extend `PHPUnit\Framework\TestCase` against the lightweight SQLite `Connection`/`DatabaseQueue` harness. One sanctioned `WorkerOptions` change (`0 = unlimited`); the VO is not otherwise touched.

**Sequencing (non-negotiable — reversing it leaves core uncompilable):** WS1 → WS2 → WS3 → WS4 → WS5. **This is a mixed extraction** (it is not uniformly copy-first like the Archive plan): WS1–WS3 are **real in-place core refactors** (add the `WorkerMonitorInterface`/`NullWorkerMonitor` seam, build the lean `QueueWorker`, rewrite `queue:work`, repoint `HealthController`/`QueueMaintenance`/`QueueProvider`, flip the binding to the null monitor) — each is an incremental, individually green core commit, **not** a copy-first move, and core stays compilable and green after every one. WS4–WS5 are **copy-first**: they **copy** the ops tree (`Process/*`, the concrete `WorkerMonitor`, `AutoScaleCommand`, the supervised worker mode) into `glueful/queue-ops` and leave the core originals **physically in place** (after WS3 those concrete classes still exist but core no longer wires or imports them — only the interface + null binding remain), then a **single atomic core-removal step** (Task 4e) deletes all the now-duplicated concrete classes/commands together, once the extension copies are proven green.

**Hard gate before WS4:** after WS3, a plain checkout (no extensions) must compile, test green, and contain **zero** references to the concrete `Glueful\Queue\Monitoring\WorkerMonitor` or any `Glueful\Queue\Process\*` outside the still-present-but-unreferenced files. This is what makes the WS4 copy-first + atomic-removal safe: because core already references the ops classes only through `WorkerMonitorInterface` + the `NullWorkerMonitor` binding, deleting the concrete classes in Task 4e cannot break core. Do not begin WS4 until that gate passes.

**Verification gate per task:** `composer test` (targeted green), `composer run analyse` (no new PHPStan errors), `composer run phpcs`.

---

## WS1 — [PREREQUISITE] `WorkerMonitorInterface` + `NullWorkerMonitor`; repoint consumers

Introduce the core seam and repoint the three consumers (`HealthController`, `QueueMaintenance`, `QueueProvider`) to it. Nothing moves yet; the concrete `WorkerMonitor` stays bound and keeps working — this task is purely additive + repointing and must leave core fully functional.

### Task 1a — Add `WorkerMonitorInterface` + `NullWorkerMonitor`; have `WorkerMonitor` implement it

**Files**
- Create: `src/Queue/Contracts/WorkerMonitorInterface.php`
- Create: `src/Queue/Monitoring/NullWorkerMonitor.php`
- Create: `tests/Unit/Queue/Monitoring/NullWorkerMonitorTest.php`
- Modify: `src/Queue/Monitoring/WorkerMonitor.php` (add `implements WorkerMonitorInterface`)

**Interface method set — the trimmed subset (verify against the spec table; do NOT add the excluded reporting methods):**
```php
namespace Glueful\Queue\Contracts;

use Glueful\Queue\Contracts\JobInterface;

interface WorkerMonitorInterface
{
    public function getActiveWorkers(): array;                 // HealthController:729; QueueMaintenance:248
    public function cleanupOldWorkers(int $days = 7): bool;    // QueueMaintenance:95
    public function cleanupOldMetrics(int $days = 30): bool;   // QueueMaintenance:114
    public function recordJobStart(JobInterface $job): void;   // QueueWorker per-job (seeds the metrics row)
    public function recordJobSuccess(JobInterface $job, float $secs): void;            // QueueWorker per-job
    public function recordJobFailure(JobInterface $job, \Exception $e, float $secs): void; // QueueWorker per-job
    public function registerWorker(string $uuid, array $data): void;       // QueueWorker self-registration
    public function updateWorkerHeartbeat(string $uuid, array $data): void; // QueueWorker heartbeat
    public function unregisterWorker(string $uuid, array $final = []): void; // QueueWorker shutdown
    public function isEnabled(): bool;                          // gate checks
}
```
> **Excluded on purpose** (ops reporting only — stay on the concrete `WorkerMonitor`, never in the contract): `getWorkerStats()`, `getJobMetrics()`, `getPerformanceStats()`. Their only callers are the ops `status`/reporting commands, which move to `queue-ops` and type-hint the concrete `WorkerMonitor`. Match the existing concrete signatures exactly so `WorkerMonitor` satisfies the interface without edits to its method bodies: `cleanupOldWorkers(int $daysOld = 7)`/`cleanupOldMetrics(int $daysOld = 30)` already match by position; keep param names as-is on the concrete (PHP matches by position). Use array typehints matching the concrete's docblocks (`array<int, array<string, mixed>>` etc. via docblocks on the interface).

`NullWorkerMonitor implements WorkerMonitorInterface` — every method a no-op / empty return: reads return `[]` (`getActiveWorkers`), cleanups return `false`, `isEnabled()` returns `false`, all `record*`/`registerWorker`/`updateWorkerHeartbeat`/`unregisterWorker` no-op. No DB connection, no constructor args.

**Steps**
- [ ] Write `tests/Unit/Queue/Monitoring/NullWorkerMonitorTest.php`: asserts `getActiveWorkers() === []`, `cleanupOldWorkers()`/`cleanupOldMetrics()` return `false`, `isEnabled()` is `false`, and `recordJobStart/Success/Failure` + `registerWorker/updateWorkerHeartbeat/unregisterWorker` execute without error or DB access (construct with no args). Run — fails (classes absent).
- [ ] Add `src/Queue/Contracts/WorkerMonitorInterface.php` with the 10 methods above + docblocks.
- [ ] Add `src/Queue/Monitoring/NullWorkerMonitor.php` implementing it as no-ops.
- [ ] Add `implements WorkerMonitorInterface` to `src/Queue/Monitoring/WorkerMonitor.php` (no body changes — it already has every method).
- [ ] Run `composer test` (targeted), `composer run analyse`, `composer run phpcs` — green. Commit.

**Rollback risk:** Low — additive contract + null impl + one `implements`. Revert = delete the two new files, drop `implements`.

### Task 1b — Repoint `QueueMaintenance` + `HealthController` to the interface; bind interface in `QueueProvider` (still concrete)

**Files**
- Modify: `src/Queue/Jobs/QueueMaintenance.php` (kill `new WorkerMonitor()` at `:48`; resolve interface)
- Modify: `src/Controllers/HealthController.php` (`getQueueHealth()` `:723`)
- Modify: `src/Queue/ServiceProvider/QueueProvider.php` (`:28-29`)
- Modify: `tests/Integration/Queue/Jobs/...` if an existing maintenance test asserts the concrete type (audit first); else Create: `tests/Unit/Queue/QueueMaintenanceMonitorSeamTest.php`

**Changes**
- `QueueMaintenance`: change the `WorkerMonitor $workerMonitor` property type to `WorkerMonitorInterface`; replace the `new WorkerMonitor(null, true, $this->context)` at `:48` with container resolution of the interface. It already holds `?ApplicationContext $context`; resolve via `container($this->context)->get(WorkerMonitorInterface::class)` (guard the null-context path — keep the existing constructor-builds-its-own-deps pattern but pull the monitor from the container when context is present; when context is null, fall back to `new NullWorkerMonitor()` so cleanup steps no-op rather than fatal). Imports: drop `use Glueful\Queue\Monitoring\WorkerMonitor;`, add `use Glueful\Queue\Contracts\WorkerMonitorInterface;` and `use Glueful\Queue\Monitoring\NullWorkerMonitor;`.
- `HealthController::getQueueHealth()`: change `container(...)->get(\Glueful\Queue\Monitoring\WorkerMonitor::class)` at `:723` to `->get(\Glueful\Queue\Contracts\WorkerMonitorInterface::class)`. No other change — `getActiveWorkers()` is on the interface.
- `QueueProvider`: replace the `WorkerMonitor` autowire def (`:28-29`) with **two** entries for now: keep `WorkerMonitor::class => autowire(WorkerMonitor::class)` (concrete still resolvable for tests/ops paths in this transitional state) **and** add `WorkerMonitorInterface::class => autowire(WorkerMonitor::class)` (interface → concrete). This keeps core fully functional and behind the interface, with the concrete still bound (it gets dropped in WS3).

**Steps**
- [ ] Audit: `grep -rn "Monitoring\\\\WorkerMonitor\|WorkerMonitor::class" src/` to confirm the only non-`Process/*`, non-command consumers are `QueueMaintenance:48`, `HealthController:723`, `QueueProvider:28`. Record findings in the task notes.
- [ ] Write/extend a test: container resolves `WorkerMonitorInterface` to a `WorkerMonitor` (booted/integration harness) **or** unit-assert `QueueMaintenance` uses an injected interface (e.g. construct with a context whose container binds a spy implementing the interface; assert `cleanupOldWorkers`/`cleanupOldMetrics`/`getActiveWorkers` are called through it). Also assert that with a null context `QueueMaintenance::handle()` runs to completion (monitor steps no-op). Run — fails.
- [ ] Repoint `QueueMaintenance` (`:31-32` property type, `:48` instantiation, imports).
- [ ] Repoint `HealthController:723` to the interface.
- [ ] Update `QueueProvider` to add the interface→concrete binding (keep concrete binding too, transitional).
- [ ] Run `composer test`, `composer run analyse`, `composer run phpcs` — green. Confirm `GET /health/queue` still resolves (existing health test). Commit.

**Rollback risk:** Medium — touches a controller, a job, and DI. Mitigated: behavior identical (interface → same concrete). Revert = restore the three call sites to the concrete class.

---

## WS2 — [PREREQUISITE] Build lean `QueueWorker`; rewrite `queue:work` onto it

The load-bearing task (Risk R1). `QueueWorker` becomes the **only** job-draining loop in the framework. Port `WorkCommand::executeProcess` (`:254-355`) semantics **1:1**, with stdout IPC stripped (IPC stays with the supervisor per G5) and monitoring routed through `WorkerMonitorInterface`. Build with exhaustive tests against the SQLite/`DatabaseQueue` harness **before** any `Process/*` code is touched.

### Task 2a — `Glueful\Queue\QueueWorker` (TDD, full coverage)

**Files**
- Create: `src/Queue/QueueWorker.php`
- Create: `tests/Unit/Queue/QueueWorkerTest.php` (SQLite/`DatabaseQueue` harness + spy monitor)
- Modify: `src/Queue/ServiceProvider/QueueProvider.php` (register `autowire(QueueWorker::class)`)

**Class shape**
```php
namespace Glueful\Queue;

use Glueful\Queue\Contracts\WorkerMonitorInterface;

final class QueueWorker
{
    public function __construct(
        private QueueManager $manager,
        private WorkerMonitorInterface $monitor,   // NullWorkerMonitor by default (G2)
        private \Psr\Log\LoggerInterface $logger,
    ) {}

    /** @param array<int,string> $queues */
    public function daemon(string $connection, array $queues, WorkerOptions $options): int;

    /** Process at most one ready job across $queues; true if one ran. */
    public function runOnce(string $connection, array $queues, WorkerOptions $options): bool;
}
```

**Port `executeProcess` (`WorkCommand.php:254-355`) semantics 1:1 — exact line refs:**
- **Driver resolution:** `$driver = $this->manager->connection($connection)` (replaces `WorkCommand:266-267` which used the default connection; now connection-targeted per Decision §9). `QueueManager::connection(?string)` is verified at `QueueManager.php:86`.
- **Per-queue pop:** iterate trimmed, non-empty queues; `$job = $driver->pop($queue)`; `null` → continue (`WorkCommand:291-300`).
- **Seed-then-process ordering (mandatory, the row-seed contract):** before `fire()`, call `$this->monitor->recordJobStart($job)`. `recordJobSuccess`/`recordJobFailure` update by `job_uuid` (`WorkerMonitor.php:209-218,236-245` — `->where('job_uuid', $job->getUuid())->update(...)`); without a prior `recordJobStart` the update touches zero rows and metrics vanish. No-op under `NullWorkerMonitor`.
- **Success path:** time the call; `$job->fire()` (`WorkCommand:305`); increment processed count (`:306`); `$this->monitor->recordJobSuccess($job, $secs)`. **Strip** the `[JOB_COMPLETED]`/`[METRICS]` stdout lines (`:308-313`) — IPC, stays with supervisor (G5).
- **Throw path — catch `\Throwable`** (`WorkCommand:314`): if `$job->getAttempts() < min($job->getMaxAttempts(), $options->maxAttempts)` → `$job->release($backoff)` (`:315-316`), else `$job->failed($wrapped)` (`:317-319`). **Wrap non-`Exception` throwables** exactly as the original (`:318`): `$wrapped = $e instanceof \Exception ? $e : new \RuntimeException($e->getMessage(), 0, $e)`. Pass the **same** `$wrapped` to `$this->monitor->recordJobFailure($job, $wrapped, $secs)` — `JobInterface::failed(\Exception)` (`JobInterface.php:91`) and `WorkerMonitorInterface::recordJobFailure(\Exception)` both require `\Exception`, so the wrap must be consistent across both calls. **Strip** the `[with-monitoring]` `$this->error(...)` line (`:321-329`) — replace with `$this->logger->error(...)`.
- **Backoff:** the original used flat `--sleep` (`$job->release($sleep)`). New wiring: derive backoff from `config('queue.workers.performance.backoff_*')` (`config/queue.php:345-351` — `backoff_strategy`/`backoff_base`/`max_backoff`), **with flat-sleep fallback** (`$options->sleep`) when config is absent. Keep a small private `computeBackoff(int $attempts, WorkerOptions $options): int` helper; default behavior with no config = flat `$options->sleep` (preserves original semantics).
- **Stop conditions (1:1):** `maxJobs > 0 && processed >= maxJobs` → return SUCCESS (`:332-334`); `maxRuntime > 0 && elapsed >= maxRuntime` → return SUCCESS (`:337-339`); `stopWhenEmpty && !jobProcessedInCycle` → return SUCCESS (`:341-343`). The `> 0` guards are what make `0 = unlimited` work (depends on relaxed `WorkerOptions`, Task 2b).
- **Idle sleep:** when no job processed in a cycle, `sleep($options->sleep)` (`:345-347`).
- **Signals:** `pcntl_signal(SIGTERM/SIGINT, …)` set `$running = false` (`:275-282`); `pcntl_signal_dispatch()` per cycle (`:349-351`). Graceful shutdown = loop exits, then `unregisterWorker`.
- **Worker lifecycle:** at start, generate a worker uuid and `$this->monitor->registerWorker($uuid, [...])` (connection, queue list, pid, started_at, options); periodically `updateWorkerHeartbeat`; on exit `unregisterWorker($uuid, [...])`. All no-op under `NullWorkerMonitor`. (This is what lets `queue-ops` observe lean workers via `getActiveWorkers()` when installed; harmless zero-workers on plain core.)
- **`runOnce()`:** single pass across queues processing **at most one** job (same seed→fire→success/throw logic), returns `true` if a job ran, else `false`. Used by `--once` (replaces the old `process` one-shot).

**Tests (port-1:1 coverage from `executeProcess`, the Verification group-1 list):**
- [ ] **success:** push a job; `runOnce()`/`daemon(stopWhenEmpty)` → `fire()` ran, processed count incremented, job removed from queue.
- [ ] **release:** a throwing job with `attempts < max` is `release()`d (re-queued) with the computed backoff delay.
- [ ] **fail-at-max-attempts:** a throwing job at the attempts ceiling goes to `failed()` (lands in `FailedJobProvider`/`queue_failed_jobs`), not re-queued.
- [ ] **non-`Exception` throwable:** a job throwing a `\Error`/`\Throwable` at max attempts → `failed()` receives a `\RuntimeException` wrapping it; the **same** instance is passed to `recordJobFailure` (spy monitor asserts identity/message).
- [ ] **stopWhenEmpty:** `daemon()` exits when the queue drains.
- [ ] **maxJobs:** stops after N jobs; **`maxJobs = 0` runs unbounded** (bounded in the test by `stopWhenEmpty` or a small fixed job count) — proves `0 = unlimited` (needs Task 2b).
- [ ] **maxRuntime:** stops after the window; **`maxRuntime = 0` runs unbounded**.
- [ ] **runOnce:** processes at most one job; returns correct bool (`true` when one ran, `false` on empty).
- [ ] **monitoring ordering:** with a spy `WorkerMonitorInterface`, assert `recordJobStart` is called **before** `recordJobSuccess`/`recordJobFailure` for the same `job_uuid`.
- [ ] **`--connection` targeting (unit level here):** `daemon('redis'|named, …)` resolves `QueueManager::connection($connection)` (assert via a fake manager that the requested connection name is passed; default path covered in 2b at the command layer).
- [ ] Register `autowire(QueueWorker::class)` in `QueueProvider`. Run `composer test`/`analyse`/`phpcs` — green. Commit.

**Rollback risk:** High (R1) — this is the only job loop. Mitigated by exhaustive harness coverage landed **before** the move and 1:1 porting from the verified loop. Revert = delete `QueueWorker` + its DI def + tests (WS2b would also revert).

### Task 2b — Relax `WorkerOptions` clamps to `0 = unlimited`

**Files**
- Modify: `src/Queue/WorkerOptions.php` (`:96`, `:101`, and the matching `validate()` lines `:302-303`, `:314-315`)
- Create: `tests/Unit/Queue/WorkerOptionsZeroUnlimitedTest.php`

**Change (the only sanctioned `WorkerOptions` change, G7/Decision §8):**
- `:96` `$this->maxJobs = max(1, $maxJobs);` → `max(0, $maxJobs);`
- `:101` `$this->maxRuntime = max(60, $maxRuntime);` → `max(0, $maxRuntime);`
- Relax the corresponding `validate()` guards so `0` is valid: `:302-303` (`maxJobs < 1`) → `maxJobs < 0`; `:314-315` (`maxRuntime < 60`) → `maxRuntime < 0`. **Leave every other clamp untouched** (`sleep >= 1` `:93`, `memory >= 32` `:94`, `timeout >= 1` `:95`, `maxAttempts >= 1` `:98`, `heartbeat >= 5` `:99`, `batchSize >= 1` `:104`). Additive to the accepted range — supervisor/autoscaler unaffected.

**Steps**
- [ ] Write `tests/Unit/Queue/WorkerOptionsZeroUnlimitedTest.php`: `new WorkerOptions(maxJobs: 0, maxRuntime: 0)` keeps `0` (not clamped up); `isValid()` is `true`; a negative input still floors at `0`; all other clamps still enforce their minimums (e.g. `memory: 1` → `32`). Run — fails.
- [ ] Apply the two `max(0, …)` changes + the two `validate()` relaxations.
- [ ] Run `composer test`/`analyse`/`phpcs` — green. Commit.

**Rollback risk:** Low — two clamps + two validations. Revert = restore `max(1,…)`/`max(60,…)` and the original guards.

### Task 2c — Rewrite `queue:work` lean onto `QueueWorker`; delete supervised actions

**Files**
- Modify (rewrite): `src/Console/Commands/Queue/WorkCommand.php`
- Create: `tests/Unit/Console/Commands/Queue/WorkCommandLeanTest.php` (or Integration with the console tester)

**New lean `queue:work` contract** (drop `action` argument + all supervisor/`process`/IPC options):
```
php glueful queue:work [--connection=] [--queue=] [--once] [--sleep=] [--memory=] [--timeout=] [--max-jobs=] [--max-runtime=] [--tries=] [--stop-when-empty]
```
- `--connection=` — default `config('queue.default')` (Decision §9); selects the `QueueManager` connection. (Positional connection arg is an acceptable alternative; this plan uses the option.)
- `--queue=` — comma-separated list (default `default`); parsed via the existing `parseQueues()`.
- `--once` — calls `QueueWorker::runOnce()`; default (no `--once`) calls `daemon()`.
- `--max-jobs=` default `0`, `--max-runtime=` default `0` (now `0 = unlimited`, relaxed VO).
- `--memory=`, `--sleep=`, `--timeout=`, `--tries=` (→ `WorkerOptions::maxAttempts`), `--stop-when-empty` → `WorkerOptions`.

**Delete from `WorkCommand`:**
- Imports of `ProcessManager`, `ProcessFactory`, `WorkerMonitor` (`:7-9,11`) and the `LockManagerInterface` import if unused after rewrite (`:12` — it is only used by `spawnWorkersWithLock`, which is deleted).
- Properties `$processManager`, `$workerMonitor`, `$lockManager` (`:35-37`).
- `initializeServices()` (`:190-204`).
- The action dispatch `match` (`:170-180`) and **all** sub-action methods: `executeWork` (`:206-246`), `executeProcess` (`:254-355` — its semantics now live in `QueueWorker`), `executeSpawn`/`executeScale`/`executeStatus`/`executeStop`/`executeRestart`/`executeHealth` (`:357-505`), `monitorWorkers`/`watchStatus`/`displayWorkerStatus`/`createWorkerOptionsFromInput`/`formatStatus`/`handleUnknownAction`/`spawnWorkersWithLock` (`:507-672`), and the supervisor-only options in `configure()` (`workers`/`daemon`/`count`/`worker-id`/`all`/`json`/`watch`/`max-attempts`/`with-monitoring`/`emit-heartbeat`, `:50-160`). **No back-compat stub for the removed actions** (G4/G6) — the `action` argument is simply gone, so `queue:work spawn` etc. become "too many arguments"/unknown-argument errors.

**New `execute()`:** resolve `QueueWorker` from the container (`$this->getService(QueueWorker::class)`); build `WorkerOptions` from the options; `$connection = $input->getOption('connection') ?: config($this->getContext(), 'queue.default')`; `--once` → `return runOnce(...) ? SUCCESS : SUCCESS` (one-shot always succeeds unless it throws); else `return $worker->daemon($connection, $queues, $options)`.

**Steps**
- [ ] Write `WorkCommandLeanTest`: (a) `queue:work --once` with one queued job runs it via `QueueWorker` (assert the job fired / processed); (b) `--connection` defaults to `config('queue.default')` when omitted, and targets the named connection when passed (assert through a fake/booted manager); (c) the removed actions are not accepted — invoking `queue:work spawn` returns a non-zero/usage error (Symfony console "too many arguments"). Run — fails (command still has the old shape).
- [ ] Rewrite `WorkCommand` per the contract above; delete all listed members.
- [ ] Run `composer test`/`analyse`/`phpcs` — green. Verify `php glueful queue:work --once --stop-when-empty` drains locally against SQLite. Commit.

**Rollback risk:** High — replaces the primary worker entrypoint and removes the supervised surface. Mitigated: `QueueWorker` is already proven (2a); command is a thin adapter. Revert = restore the old `WorkCommand` (kept in git history) — but note WS3+ depend on this, so a full revert unwinds to pre-WS2.

---

## WS3 — Default-bind `NullWorkerMonitor`; core no longer references the concrete

Flip the interface binding to the null impl and drop the concrete `WorkerMonitor` binding from core DI. After this, **core references no concrete `WorkerMonitor` and no `Process/*`** — the move can begin. This is the hard gate (R2).

### Task 3a — Flip `QueueProvider` to `WorkerMonitorInterface => NullWorkerMonitor`; remove concrete bind

**Files**
- Modify: `src/Queue/ServiceProvider/QueueProvider.php`
- Create: `tests/Integration/Queue/NullMonitorDefaultBindingTest.php`

**Changes**
- Remove the `WorkerMonitor::class => autowire(WorkerMonitor::class)` def (added transitionally in 1b) and change the interface binding to `WorkerMonitorInterface::class => autowire(NullWorkerMonitor::class)`. Drop any `use`/FQCN reference to the concrete `WorkerMonitor` from `QueueProvider`. `QueueWorker`, `QueueManager`, `FailedJobProvider`, `JobScheduler`, registry/plugin defs stay.

**Steps**
- [ ] Write `NullMonitorDefaultBindingTest`: a booted core container resolves `WorkerMonitorInterface` to `NullWorkerMonitor`; `getActiveWorkers()` returns `[]`. Run — fails (still bound to concrete).
- [ ] Update `QueueProvider`.
- [ ] Run `composer test`/`analyse`/`phpcs` — green. Commit.

**Rollback risk:** Low-Medium — DI binding flip. Revert = rebind interface → concrete.

### Task 3b — Decoupling acceptance gate (core, plain checkout)

**Files**
- Create: `tests/Integration/Queue/CoreQueueDecouplingTest.php`
- Create: `tests/Integration/Health/QueueHealthNullMonitorTest.php` (if not already covered)

**Behavior proven (Verification group 2 — all on a plain core checkout, no extensions):**
- A grep of runtime/core source finds **no** references to the concrete `Glueful\Queue\Monitoring\WorkerMonitor`, nor to any `Glueful\Queue\Process\*` (these classes still physically exist in `src/` until WS4, but **nothing in core wires or imports them** any longer). Assert via a code-level test or a documented `grep` step over `src/` excluding `src/Queue/Process/`, `src/Queue/Monitoring/WorkerMonitor.php`, `docs/`, and test fixtures.
- `WorkerMonitorInterface` resolves to `NullWorkerMonitor`.
- `GET /health/queue` returns a valid response with the null monitor: `workers.active = 0`, no error, and the `no_active_workers_with_pending_jobs` signal still computes against real queue stats (`HealthController:739-742`).
- `QueueMaintenance::handle()` runs to completion with the null monitor (worker/metric cleanup steps no-op; failed-job cleanup via `FailedJobProvider` still works).
- The lean `queue:work` no-ops monitoring under the null monitor (registers/unregisters are no-ops; no DB writes to `queue_workers`).
- Removed `queue:work` sub-actions / `queue:autoscale` are absent from `commands:list`; invoking them yields a standard command-not-found / unknown-argument error (no core stub, no actionable-message shim).

**Steps**
- [ ] Write the acceptance tests above. Run — should pass after 3a (this task formalizes the gate).
- [ ] Run the documented decoupling grep over `src/` (excluding the to-be-moved files + `docs/`/fixtures); confirm zero hits. Record the command + output in the task notes.
- [ ] Run `composer test`/`analyse`/`phpcs` — green. **Commit. This commit is the hard gate — WS4 must not start until it is green on a plain checkout.**

**Rollback risk:** Low — test-only + the gate.

---

## WS4 — Relocate the ops tree to `glueful/queue-ops` (copy-first, then one atomic core removal)

Stand up the new extension and **copy** `Process/*` (7 classes), the concrete `WorkerMonitor`, `AutoScaleCommand` (→ `queue:autoscale`), and the supervised worker mode (→ `queue:supervise`, including the leaf-worker `executeProcess` IPC loop intact, G5) into it. `LockManagerInterface`, `QueueManager`, `WorkerOptions`, `WorkerMonitorInterface`, `LoggerInterface` are consumed from core. **Copy-first, not `git mv`:** Tasks 4b–4d copy each class/command into the extension and leave the core originals **physically in place** (they are already unreferenced by core after WS3, so they sit harmlessly). **Task 4e is the single atomic core-removal commit** that deletes all the now-duplicated concrete classes + moved commands from core together, after the extension copies are proven green — keeping core green/compilable at every step (the only breaking removal is that one step).

> **Core stays green every task — copy-first, then one atomic removal.** Tasks 4b–4d **copy** code into `glueful/queue-ops` and leave the core originals **untouched**, so a plain core checkout still compiles and its suite still passes after each of those tasks (core uses `NullWorkerMonitor` and references the ops classes through nothing but the interface). **Task 4e deletes the duplicated core sources atomically.** Do **not** `git mv` the concrete classes out of core in Tasks 4b–4d — that would break the still-present-but-unreferenced files mid-series; copy, prove green in the extension, then remove in 4e.

> **Cross-repo note (per Aegis-style workflow):** `glueful/queue-ops` is a sibling package; develop it against the framework via a path/symlink composer repo so the extension's tests boot the real core. Commit the extension scaffold/copies (4a–4d) on their own; the core deletions land together in the atomic Task 4e so the plain-checkout gate (WS3) stays green throughout and the extension is self-contained.

### Task 4a — Scaffold `glueful/queue-ops` (composer manifest + ServiceProvider skeleton)

**Files (in the `glueful/queue-ops` package)**
- Create: `composer.json` — the canonical Glueful extension manifest (strict JSON, **no `//` comments**). `glueful/framework` sits in **require-dev** (the extension is a plugin, not a library that ships the framework). `queue-ops` **ships migrations** for `queue_workers`/`queue_job_metrics` (WS5), so the `classmap` autoload **keeps** `migrations/`:
  ```json
  {
      "name": "glueful/queue-ops",
      "description": "Queue operations for Glueful (worker supervision, autoscaling, worker/job metrics).",
      "type": "glueful-extension",
      "license": "MIT",
      "authors": [{ "name": "Michael Tawiah Sowah", "email": "michael@glueful.dev" }],
      "keywords": ["queue", "workers", "autoscaling", "operations", "glueful"],
      "require": {
          "php": "^8.3"
      },
      "require-dev": {
          "glueful/framework": "^1.52.0",
          "phpunit/phpunit": "^10.5",
          "squizlabs/php_codesniffer": "^3.6",
          "phpstan/phpstan": "^1.0"
      },
      "homepage": "https://github.com/glueful/queue-ops",
      "autoload": {
          "psr-4": { "Glueful\\Extensions\\QueueOps\\": "src/" },
          "classmap": ["migrations/"]
      },
      "autoload-dev": {
          "psr-4": { "Glueful\\Extensions\\QueueOps\\Tests\\": "tests/" }
      },
      "scripts": {
          "test": "vendor/bin/phpunit",
          "phpcs": "vendor/bin/phpcs --standard=Squiz src",
          "phpcbf": "vendor/bin/phpcbf --standard=Squiz src",
          "analyze": "vendor/bin/phpstan analyze src --level=8"
      },
      "extra": {
          "glueful": {
              "name": "QueueOps",
              "displayName": "Queue Ops",
              "description": "Queue operations for Glueful: supervision, autoscaling, worker metrics.",
              "version": "1.0.0",
              "categories": ["queue", "operations"],
              "publisher": "glueful-team",
              "provider": "Glueful\\Extensions\\QueueOps\\QueueOpsServiceProvider",
              "requires": { "glueful": ">=1.52.0", "extensions": [] }
          }
      },
      "config": { "sort-packages": true }
  }
  ```
  **Strict JSON** (no comments) and **keep the `migrations/` classmap** — `queue-ops` ships proper migrations because the concrete `WorkerMonitor` lazily `CREATE TABLE`s `queue_workers`/`queue_job_metrics` today (WS5 replaces that with real migrations, hence the `migrations/` classmap). The `^1.52.0` constraint is the **coordinated breaking release** that performs the core removal (Task 4e lands there) — fill in the real version once it is cut. The package ships its own `test`/`phpcs`/`phpcbf`/`analyze` scripts + dev tooling so the per-task gates run **from the package root**; they do not depend on the framework's composer scripts. Local pre-release development resolves `glueful/framework` from a **project-level path repository** (as `create:extension` wires up) — that is a developer-machine convenience and is **not committed** in this manifest.
- Create: `src/QueueOpsServiceProvider.php` extending `Glueful\Extensions\ServiceProvider` — `services()` (DI defs, filled in 4b–4d), `register()` (mergeConfig + migrations, WS5), `boot()` (`discoverCommands`, 4d).
- Create: `tests/` harness mirroring the framework's SQLite `Connection` setup.

**Steps**
- [ ] Create the manifest + empty provider with `services(): []`, `register()`, `boot()` no-ops. Add a smoke test that the provider class loads and `services()` returns an array. Run `composer install` in the package; run the smoke test — green. Commit (in the extension repo).

**Rollback risk:** Low — new package scaffold.

### Task 4b — Copy the concrete `WorkerMonitor` into the extension; bind the interface

**Files**
- Copy (core original stays until Task 4e): `src/Queue/Monitoring/WorkerMonitor.php` (core) → `glueful/queue-ops` `src/Monitoring/WorkerMonitor.php`, namespace `Glueful\Extensions\QueueOps\Monitoring`. Keep it `implements \Glueful\Queue\Contracts\WorkerMonitorInterface`. **Keep** the reporting methods (`getWorkerStats`/`getJobMetrics`/`getPerformanceStats`) — they live on the concrete and are used by ops commands. **Keep** the lazy `hasTable`-guarded `CREATE TABLE` (`:517,564`) as a fallback (R5). (The core copy stays physically present but unreferenced — it is deleted in the atomic Task 4e; the `NullWorkerMonitor` stays in core permanently.)
- Modify (extension): `QueueOpsServiceProvider::services()` — bind `WorkerMonitorInterface::class => autowire(\Glueful\Extensions\QueueOps\Monitoring\WorkerMonitor::class)` (overrides core's null default; last-provider-wins).
- Create (extension): `tests/.../WorkerMonitorOverrideTest.php`.

**Steps**
- [ ] (extension) Write a test: with `queue-ops` booted, the container resolves `WorkerMonitorInterface` to the extension `WorkerMonitor` (R3); `recordJobStart` then `recordJobSuccess` against the SQLite harness writes/updates a `queue_job_metrics` row by `job_uuid`. Run — fails (class not yet present).
- [ ] **Copy** the file + rename namespace (leave the core original in place — Task 4e removes it); bind in `services()`.
- [ ] Re-run the WS3 plain-checkout gate (core test suite) — still green (core uses `NullWorkerMonitor` and never references the concrete, copied or original).
- [ ] Run core + extension `composer test`/`analyse`/`phpcs` — green. Commit (extension addition only — core is untouched).

**Rollback risk:** Low-Medium — copies a DB-backed class into the extension; core untouched. Mitigated by the override test + retained lazy-create. Revert = delete the extension copy + binding.

### Task 4c — Copy the `Process/*` tree + `AutoScaleCommand` into the extension

**Files**
- Copy (core originals stay until Task 4e) (core → extension `src/Process/`, namespace `Glueful\Extensions\QueueOps\Process`): `ProcessManager.php`, `ProcessFactory.php`, `WorkerProcess.php`, `AutoScaler.php`, `ScheduledScaler.php`, `ResourceMonitor.php`, `StreamingMonitor.php`. Update internal `use` statements in the **copies**: they reference core primitives that **stay** (`Glueful\Queue\WorkerOptions`, `Glueful\Queue\QueueManager`, `Psr\Log\*`, `Cron\CronExpression`) — leave those as core imports; repoint the `Glueful\Queue\Monitoring\WorkerMonitor` import in the copied `ProcessManager` (`:8,17,25-30`) to the **extension** `Glueful\Extensions\QueueOps\Monitoring\WorkerMonitor` (copied in 4b; constructor type-hint becomes the extension concrete — fine, both live in the extension).
- Copy (core original stays until Task 4e) (core → extension `src/Console/AutoScaleCommand.php`, namespace `Glueful\Extensions\QueueOps\Console`): `src/Console/Commands/Queue/AutoScaleCommand.php`. Keep `#[AsCommand(name: 'queue:autoscale')]`. Repoint the copy's `use` imports of `Process/*` + `WorkerMonitor` to the extension namespaces; `QueueManager`/`LoggerInterface` stay core. Its `config(...,'queue.workers',...)` reads move to `queue_ops` config in WS5.
- Modify (extension): `services()` registers `ProcessManager`/`ProcessFactory`/`AutoScaler`/`ScheduledScaler`/`ResourceMonitor`/`StreamingMonitor` (autowire or factory closures matching their constructors — `ProcessManager` needs `ProcessFactory`, the extension `WorkerMonitor`, `LoggerInterface`, config array, as in `WorkCommand::initializeServices` `:195-203` / `AutoScaleCommand::initializeServices` `:233-239`).

> Core originals (the 7 `src/Queue/Process/*` files + `src/Console/Commands/Queue/AutoScaleCommand.php`) remain physically present but unreferenced after this task; they are deleted in the atomic Task 4e. Because nothing in core imports them (WS3 gate), they sit harmlessly until then.

**Steps**
- [ ] (extension) Write a test that the container resolves `ProcessManager` and `AutoScaler` with their dependencies (smoke-level construction). Run — fails.
- [ ] **Copy** the 7 `Process/*` files + `AutoScaleCommand` into the extension (leave the core originals in place — Task 4e removes them); fix namespaces/imports in the copies; register in `services()`.
- [ ] Re-run the WS3 plain-checkout grep gate — confirm `src/` has **zero** `Glueful\Queue\Process\*` *references* (the original files still exist but nothing imports them) and the core suite is green.
- [ ] Run core + extension `composer test`/`analyse`/`phpcs` — green. Commit (extension additions only — core is untouched).

**Rollback risk:** Medium — large copy into the extension; core untouched. Mitigated: the tree only depended on staying-core primitives + the (now-copied) concrete monitor; no inbound core edges after WS3. Revert = delete the extension copies + imports.

### Task 4d — Supervised mode as `queue:supervise` (incl. leaf-worker IPC); discover commands

**Files**
- Create (extension): `src/Console/SuperviseCommand.php`, `#[AsCommand(name: 'queue:supervise')]`, namespace `Glueful\Extensions\QueueOps\Console`. Port from the deleted `WorkCommand` supervisor surface: the `work`/`spawn`/`scale`/`status`/`stop`/`restart`/`health` actions + `spawnWorkersWithLock`/`monitorWorkers`/`watchStatus`/`displayWorkerStatus`/`createWorkerOptionsFromInput`/`formatStatus` (old `WorkCommand:206-246,357-672`), **and** the leaf-worker `executeProcess` IPC loop (old `WorkCommand:254-355`) as the **`queue:supervise process`** sub-action — keeping the `[HEARTBEAT]`/`[JOB_COMPLETED]`/`[METRICS]` stdout lines intact (G5). Consume `LockManagerInterface`, `QueueManager`, the extension `ProcessManager`/`ProcessFactory`/`WorkerMonitor`, `LoggerInterface` from the container.
- Modify (extension): `ProcessFactory::buildWorkerCommand` (the copy made in 4c) — change the shelled command from `queue:work process …` (`ProcessFactory.php:104-149`) to **`queue:supervise process …`** so the supervisor spawns the extension's own leaf workers, not the lean core `queue:work` (G5/Decision §2). Keep all option flags (`--queue`/`--sleep`/`--max-jobs`/`--max-runtime`/`--timeout`/`--memory`/`--max-attempts`/`--stop-when-empty`/`--with-monitoring`/`--emit-heartbeat`).
- Modify (extension): `QueueOpsServiceProvider::boot()` — `discoverCommands('Glueful\\Extensions\\QueueOps\\Console', __DIR__ . '/Console')` (both `SuperviseCommand` + `AutoScaleCommand` carry `#[AsCommand]`).
- Create (extension): `tests/.../SuperviseSpawnsLeafWorkersTest.php`.

**Steps**
- [ ] (extension) Write a test (Verification group 3): with `queue-ops` booted, `queue:supervise` + `queue:autoscale` appear in the command list; `ProcessFactory::buildWorkerCommand` produces a `queue:supervise process …` argv (assert the command string); the leaf `queue:supervise process` loop drains a queue and emits `[HEARTBEAT]`/`[JOB_COMPLETED]` lines. Run — fails.
- [ ] Port `SuperviseCommand` (supervisor actions + `process` leaf loop); repoint `ProcessFactory` shell to `queue:supervise process`; wire `discoverCommands` in `boot()`.
- [ ] Run extension `composer test`/`analyse`/`phpcs` — green. Re-run core suite — still green (core unaffected). Commit.

**Rollback risk:** Medium — recreates the supervisor + IPC in the extension. Mitigated by porting the verified original verbatim and the spawn-argv test. Revert = remove `SuperviseCommand`, revert `ProcessFactory` shell string.

### Task 4e — [ATOMIC CORE REMOVAL] delete the now-duplicated ops classes + commands from core

The single, atomic **code/command** removal commit. Tasks 4b–4d copied every ops class/command into `glueful/queue-ops` and proved them green there while leaving the core originals **physically in place but unreferenced** (core wires only `WorkerMonitorInterface` + `NullWorkerMonitor` after WS3). This task deletes all those duplicated core sources together, so core goes from green (with the dormant ops files) to green (without them) in one step — there is no intermediate broken state, and at no point is core left importing a class that no longer exists.

> **Scope note — this is the *code/command* removal, not the whole core cleanup.** The ops **config** (`queue.workers.*` blocks + per-queue ops keys) is removed from core in a **second** intentional cleanup, **Task 5b** — it can't move until the ops classes that read it are gone. So core removal happens in **two staged breaking commits**: 4e (classes + `queue:autoscale` + the `queue:work` sub-actions) then 5b (ops config → `queue_ops.*`). Don't read "atomic" here as "all core removal is finished at 4e."

**Delete (core)**
- `src/Queue/Monitoring/WorkerMonitor.php` — the concrete monitor (copied in 4b). **Keep** `src/Queue/Monitoring/NullWorkerMonitor.php` and `src/Queue/Contracts/WorkerMonitorInterface.php` — those are the permanent core seam.
- `src/Queue/Process/` — all 7 files (`ProcessManager.php`, `ProcessFactory.php`, `WorkerProcess.php`, `AutoScaler.php`, `ScheduledScaler.php`, `ResourceMonitor.php`, `StreamingMonitor.php`), copied in 4c, and the now-empty `src/Queue/Process/` dir.
- `src/Console/Commands/Queue/AutoScaleCommand.php` — copied in 4c; so core's `ConsoleProvider` `#[AsCommand]` scan no longer auto-registers `queue:autoscale`.

**Docs ship IN this breaking commit (command-surface break) — staged series, part 1 of 2**
- `CHANGELOG.md` `[Unreleased]` Breaking Changes — record the **command-surface** break: the `queue:work` sub-actions (`work`(multi)/`spawn`/`scale`/`status`/`stop`/`restart`/`health`) and the `queue:autoscale` command are **removed/absent** (invoking them is command-not-found — **no** core stub prints an actionable message); plain `php glueful queue:work` is now a single lean worker; supervised fleets/autoscaling/worker-metrics require `composer require glueful/queue-ops` (restores `queue:supervise` + `queue:autoscale`). Also note the additive WS2 changes already shipped: new `--once`/`--connection` flags and `WorkerOptions` `0 = unlimited`.
- `UPGRADE.md` (or repo upgrade-notes location) — append the queue-ops command-surface section, and **flag this as a staged breaking series**: *commands/classes are removed here (4e); the ops **config** keys (`queue.workers.process/auto_scaling/...` + per-queue `workers`/`max_workers`/`auto_scale`) relocate to `glueful/queue-ops` in a follow-up commit (5b)* — so a reviewer reading commits individually sees the config move as the intended second step, not a leftover.
- `CLAUDE.md` — if its queue/worker section describes the supervised `queue:work`, update locally to the lean worker + `glueful/queue-ops` (**local only, never staged**, per repo convention).

**Steps**
- [ ] Confirm the WS3 decoupling grep over `src/` (excluding `docs/`/fixtures) is **zero** references to the concrete `Glueful\Queue\Monitoring\WorkerMonitor` and `Glueful\Queue\Process\*` **before** deleting — proving nothing core-side will break when the files vanish. Record the command + output.
- [ ] Delete the concrete `WorkerMonitor`, the 7 `Process/*` files (+ dir), and `AutoScaleCommand` from core.
- [ ] Write the `CHANGELOG.md` `[Unreleased]` command-surface entry + the `UPGRADE.md` section (staged-series note included).
- [ ] Run core `composer test` — green (core boots on `NullWorkerMonitor`; `queue:work` is the only worker entrypoint; `queue:autoscale` and the removed `queue:work` sub-actions are absent → command-not-found). Run `composer run analyse` (no dangling `Glueful\Queue\Process\*` / concrete-`WorkerMonitor` references) + `composer run phpcs` — clean.
- [ ] Commit (`feat(queue)!: remove ops tree from core (extracted to glueful/queue-ops)`) — explicitly `git add` the deleted source paths **+ `CHANGELOG.md` + the UPGRADE doc**; **do not stage `CLAUDE.md`**.

**Rollback risk:** High — this is a breaking commit (deletes core code, removes `queue:autoscale`, changes the command surface). Mitigated by Tasks 4b–4d having already proven the code works in the extension and by the WS3 gate guaranteeing no inbound core references. Revert = `git revert` the commit (restores all deleted files; the binding already points at `NullWorkerMonitor`, so the restored concrete is merely dormant again).

---

## WS5 — Own the ops schema + config in the extension

Ship real migrations for the worker/metrics tables and move the operational config blocks out of core `queue.php`.

### Task 5a — Migrations for `queue_workers` / `queue_job_metrics`

**Files**
- Create (extension): `migrations/NNN_CreateQueueWorkersTable.php`, `migrations/NNN_CreateQueueJobMetricsTable.php` — implement `MigrationInterface` against `SchemaBuilderInterface`, mirroring the columns/indexes lazily created in `WorkerMonitor::createWorkersTable()` (`:515-555`) and `createMetricsTable()` (`:562-596`) exactly (uuid/connection/queue/pid/hostname/timestamps/counters/memory/status/options for workers; job_uuid/job_class/queue/timestamps/processing_time/memory/status/attempts/error_* for metrics; same unique/index set). Working `down()` (drop tables).
- Modify (extension): `QueueOpsServiceProvider::register()` — `loadMigrationsFrom(__DIR__ . '/../migrations')`.
- Create (extension): `tests/.../OpsMigrationsTest.php`.

**Steps**
- [ ] (extension) Write a test: running the migrations on the SQLite harness creates `queue_workers` + `queue_job_metrics` with the expected columns; `down()` drops them; the migration is idempotent against the lazy-create (`hasTable` guard, R5). Run — fails.
- [ ] Add the two migrations + `loadMigrationsFrom`.
- [ ] Run extension `composer test`/`analyse`/`phpcs` — green. Commit.

**Rollback risk:** Low — additive migrations + retained lazy-create fallback.

### Task 5b — Split `config/queue.php`; ship `config/queue_ops.php`

**Files**
- Modify (core): `config/queue.php` — **remove** the `workers.process`, `workers.auto_scaling`, `workers.resource_limits`, `workers.resource_thresholds`, `workers.supervisor` blocks (`:194-221,295-365`); **remove** the per-queue `workers`, `max_workers`, `auto_scale` keys from each `workers.queues.<name>` entry (`:229-293`), **keeping** per-queue `priority`, `memory_limit`, `timeout`, `max_jobs`. **Keep** `connections`, `default`, `monitoring.*` (`:110-173`, alert-rule data the health layer reads, Decision §4), and `workers.performance.*` (`:345-351`, backoff — now consumed by the lean `QueueWorker`).
- Create (extension): `config/queue_ops.php` — the removed blocks (`process`/`auto_scaling`/`resource_limits`/`resource_thresholds`/`supervisor`) + a `queues.<name>.{workers,max_workers,auto_scale}` map. Read the same env vars unchanged (`QUEUE_AUTO_SCALING`, `QUEUE_SCALE_*`, `QUEUE_PROCESS_*`, `*_QUEUE_WORKERS`/`*_QUEUE_MAX_WORKERS`/`*_QUEUE_AUTO_SCALE`).
- Modify (extension): `QueueOpsServiceProvider::register()` — `mergeConfig('queue_ops', require __DIR__ . '/../config/queue_ops.php')`.
- Modify (extension): repoint `AutoScaleCommand`/`SuperviseCommand`/`ResourceMonitor`/`ProcessManager` config reads from `queue.workers.*` to `queue_ops.*` (e.g. `AutoScaleCommand:197,205-206`, `ResourceMonitor` thresholds, `ProcessManager` process config). The lean core `QueueWorker` reads per-queue `priority`/`memory_limit`/`timeout`/`max_jobs` and `queue.workers.performance.*` from core `queue.php`.
- Create (extension): `tests/.../OpsConfigMergeTest.php`; Modify (core) any test asserting on the removed `queue.workers.*` keys.

**Docs ship IN this commit (config-relocation break) — staged series, part 2 of 2**
- `CHANGELOG.md` `[Unreleased]` — add to the queue-ops breaking section: the ops **config** moved from core `queue.php` to the extension's `queue_ops.*` — specifically `queue.workers.{process,auto_scaling,resource_limits,resource_thresholds,supervisor}` and the per-queue `workers`/`max_workers`/`auto_scale` keys. **Stays in core:** per-queue `priority`/`memory_limit`/`timeout`/`max_jobs`, `queue.monitoring.*`, `queue.workers.performance.*`. The feeding env vars are unchanged but now read by `glueful/queue-ops`.
- `UPGRADE.md` — append the config-relocation step (apps that overrode those keys in a published `config/queue.php` move them to `queue_ops.*` once `glueful/queue-ops` is installed); reiterate this is the **second** stage of the staged breaking series (commands removed in 4e, config relocated here).
- `CLAUDE.md` — if it documents the moved queue config keys, update locally (**not staged**).

**Steps**
- [ ] Audit: `grep -rn "queue.workers.process\|queue.workers.auto_scaling\|queue.workers.resource\|queue.workers.supervisor\|workers.queues" src/ tests/` — confirm the only readers of the moving blocks are the moved ops classes/commands (already in the extension after WS4) and that nothing core-side reads them. Record findings.
- [ ] (extension) Write `OpsConfigMergeTest`: with `queue-ops` booted, `config('queue_ops.process.default_workers')` etc. resolve from the merged config; (core) write/adjust a test that `config('queue.workers.performance.backoff_base')` + per-queue `priority`/`memory_limit`/`timeout`/`max_jobs` still resolve in core. Run — fails.
- [ ] Edit core `config/queue.php` (remove the five blocks + the three per-queue keys); create extension `config/queue_ops.php`; wire `mergeConfig`; repoint ops-class config reads.
- [ ] Add the `CHANGELOG.md` config-relocation entry + `UPGRADE.md` step (staged-series part 2).
- [ ] Run core + extension `composer test`/`analyse`/`phpcs` — green. Commit — explicitly `git add` core `config/queue.php` **+ `CHANGELOG.md` + the UPGRADE doc** (not `CLAUDE.md`).

**Rollback risk:** Medium — config surface split (R6). Mitigated by the audit + the merge test + the documented split table in the spec. Revert = restore the blocks/keys in core `queue.php`, drop `queue_ops.php`.

---

## Cross-cutting

- **Sequencing (mixed extraction):** WS1–WS3 are **incremental in-place core refactors** (core green at every step — seam, lean worker, lean `queue:work`, null-bind). WS4–WS5 are **copy-first into `glueful/queue-ops`** (Tasks 4b–4d copy each ops class/command; core originals stay physically present but unreferenced). **Core removal is two staged breaking commits:** **Task 4e** atomically deletes the duplicated concrete **classes/commands**, then **Task 5b** relocates the ops **config** (it can't move until the classes that read it are gone). "Atomic" describes 4e's code/command deletion — it is **not** the end of all core removal; 5b is the intentional second cleanup. Never `git mv` ops classes out of core mid-series.
- **Verification gate per task:** `composer test` (targeted green), `composer run analyse` (no new PHPStan errors), `composer run phpcs`. The **WS3 commit is the hard gate**: full core suite green on a plain checkout with zero concrete-`WorkerMonitor`/`Process/*` references before any WS4 copy; that zero-reference state is exactly what makes the WS4 copy-first + Task 4e atomic removal non-breaking.
- **Decoupling grep (run at WS3b, re-confirm after each WS4 copy, and immediately before Task 4e's deletion):**
  `grep -rn "Queue\\\\Monitoring\\\\WorkerMonitor\|Queue\\\\Process\\\\" src/ --include='*.php'` (exclude `src/Queue/Process/` + `src/Queue/Monitoring/WorkerMonitor.php`, which exist-but-unreferenced until Task 4e) → expect zero hits in core consumers throughout WS4; after Task 4e the files themselves are gone.
- **Extension override test (R3):** assert last-provider-wins so `queue-ops`'s `WorkerMonitorInterface => WorkerMonitor` beats core's `=> NullWorkerMonitor` (4b).
- **`ServeCommand` (R4):** no code change. `ServeCommand:373-378` keeps shelling `queue:work --sleep=3`, which is now one lean worker (was implicitly 2 supervised). Note in upgrade docs only.
- **CHANGELOG / upgrade docs are owned task steps, not floating prose** — and they're **staged across the two breaking commits**: the **command-surface** break (removed `queue:work` sub-actions + `queue:autoscale`, new `--once`/`--connection`, `0 = unlimited`, `composer require glueful/queue-ops` to restore fleets/autoscaling/metrics) is written in **Task 4e**; the **config-relocation** break (`queue.workers.*`/per-queue ops keys → `queue_ops.*`) is written in **Task 5b**. Each ships its CHANGELOG `[Unreleased]` + `UPGRADE.md` entry **in its own commit** (with the staged-series note so commit-by-commit reviewers see 5b's config move as the intended second step). `CLAUDE.md` is updated locally only, never staged.
- **Upgrade notes:** carry the spec's "Upgrade notes" section verbatim into the migration guide (plain workers leaner; removed commands; `queue-ops` restores supervision; config moved; health endpoint stays up either way).

## Self-review (completed during planning)

- **Sequencing:** WS1 (seam) → WS2 (lean worker + lean `queue:work` + `0=unlimited`) → WS3 (null bind + decoupling gate) → WS4 (copy ops tree + supervise/autoscale into `queue-ops`, then **Task 4e atomic core removal**) → WS5 (migrations + config split). WS1–WS3 are in-place core refactors (core green each step); WS4 is copy-first with a single atomic removal at 4e; not uniformly copy-first (mixed extraction). Hard gate made explicit at WS3b before any copy.
- **`QueueWorker` 1:1 port:** start-ordering (`recordJobStart` before fire), `\Throwable` catch + non-`Exception` wrap (`:318`) reused for both `failed()` and `recordJobFailure()`, `0=unlimited` stop guards (`:332,337`), signal handling (`:275-282,349-351`), stdout IPC stripped (G5) — all with exact line refs.
- **Interface set:** exactly the trimmed 10 methods; reporting trio excluded and kept on the concrete (used by ops commands).
- **No shims:** removed commands are absent (generic command-not-found), no actionable stub (G4/G6).
- **Verification:** spec groups 1 (QueueWorker incl. start-ordering/`0=unlimited`/`--connection`), 2 (core-only decoupling), 3 (extension override + supervised leaf workers) are each mapped to concrete task steps.

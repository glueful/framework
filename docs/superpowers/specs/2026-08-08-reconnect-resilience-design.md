# Reconnect Resilience — Design

**Date:** 2026-08-08
**Status:** Approved design, pending spec review
**Roadmap:** item 4 of `docs/DATABASE_NATIVE_ROADMAP.md` — the final item; ships in the
combined roadmap release.

## Goal

Retry/reconnect primitives built on the typed database exceptions: automatic replay of
provably-uncommitted transactions at the outermost boundary, an explicit
idempotent-read primitive, and a lazy reconnect seam on `Connection` — under **one
shared, configurable retry budget** with an injectable clock. Never retry arbitrary
writes; never replay when the commit outcome is ambiguous.

## Non-goals

- Retrying arbitrary statements or writes outside the two sanctioned surfaces.
- Replaying transactions whose commit outcome is unknown (that is the express danger).
- Jitter, per-exception retry curves, or a general `RetryPolicy` object — `RetryBudget`
  exists only because mutable per-invocation state must be shared across two owners.
- Queue-worker or application-level integration beyond the primitives.
- Making `QueryBuilder::transaction()` reconnect-capable (documented limitation below).

## Components

Namespace `Glueful\Database\Resilience` (new):

| Unit | Responsibility |
|---|---|
| `SleeperInterface` | `sleepMilliseconds(int $ms): void` — the clock seam. |
| `UsleepSleeper` | Production implementation wrapping `usleep()`. Tests use a recording fake. |
| `RetryBudget` | Mutable per-invocation budget. `tryConsume(): bool` **atomically authorizes and delays**: when attempts remain it decrements, sleeps `backoff_base_ms × failed-attempt-number` via the Sleeper, and returns true; when exhausted it returns false and sleeps nothing (the historical sleep-after-terminal-failure is removed — documented correction). `max_attempts` counts **total executions including the first**; with defaults (3, 500) the delay sequence is exactly `[500, 1000]` ms. Tracks attempts used for log context. Constructor validates `max_attempts >= 1`, `backoff_base_ms >= 0` (0 = immediate retries). |

New exceptions in `Glueful\Database\Exceptions`:

- `CommitOutcomeUnknownException extends DatabaseException` — implements **no**
  transient marker. Thrown when a connection loss is detected during the level-1
  `PDO::commit()` call: the server may have committed before the acknowledgement was
  lost, so replay could duplicate writes. Carries the classified loss as `previous`.

## Configuration

`config/database.php`:

```php
'retry' => [
    'max_attempts' => (int) env('DB_RETRY_MAX_ATTEMPTS', 3),
    'backoff_base_ms' => (int) env('DB_RETRY_BACKOFF_MS', 500),
],
```

- Defaults reproduce today's observable behavior (three executions, 500/1000 ms
  delays), minus the terminal sleep.
- The config path rejects `max_attempts < 1` and `backoff_base_ms < 0` with a clear
  message.
- Documented as **distinct from** `pooling.retry_attempts` / `DB_POOL_RETRY_*`: pool
  retries concern acquiring connections; this budget concerns database-operation
  recovery.

## Ownership split — one budget, two consumers

The budget is created **once per outermost `Connection::transaction()` call** and
flows to every consumer of that invocation. Ownership is strict:

- **`TransactionManager` consumes retries only for
  `RetryableTransactionFailureInterface`** (deadlock, serialization failure, lock
  contention) — its existing loop, now driven by the budget and the Sleeper.
- **`Connection` consumes retries only for eligible `ConnectionLostException`s** —
  reconnect-and-replay at the outermost boundary, and `idempotentRead()` re-runs.
- A connection loss **passes straight through `TransactionManager`** without
  consuming the budget; `Connection` is the only consumer for that class.
- A **failed reconnect consumes the next shared attempt** like any recovery cycle.
- When the budget is exhausted, the **last classified exception is rethrown
  unchanged**.

### TransactionManager changes

`transaction(callable $callback, ?RetryBudget $budget = null)` (optional trailing
param on `TransactionManagerInterface` — interface-BC changelog note, same pattern as
the schema interfaces last release):

- `$budget === null` (direct use): **the `setMaxRetries(0)` zero-execution edge is
  preserved first** — if the legacy allowance is 0, the historical path (no attempts,
  the historical generic exhaustion exception) runs before any budget is constructed.
  Otherwise a local budget is built honoring `setMaxRetries()` and the configured
  backoff. `setMaxRetries()` remains supported even though internal terminology
  becomes "attempts".
- The retry branch fires only for `RetryableTransactionFailureInterface`; the sleep
  moves into `RetryBudget::tryConsume()` (no sleep after the final failure).
- The catch widens from `Exception` to **`\Throwable`** — an `Error` thrown by a
  callback now rolls back (guarded by `$began`) before propagating. Behavior delta,
  documented.
- **Commit-phase ambiguity:** in `commit()`, a level-1 `PDO::commit()` failure whose
  classification is `ConnectionLostException` throws `CommitOutcomeUnknownException`.
  On that path the manager **clears transaction bookkeeping and both callback
  collections** (after-commit and after-rollback) — neither callback outcome can be
  safely inferred — and sets the presumed-dead flag (below).
- **Post-commit callback safety:** the database commit is marked complete
  (`transactionLevel = 0`, internal commit-complete state) **before** after-commit
  callbacks run. Callback failures must be non-replayable: today
  `executeCallbacks()` logs-and-swallows callback throwables — the spec pins that
  contract with a regression test (a callback throwing a classified
  `ConnectionLostException` must NOT escape `commit()` and must NOT trigger replay).
  If implementation reveals any escape path, it must be wrapped so `Connection`'s
  replay catch cannot see it. Verified at plan time.
- **Rollback-failure precedence** (when `$this->rollback()` throws inside the catch
  path), exactly:
  1. Primary is `ConnectionLostException` → preserve the primary; log the secondary.
  2. Primary is non-retryable (anything else) and the rollback failure classifies as
     connection loss → preserve the primary, log the secondary, and **flag the
     connection presumed-dead** so `Connection` invalidates (not reconnects) it.
  3. Primary is retryable (`RetryableTransactionFailureInterface`) and the rollback
     failure classifies as connection loss → **surface the connection loss** instead
     of the retryable primary (log the primary): retrying inside the manager would
     reuse the dead PDO; `Connection` owns connection-loss recovery and its budget
     consumption, keeping single-consumption intact.
  - In every rollback-loss case the manager resets its transaction bookkeeping
    (`transactionLevel = 0`, callbacks discarded per the rules above) — the server
    rolls back on disconnect.
- **Presumed-dead signal:** a small internal flag with a public accessor (e.g.
  `connectionPresumedDead(): bool`) set on commit-unknown and rollback-loss paths.
  `Connection` reads it after any transaction attempt to decide invalidation.

### Connection changes

- **`transaction(callable $callback)`** — the outermost wrapper:
  - If `transactionLevel() > 0` **or an outer budget is already active**: delegate to
    the memoized manager **passing the active budget** (stored temporarily on the
    Connection for the duration of the outermost call and cleared in `finally`).
    Passing null on nested delegation would mint a second allowance — forbidden.
  - Outermost: create the budget from config; loop:
    `getTransactionManager()->transaction($callback, $budget)`.
    - Catch **`ConnectionLostException` only** (with the manager fully unwound,
      level 0): `tryConsume()` → `reconnect()` → memoized manager rebuilt (fresh PDO +
      fresh SavepointManager) → replay, same budget instance. Reconnect failure that
      classifies as connection loss consumes the next attempt and loops.
    - `CommitOutcomeUnknownException` is **never caught for replay**: `Connection`
      **invalidates** the dead PDO and manager (below) and lets the exception
      propagate unmasked. A later operation reconnects lazily.
    - After any attempt, if the manager reports presumed-dead, invalidate.
- **`idempotentRead(callable $fn): mixed`** — the explicit idempotent-read
  primitive: refuses inside a transaction (a plain `\LogicException` with a clear
  message — this is caller misuse, not a database failure); runs `$fn($this)`; on
  `ConnectionLostException` consumes its own per-call budget (same config) →
  `reconnect()` → re-run. The caller's name-level declaration of idempotency is the
  contract; nothing is inspected.
- **`reconnect(): void`** — refuses while `inTransaction()`. Establishes a fresh
  connection immediately (invalidate + connect).
- **`invalidate()`** (internal; name at implementer's discretion) — drops the dead
  handle WITHOUT connecting: clears the instance PDO reference and memoized manager,
  purges the static share, discards the pooled handle. Used by the
  commit-unknown/presumed-dead paths; `reconnect()` = invalidate + establish.

### Reconnect mechanics (corrected)

- **One canonical connection key.** The constructor caches shared PDOs by full
  identity (`engine|dsn|user|schema`) while the lazy `getPDO()` fallback uses
  **engine-only** keys — a real inconsistency. A single `connectionKey(): string`
  (full identity) is introduced and used by the constructor cache, the lazy fallback,
  and the purge. SQLite remains excluded from sharing.
- **Purging the static entry protects future borrowers only.** Other `Connection`
  objects already holding the dead PDO recover independently when they hit their own
  connection loss — documented, not "fixed" (there is no registry of holders, and
  inventing one is out of scope).
- **Pooling: explicit discard path.** Normal `ConnectionPool::release()` performs
  rollback/session-reset work against the handle — wrong on a known-dead connection.
  A discard path (`markDead()`/`discard()`-style, exact seam per the pool's existing
  health machinery) retires the handle without touching it; `Connection` then
  reacquires lazily on next `getPDO()`.

## Replay availability — stated limits

Reconnect replay is available through **`Connection::transaction()` and
`Connection::idempotentRead()` only.** `QueryBuilder::transaction()` delegates to its
captured manager/PDO and cannot safely reconnect — documented, unchanged. Replay
callbacks must build query chains **inside the callback from the supplied
`Connection`** (fresh `table()` chains bind the current PDO); prebuilt builders or
captured `QueryExecutor`s retain the stale PDO and will fail on replay. This guidance
appears in the changelog and the method docblocks.

## Phase-safety table

| Loss detected during | Outcome |
|---|---|
| `begin()` (incl. reconnect-then-begin) | Replay-eligible — nothing started. |
| Callback / statement execution | Replay-eligible — server rolls back on disconnect. |
| Level-1 `PDO::commit()` | `CommitOutcomeUnknownException` — invalidate, never replay, propagate. |
| After-commit callbacks (commit already durable) | Never replayable; callback failures log-and-swallow (pinned by test). |
| `rollback()` during failure handling | Precedence rules above; original preserved except the retryable-primary case, which surfaces the loss to `Connection`. |

## Observability

`QueryLogger::logEvent` entries with attempt numbers and delays: connection-loss
detected (phase), reconnect attempt/success/failure, transaction replay started,
budget exhausted, commit-outcome-unknown surfaced, presumed-dead invalidation,
rollback-failure secondary (with preserved primary). No new logging infrastructure —
the existing event channel.

## Behavior deltas (documented in CHANGELOG Upgrade Notes)

1. Outermost `Connection::transaction()` now replays provably-uncommitted work after
   a connection loss (same replay contract deadlocks always had); nothing replays on
   commit-phase loss — that surfaces as the new `CommitOutcomeUnknownException`
   (previously a raw `ConnectionLostException`).
2. `transaction()` catches `\Throwable`: an `Error` from a callback now rolls back
   before propagating (previously left the transaction open).
3. No sleep after the final failed attempt (was: ~1.5 s wasted at defaults).
4. New config keys `DB_RETRY_MAX_ATTEMPTS` / `DB_RETRY_BACKOFF_MS`, distinct from the
   pool's `DB_POOL_RETRY_*`.
5. `TransactionManagerInterface::transaction()` gains an optional `?RetryBudget`
   param — interface-BC note for external implementors.
6. Retryable-failure + rollback-connection-loss now surfaces the connection loss
   (previously the retryable primary escaped after a masked secondary, or worse,
   retried on the dead handle).

## Testing

- **Budget/backoff:** recording FakeSleeper asserts the exact `[500, 1000]` sequence
  at defaults, zero terminal sleep, `backoff_base_ms = 0` sleeps nothing, config
  validation rejects bad values; shared-counter test: one deadlock consumption + one
  connection-loss consumption exhaust a 3-attempt budget together (no reset on
  exception-type change).
- **Nesting:** nested `Connection::transaction()` calls reuse the active budget (no
  allowance multiplication — asserted by counting total consumptions).
- **Replay:** fault-injected PDO (loss during callback) → reconnected, replayed,
  succeeds; loss during `begin()` → replayed; repeated loss → budget exhausts and
  the last classified exception is rethrown unchanged; failed reconnect consumes an
  attempt.
- **Commit ambiguity:** fault-injected loss during level-1 `commit()` →
  `CommitOutcomeUnknownException`, zero replays, both callback collections cleared,
  manager bookkeeping reset, `Connection` invalidated (next operation lazily
  reconnects), exception propagates unmasked.
- **Post-commit callbacks:** a callback throwing classified loss after a durable
  commit does not escape `commit()` and triggers no replay (pinned contract).
- **Rollback precedence:** all three rules exercised with fault-injecting
  PDO/savepoint doubles; the retryable-primary case proves the loss surfaces and the
  manager did NOT retry on the dead handle.
- **Reconnect mechanics:** canonical-key consistency (constructor and fallback hit
  the same entry), purge protects future borrowers, pooled discard path never runs
  release-side session-reset on the dead handle, memoized manager rebuilt, fresh
  `table()` chains bind the new PDO.
- **idempotentRead:** replays on loss, refuses in-transaction, returns the
  callback's value.
- **Direct-use BC:** `setMaxRetries(0)` still yields zero executions and the
  historical exception; `setMaxRetries(n)` still governs when no budget is passed.
- **Throwable:** an `Error` from a callback rolls back (level 0 after) and
  propagates.

## Bookkeeping

- CHANGELOG `[Unreleased]`: Added (Resilience namespace, `CommitOutcomeUnknownException`,
  config block, `Connection::idempotentRead()`/`reconnect()`), Changed (replay
  contract, Throwable, terminal sleep, interface param), Upgrade Notes (deltas above,
  incl. the replay-callback chain-building guidance).
- `docs/DATABASE_NATIVE_ROADMAP.md`: item 4 marked done; roadmap complete.
- Skeleton `.env.example` parity for the two new env keys at release time.

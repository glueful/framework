# Database Layer: Native Roadmap

> **Decision (2026-08): improve Glueful's own database layer; do not adopt doctrine/dbal.**
> This document records why, and the improvement sequence that replaces what DBAL
> adoption promised — without the dependency or the transition risk.

## Why DBAL was declined

An external analysis recommended adopting `doctrine/dbal` beneath Glueful's public API
(for platforms, types, schema introspection, and comparison). Evaluated and declined:

- **The maintenance-cost argument was stale.** The level-8 static-analysis campaign and
  the 2026-06 security-review fixes had just hardened this exact layer; behavior is
  pinned by the full test suite. The layer is in the best shape it has ever been.
- **DBAL's schema abstraction fits Glueful worst.** Glueful's builder is table-driven,
  and the flagship consumer (Thallo) is deliberately PostgreSQL-only, using JSONB,
  CHECK constraints, and expression indexes that DBAL's lowest-common-denominator
  schema API expresses poorly. Laravel used DBAL for exactly this (column modification,
  introspection) and **removed it in Laravel 10**, reimplementing natively, because
  round-tripping schema state through DBAL's platform abstraction lost vendor-specific
  attributes.
- **Connection reuse is not clean.** DBAL 3/4 cannot wrap an existing PDO instance
  without a custom driver. Glueful's pooling, query interceptors, tenancy hooks, and
  purpose tracking live on its own connection lifecycle; a parallel DBAL connection
  risks schema operations and app queries running in different transaction contexts.
- **The size of the win was overstated.** DBAL would replace only the bottom slice
  (drivers, platform detection, the three SQL generators) — the query builder, ORM,
  caching, logging, migrations, and repositories stay Glueful's either way.

Doctrine ORM was never a candidate for core (Data Mapper vs Glueful's Active Record).
A `glueful/doctrine` extension (optional EntityManager integration) remains possible
at any time without core changes.

## The native roadmap

In sequence. Items 1–2 are roughly a week combined and deliver most of what DBAL
adoption promised, with zero new dependencies. **Items 1–4 are complete; item 5
remains deliberately deferred.**

### 1. Typed database exceptions — DONE (see CHANGELOG [Unreleased])

Shipped as `Glueful\Database\Exceptions`: constraint (`ConstraintViolationException`
parent with `UniqueConstraintViolationException`,
`ForeignKeyConstraintViolationException`, `NotNullConstraintViolationException`),
`DeadlockException`, `SerializationFailureException`, `LockContentionException`, and
`ConnectionLostException`, classified from **SQLSTATE plus vendor codes** (vendor-first)
at the execution boundary by a stateless `ExceptionClassifier`. Timeout and
syntax/schema families were deliberately not shipped — a syntax error is a programming
bug and falls to the generic `DatabaseException`; lock-wait timeouts are covered by
`LockContentionException`. Design record:
`docs/superpowers/specs/2026-08-07-typed-database-exceptions-design.md`.

Value delivered: clean 409-conflict handling for unique violations, marker-driven
retry-on-deadlock, no more string-matching driver messages.

### 2. Unify retry classification — DONE (shipped with item 1)

`TransactionManager::isDeadlock()` (`src/Database/Transaction/TransactionManager.php:239`)
compares `['1213', '1205', '40001']` against `Exception::getCode()` — a list that mixes
MySQL driver codes with SQLSTATEs, does not distinguish PostgreSQL `40P01`
(deadlock_detected) from `40001` (serialization_failure), and has no SQLite
`SQLITE_BUSY` / `SQLITE_LOCKED` coverage. Route this through the new exception
classifier so MySQL / PostgreSQL / SQLite behavior is explicit and testable per driver.

### 3. Complete SQLite rebuild operations — DONE (see CHANGELOG [Unreleased])

Implemented the documented create-copy-swap sequence for alterations SQLite cannot
express natively: modify column, drop column, add/drop foreign key, and drop inline
unique constraint. The rebuild is audited before any DDL runs (fails closed on generated
columns, COLLATE, composite foreign keys, expression uniques, indexes/triggers/views
referencing changed columns, journal_mode=OFF, or an open transaction), atomic (own
transaction or savepoint; global foreign_key_check before mutation and commit; state
restoration and verification), and verified (re-introspected and canonically compared
against the planned target). Six formerly silent alteration paths now take real effect
or throw before mutation. Design record:
`docs/superpowers/specs/2026-08-07-sqlite-alteration-correctness-design.md`.

### 4. Reconnect resilience — DONE (see CHANGELOG [Unreleased])

Shipped as `Glueful\Database\Resilience`: retry/reconnect primitives built on the typed
exceptions. `Connection::transaction()` replays callbacks after connection loss during
begin/callback phases (proven safe; server rolls back on disconnect); connection loss
during COMMIT surfaces as non-retryable `CommitOutcomeUnknownException` (server may have
committed despite acknowledgement loss). New `Connection::idempotentRead(callable)`
re-runs caller-declared idempotent reads after reconnecting. All failures (deadlock,
serialization, lock contention, eligible connection loss) share one retry budget
(`DB_RETRY_MAX_ATTEMPTS`/`DB_RETRY_BACKOFF_MS`, default 3 attempts / 500 ms backoff).
Design record: `docs/superpowers/specs/2026-08-08-reconnect-resilience-design.md`.

### 5. Defer schema introspection/diffing

Only if a product feature needs it (schema-drift detection, `migrate:diff`-style
tooling). This is the one DBAL capability with no cheap native equivalent, but it is
speculative until something needs it.

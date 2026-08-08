# Typed Database Exceptions — Design

**Date:** 2026-08-07
**Status:** Approved design, implementation-ready
**Roadmap:** items 1–2 of `docs/DATABASE_NATIVE_ROADMAP.md` (typed exceptions + retry-classification unification)

## Goal

Classify database failures into a typed exception hierarchy at the execution boundary so
consumers make type-safe decisions (409 on conflict, retry on deadlock) instead of
string-matching driver messages. Connect the taxonomy to the two existing policies that
need it — `TransactionManager` retry recognition and HTTP rendering — **without creating
any new recovery behavior**.

## Non-goals (out of scope)

- Reconnecting dropped connections; retrying idempotent reads; retry configuration;
  changing retry counts or backoff; replaying transactions after connection loss.
- Schema-builder changes; application-level validation derived from constraints.
- Removing or redesigning the HTTP-domain `Glueful\Http\Exceptions\Domain\DatabaseException`.
- Enabling SQLite extended result codes globally (recorded as a separate future
  compatibility decision — see SQLite section).
- Message-based classification (fragile across versions/locales; not used).

## Public contract

Namespace: `Glueful\Database\Exceptions`.

```php
interface DatabaseExceptionInterface extends \Throwable
{
    public function sqlState(): ?string;
    public function driverCode(): int|string|null;
    public function driver(): string;
}

interface TransientFailureInterface extends DatabaseExceptionInterface {}
interface RetryableTransactionFailureInterface extends TransientFailureInterface {}
```

Class hierarchy — root extends `\PDOException` so every existing
`catch (\PDOException)` in framework, extensions, and applications keeps working:

```
DatabaseException                        extends \PDOException implements DatabaseExceptionInterface
│                                        (generic fallback for unclassified failures)
├── ConstraintViolationException         (intermediate; unmatched 23xxx and ambiguous
│   │                                     SQLite constraint code 19 land here)
│   ├── UniqueConstraintViolationException      final
│   ├── ForeignKeyConstraintViolationException  final
│   └── NotNullConstraintViolationException     final
├── DeadlockException                    final, implements RetryableTransactionFailureInterface
├── SerializationFailureException        final, implements RetryableTransactionFailureInterface
├── LockContentionException              final, implements RetryableTransactionFailureInterface
│                                        (MySQL 1205 lock-wait timeout, PostgreSQL 55P03
│                                         lock_not_available, SQLite BUSY/LOCKED — named for
│                                         contention, not timeout, since PG NOWAIT and SQLite
│                                         BUSY occur without any timeout)
└── ConnectionLostException              final, implements TransientFailureInterface only
                                         (NOT transaction-retryable: replaying a transaction
                                          on a dead connection requires reconnect + state
                                          handling this slice does not provide)
```

### Construction and data preservation

Each class provides `public static function fromPdo(\PDOException $e, string $driver): static`.
Inheritance alone does not copy PDO exception state; the factory must preserve:

- the exact original message;
- the original `getCode()` **including string SQLSTATE values** (assigned via the
  protected `$code` property — `\Exception`'s constructor only accepts int);
- the public `$errorInfo` array, copied verbatim;
- the original `PDOException` chained as `$previous`;
- parsed `sqlState`, `driverCode`, and `driver` for the interface accessors.

Contract assertions (used verbatim in tests):

```php
$this->assertInstanceOf(DatabaseExceptionInterface::class, $exception);
$this->assertInstanceOf(\PDOException::class, $exception);
$this->assertSame($original->getMessage(), $exception->getMessage());
$this->assertSame($original->getCode(), $exception->getCode());
$this->assertSame($original->errorInfo, $exception->errorInfo);
$this->assertSame($original, $exception->getPrevious());
```

## ExceptionClassifier

One `final`, stateless class: `classify(\PDOException $e, string $driver): DatabaseException`.

### Precedence — specificity first

Driver-specific vendor codes are checked **before** the exact-SQLSTATE map. This is
load-bearing: MySQL reports deadlock 1213 with SQLSTATE `40001`, so an
exact-SQLSTATE-first order would misclassify MySQL deadlocks as
`SerializationFailureException`. It also naturally handles MySQL's ambiguous generic
`23000` (used for both unique and FK violations) and SQLite's generic code 19.

1. **Already classified** — `$e instanceof DatabaseException` → return unchanged
   (preserves identity and stack; prevents double-classification).
2. **Extract** SQLSTATE from `errorInfo[0]`, falling back to `getCode()` only when it
   resembles a SQLSTATE (5 alphanumeric chars); extract vendor code from `errorInfo[1]`.
3. **Driver-specific vendor-code map** (see below).
4. **Exact SQLSTATE map** (unambiguous codes only — `23000` deliberately excluded):

   | SQLSTATE | Class |
   |---|---|
   | `23505` | UniqueConstraintViolationException |
   | `23503` | ForeignKeyConstraintViolationException |
   | `23502` | NotNullConstraintViolationException |
   | `40001` | SerializationFailureException |
   | `40P01` | DeadlockException |
   | `55P03` | LockContentionException |
   | `57P01`, `57P02`, `57P03` | ConnectionLostException |

5. **SQLSTATE class-prefix families**: `23xxx` → ConstraintViolationException,
   `08xxx` → ConnectionLostException.
6. **Generic** `DatabaseException`.

### Vendor-code maps

```php
'mysql' => [
    1062 => UniqueConstraintViolationException::class,
    1451 => ForeignKeyConstraintViolationException::class,
    1452 => ForeignKeyConstraintViolationException::class,
    1048 => NotNullConstraintViolationException::class,
    1213 => DeadlockException::class,
    1205 => LockContentionException::class,
    2006 => ConnectionLostException::class,   // server has gone away
    2013 => ConnectionLostException::class,   // lost connection during query
],
'sqlite' => [
    5    => LockContentionException::class,   // SQLITE_BUSY
    6    => LockContentionException::class,   // SQLITE_LOCKED
    // Extended result codes — honored WHEN supplied (see SQLite section):
    2067 => UniqueConstraintViolationException::class,   // SQLITE_CONSTRAINT_UNIQUE
    1555 => UniqueConstraintViolationException::class,   // SQLITE_CONSTRAINT_PRIMARYKEY
    1299 => NotNullConstraintViolationException::class,  // SQLITE_CONSTRAINT_NOTNULL
    787  => ForeignKeyConstraintViolationException::class, // SQLITE_CONSTRAINT_FOREIGNKEY
    19   => ConstraintViolationException::class,         // bare SQLITE_CONSTRAINT (ambiguous)
],
'pgsql' => [
    // PostgreSQL SQLSTATEs are specific; the exact-SQLSTATE map suffices.
],
```

No message matching in the primary classifier. If an unavoidable driver case ever
requires it, it must be isolated and documented as a final fallback — none is needed in
this slice.

### SQLite compatibility decision

Verified behavior under current PDO configuration: unique, not-null, and FK violations
are indistinguishable without message parsing — all produce
`getCode() === '23000'`, `errorInfo === ['23000', 19, ...]`. Enabling
`PDO::SQLITE_ATTR_EXTENDED_RESULT_CODES` exposes distinguishing codes (2067/1299/787)
**but changes SQLSTATE from `23000` to `HY000`**, which could affect code inspecting raw
exception codes. Decision for this slice:

- Do **not** enable extended-result mode globally.
- The classifier honors extended codes when they are supplied (map entries above), so an
  application that opts in gets specific types.
- Under default configuration, SQLite code 19 classifies as the generic
  `ConstraintViolationException`, and the SQLite integration tests expect exactly that.
- Enabling extended-result mode framework-wide is recorded as a separate, deliberate
  future compatibility decision.

## Integration points

### 1. QueryExecutor (`src/Database/Execution/QueryExecutor.php` ~line 259)

The existing `catch (PDOException $e)` classifies and throws the typed exception.
`QueryExecutor::getDriverName()` already exists (line 350) — no constructor change.

### 2. TransactionManager (`src/Database/Transaction/TransactionManager.php`)

- **No code knowledge.** `isDeadlock()` and its mixed code list are removed; the check
  becomes `$e instanceof RetryableTransactionFailureInterface`.
- **Classify every transaction-control boundary.** Public `begin()`/`commit()`/`rollback()`
  wrap their direct PDO and savepoint-manager operations and classify any raw
  `PDOException` before rethrowing. This ensures direct callers receive the same typed
  surface as callers using `transaction()`. The retry loop retains a defensive fallback:
  an already-typed `DatabaseException` passes through unchanged, while any raw
  `PDOException` thrown by a callback is classified before the marker check. Non-PDO
  exceptions roll back and rethrow untouched, as today.
- **Driver without a required constructor change.** Optional trailing parameter with an
  internal fallback, so existing direct construction keeps working:

  ```php
  public function __construct(
      PDO $pdo,
      SavepointManagerInterface $savepointManager,
      QueryLogger $logger,
      ?string $driver = null,
  ) {
      $this->driver = $driver ?? (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
  }
  ```

- **`begin()` moves inside the retry `try`.** Today `$this->begin()` sits outside the
  `try`, so a lock-contention failure during transaction acquisition (SQLite BUSY on
  BEGIN) can never retry and escapes with no cleanup. It moves inside, with rollback
  guarded so it only runs when a transaction actually started (begin throwing before
  `beginTransaction()` succeeds must not trigger a rollback at level 0).
- **The final failure is preserved.** Today, exhaustion throws
  `new Exception("Transaction failed after {$this->maxRetries} retries due to deadlock.")`,
  discarding SQLSTATE, `errorInfo`, the marker interface, and the chain exactly when the
  caller most needs them. The loop keeps `$lastFailure` and rethrows it after
  exhaustion. The exhaustion log line remains. `setMaxRetries(0)` is already valid and
  produces zero attempts, so `$lastFailure` can legitimately remain `null`; in that
  compatibility edge case only, the existing generic exhaustion exception is retained:

  ```php
  if ($lastFailure !== null) {
      throw $lastFailure;
  }

  throw new \Exception(
      "Transaction failed after {$this->maxRetries} retries due to deadlock."
  );
  ```
- **Everything else is untouched:** retry count semantics (`maxRetries` attempts),
  progressive backoff (`usleep(500000 * $retryCount)`), logging calls, savepoint
  behavior, `afterCommit` callbacks.

### 3. HTTP handler (`src/Http/Exceptions/Handler.php`)

- `UniqueConstraintViolationException` gets a **dedicated renderer** returning HTTP 409
  with the fixed message "A conflicting record already exists." — fixed in debug mode
  too (the generic renderer exposes exception messages when debug is enabled; a unique
  violation must not leak table/column/value detail through that path).
- `UniqueConstraintViolationException` joins the handler's `$dontReport` list — an
  expected 409 is not error noise.
- Typed database failures must resolve to the database log channel **before** the
  handler's generic framework-origin check. Today `resolveLogChannel()` calls
  `isFrameworkException()` before consulting `$channelMap`; because typed exceptions are
  constructed in framework source, merely adding the base class to `$channelMap` would
  incorrectly route them to `framework`. Add the targeted first check below instead of
  globally reordering channel resolution:

  ```php
  if ($e instanceof DatabaseExceptionInterface) {
      return 'database';
  }
  ```

  The handler imports both the existing HTTP-domain `DatabaseException` and the new
  database-layer class, so their imports must be explicitly aliased (for example,
  `HttpDatabaseException` and `TypedDatabaseException`) wherever both basenames appear.
- **Every other typed database exception keeps the existing sanitized-500 path.** FK
  violations are semantically ambiguous at the HTTP layer (409 on delete, 422 on
  insert), so no mapping — per the "unambiguous only" rule.
- The HTTP-domain `DatabaseException` class is not modified.

### Unchanged consumers

`BaseRepository`, `ConnectionTester`, `DatabaseLogHandler`, `HealthService`, and every
extension/application `catch (\PDOException)` site continue to work — classified
exceptions still are `PDOException`s. `ConnectionPoolException` and connection
*establishment* failures are untouched; `ConnectionLostException` covers loss detected
during statement execution or transaction control only.

## Explicit behavior deltas

Everything else is behavior-preserving; these change deliberately:

1. PostgreSQL `40P01` (deadlock_detected) becomes transaction-retryable — it is missing
   from today's list, a live bug.
2. SQLite `SQLITE_BUSY`/`SQLITE_LOCKED` become transaction-retryable (previously never
   retried), including during `begin()`.
3. MySQL 1205 remains retryable but is now truthfully typed `LockContentionException`
   rather than pretending to be a deadlock.
4. After retry exhaustion, callers receive the final typed failure instead of a generic
   `Exception` with a fixed message (callers matching that message text — none found
   in-repo — would need the typed exception instead).
5. Failures classified as `UniqueConstraintViolationException` surface as HTTP 409
   (previously 500) with a fixed message and are no longer reported to the error log.
   Under the default SQLite configuration, ambiguous constraint code 19 remains a
   generic `ConstraintViolationException` and therefore remains HTTP 500.

## Testing

- **Classifier unit tests** — synthetic `PDOException`s carrying real per-driver
  `errorInfo` tuples: every mapped code for all three drivers; MySQL 1213-with-40001
  proves vendor-first precedence; MySQL generic `23000` resolves via vendor codes;
  SQLite `['23000', 19]` → `ConstraintViolationException`; SQLite extended codes →
  specific types; `08xxx`/`23xxx` family fallbacks; unknown codes → generic
  `DatabaseException`; already-classified passthrough returns the same instance.
- **Data-preservation tests** — the six contract assertions, per class.
- **TransactionManager tests** — a classified `DeadlockException` retries with existing
  backoff; `ConnectionLostException` does not retry; exhaustion rethrows the last typed
  failure (`assertSame`); `maxRetries === 0` retains the existing generic exhaustion
  exception; begin-time lock contention retries; a non-PDO exception rolls back and
  rethrows unclassified; rollback is not attempted when `begin()` itself fails; direct
  calls to `begin()`/`commit()`/`rollback()` classify their own raw PDO failures; a raw
  PDO exception thrown by a transaction callback is classified by the loop's defensive
  fallback.
- **Integration tests** — provoke real unique/FK/not-null violations on SQLite in-suite
  (expecting `ConstraintViolationException` under default configuration, per the SQLite
  decision); MySQL/PostgreSQL equivalents behind the existing environment guards
  (expecting the specific classes).
- **HTTP handler tests** — unique violation renders 409 with the fixed message in both
  debug and non-debug modes and is not reported; a generic `DatabaseException` still
  renders a sanitized 500 and logs to the database channel even though its construction
  site is inside framework source; default SQLite's generic constraint violation remains
  a sanitized 500.

## Bookkeeping

- CHANGELOG `[Unreleased]`: Added (hierarchy + classifier), Changed (TransactionManager
  recognition + exhaustion behavior, HTTP 409), Fixed (`40P01` never retried).
- `docs/DATABASE_NATIVE_ROADMAP.md`: mark items 1–2 in progress/done as they land.

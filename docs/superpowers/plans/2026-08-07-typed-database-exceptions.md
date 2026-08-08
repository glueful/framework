# Typed Database Exceptions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Classify database failures into a typed `\PDOException`-rooted hierarchy at the execution boundary, and connect it to `TransactionManager` retry recognition and HTTP rendering — with zero new recovery behavior.

**Architecture:** New `Glueful\Database\Exceptions` namespace: three interfaces, a `DatabaseException` base extending `\PDOException` (so every existing `catch (\PDOException)` keeps working), eight subclasses (including the intermediate `ConstraintViolationException`), and a stateless `ExceptionClassifier` with vendor-code-first precedence. Three integration points: `QueryExecutor`'s catch, `TransactionManager`'s boundaries and retry loop, and the HTTP `Handler` (409 renderer, `dontReport`, log-channel first-check).

**Tech Stack:** PHP 8.3, PDO, PHPUnit 10 attributes, PHPStan level 8 (`phpVersion: 80300`, no baseline), PSR-12.

**Spec:** `docs/superpowers/specs/2026-08-07-typed-database-exceptions-design.md` — read it before starting; it is the authority on precedence, maps, and behavior deltas.

## Global Constraints

- PHP floor 8.3; no new composer dependencies.
- PHPStan level 8, `phpVersion: 80300`, **no baseline** — new findings are fixed at the source, never suppressed (rare justified inline `@phpstan-ignore identifier (reason)` only).
- PSR-12 via phpcs, 120-column limit.
- Gates before every commit (CI-equivalent, full-tree — never per-file subsets):
  `vendor/bin/phpunit` && `composer run phpcs` && `vendor/bin/phpstan clear-result-cache && composer run analyse`.
- Work on `dev`; **never push**; no AI/Claude/Anthropic attribution in commits.
- Stage exact file paths only; never stage `CLAUDE.md`; spec + plan documents stay uncommitted.
- Commits are batched at the three checkpoints marked below, not per task.
- No message-based classification anywhere.
- Preserve `TransactionManager` retry count, backoff (`usleep(500000 * $retryCount)`), logging calls, and callback semantics exactly; the only behavior deltas allowed are the five listed in the spec.
- Exact fixed 409 message: `A conflicting record already exists.`

---

### Task 1: Exception hierarchy

**Files:**
- Create: `src/Database/Exceptions/DatabaseExceptionInterface.php`
- Create: `src/Database/Exceptions/TransientFailureInterface.php`
- Create: `src/Database/Exceptions/RetryableTransactionFailureInterface.php`
- Create: `src/Database/Exceptions/DatabaseException.php`
- Create: `src/Database/Exceptions/ConstraintViolationException.php`
- Create: `src/Database/Exceptions/UniqueConstraintViolationException.php`
- Create: `src/Database/Exceptions/ForeignKeyConstraintViolationException.php`
- Create: `src/Database/Exceptions/NotNullConstraintViolationException.php`
- Create: `src/Database/Exceptions/DeadlockException.php`
- Create: `src/Database/Exceptions/SerializationFailureException.php`
- Create: `src/Database/Exceptions/LockContentionException.php`
- Create: `src/Database/Exceptions/ConnectionLostException.php`
- Test: `tests/Unit/Database/Exceptions/DatabaseExceptionTest.php`

**Interfaces:**
- Consumes: nothing (leaf task).
- Produces: `DatabaseException::fromPdo(\PDOException $e, string $driver): static`;
  `DatabaseException::extractSqlState(\PDOException $e): ?string`;
  `DatabaseException::extractDriverCode(\PDOException $e): int|string|null`;
  accessors `sqlState(): ?string`, `driverCode(): int|string|null`, `driver(): string`;
  the class names and marker interfaces exactly as listed above. Task 2's classifier and
  Tasks 3–5 depend on these names verbatim.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Database/Exceptions/DatabaseExceptionTest.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\Exceptions;

use Glueful\Database\Exceptions\ConnectionLostException;
use Glueful\Database\Exceptions\ConstraintViolationException;
use Glueful\Database\Exceptions\DatabaseException;
use Glueful\Database\Exceptions\DatabaseExceptionInterface;
use Glueful\Database\Exceptions\DeadlockException;
use Glueful\Database\Exceptions\ForeignKeyConstraintViolationException;
use Glueful\Database\Exceptions\LockContentionException;
use Glueful\Database\Exceptions\NotNullConstraintViolationException;
use Glueful\Database\Exceptions\RetryableTransactionFailureInterface;
use Glueful\Database\Exceptions\SerializationFailureException;
use Glueful\Database\Exceptions\TransientFailureInterface;
use Glueful\Database\Exceptions\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DatabaseExceptionTest extends TestCase
{
    /**
     * Build a synthetic PDOException carrying real driver error shapes.
     * PDO sets string SQLSTATE codes on the protected $code property, which
     * plain construction cannot. An anonymous PDOException subclass can assign
     * that inherited protected property without trying to bind a closure to the
     * internal \Exception class scope (which PHP rejects).
     *
     * @param array{0: string, 1?: int|string|null, 2?: string}|null $errorInfo
     */
    private function pdoException(
        string $message,
        ?array $errorInfo,
        int|string|null $code = null
    ): \PDOException {
        return new class ($message, $errorInfo, $code) extends \PDOException {
            /**
             * @param array{0: string, 1?: int|string|null, 2?: string}|null $errorInfo
             */
            public function __construct(
                string $message,
                ?array $errorInfo,
                int|string|null $code
            ) {
                parent::__construct($message);
                $this->errorInfo = $errorInfo;

                if ($code !== null) {
                    $this->code = $code;
                }
            }
        };
    }

    /**
     * @return iterable<string, array{class: class-string<DatabaseException>}>
     */
    public static function concreteExceptionClasses(): iterable
    {
        yield 'generic database failure' => ['class' => DatabaseException::class];
        yield 'generic constraint violation' => ['class' => ConstraintViolationException::class];
        yield 'unique constraint violation' => ['class' => UniqueConstraintViolationException::class];
        yield 'foreign-key constraint violation' => [
            'class' => ForeignKeyConstraintViolationException::class,
        ];
        yield 'not-null constraint violation' => ['class' => NotNullConstraintViolationException::class];
        yield 'deadlock' => ['class' => DeadlockException::class];
        yield 'serialization failure' => ['class' => SerializationFailureException::class];
        yield 'lock contention' => ['class' => LockContentionException::class];
        yield 'connection lost' => ['class' => ConnectionLostException::class];
    }

    /** @param class-string<DatabaseException> $class */
    #[Test]
    #[DataProvider('concreteExceptionClasses')]
    public function fromPdoPreservesAllOriginalState(string $class): void
    {
        $original = $this->pdoException(
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'a@b.c'",
            ['23000', 1062, "Duplicate entry 'a@b.c' for key 'users.email'"],
            '23000'
        );

        $exception = $class::fromPdo($original, 'mysql');

        $this->assertSame($class, get_class($exception));
        $this->assertInstanceOf(DatabaseExceptionInterface::class, $exception);
        $this->assertInstanceOf(\PDOException::class, $exception);
        $this->assertSame($original->getMessage(), $exception->getMessage());
        $this->assertSame($original->getCode(), $exception->getCode());
        $this->assertSame($original->errorInfo, $exception->errorInfo);
        $this->assertSame($original, $exception->getPrevious());
    }

    #[Test]
    public function fromPdoExposesParsedAccessors(): void
    {
        $original = $this->pdoException(
            'SQLSTATE[40P01]: deadlock detected',
            ['40P01', 7, 'deadlock detected'],
            '40P01'
        );

        $exception = DeadlockException::fromPdo($original, 'pgsql');

        $this->assertSame('40P01', $exception->sqlState());
        $this->assertSame(7, $exception->driverCode());
        $this->assertSame('pgsql', $exception->driver());
    }

    #[Test]
    public function extractSqlStatePrefersErrorInfoOverCode(): void
    {
        $e = $this->pdoException('m', ['23505', 0, 'x'], 'HY000');
        $this->assertSame('23505', DatabaseException::extractSqlState($e));
    }

    #[Test]
    public function extractSqlStateFallsBackToStringCodeThatResemblesSqlState(): void
    {
        $e = $this->pdoException('m', null, '40001');
        $this->assertSame('40001', DatabaseException::extractSqlState($e));
    }

    #[Test]
    public function extractSqlStateReturnsNullForNonSqlStateShapes(): void
    {
        $this->assertNull(DatabaseException::extractSqlState($this->pdoException('m', null, 0)));
        $this->assertNull(DatabaseException::extractSqlState($this->pdoException('m', null, 'oops')));
        $this->assertNull(DatabaseException::extractSqlState($this->pdoException('m', null)));
    }

    #[Test]
    public function extractDriverCodeReadsErrorInfoIndexOne(): void
    {
        $this->assertSame(1213, DatabaseException::extractDriverCode(
            $this->pdoException('m', ['40001', 1213, 'Deadlock found'])
        ));
        $this->assertNull(DatabaseException::extractDriverCode($this->pdoException('m', null)));
    }

    #[Test]
    public function hierarchyAndMarkersAreExactlyAsSpecified(): void
    {
        $raw = $this->pdoException('m', ['HY000', 0, 'x']);

        foreach (
            [
                UniqueConstraintViolationException::class,
                ForeignKeyConstraintViolationException::class,
                NotNullConstraintViolationException::class,
            ] as $constraintClass
        ) {
            $e = $constraintClass::fromPdo($raw, 'sqlite');
            $this->assertInstanceOf(ConstraintViolationException::class, $e);
            $this->assertNotInstanceOf(TransientFailureInterface::class, $e);
        }

        foreach (
            [
                DeadlockException::class,
                SerializationFailureException::class,
                LockContentionException::class,
            ] as $retryableClass
        ) {
            $e = $retryableClass::fromPdo($raw, 'sqlite');
            $this->assertInstanceOf(RetryableTransactionFailureInterface::class, $e);
        }

        $lost = ConnectionLostException::fromPdo($raw, 'mysql');
        $this->assertInstanceOf(TransientFailureInterface::class, $lost);
        $this->assertNotInstanceOf(RetryableTransactionFailureInterface::class, $lost);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Database/Exceptions/DatabaseExceptionTest.php`
Expected: ERROR — `Class "Glueful\Database\Exceptions\..." not found`.

- [ ] **Step 3: Write the interfaces**

`src/Database/Exceptions/DatabaseExceptionInterface.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Database\Exceptions;

/**
 * Contract for classified database failures.
 *
 * All classified exceptions also extend \PDOException, so existing
 * catch (\PDOException) sites keep matching them.
 */
interface DatabaseExceptionInterface extends \Throwable
{
    public function sqlState(): ?string;

    public function driverCode(): int|string|null;

    public function driver(): string;
}
```

`src/Database/Exceptions/TransientFailureInterface.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Database\Exceptions;

/**
 * Marker for failures that may succeed on a later attempt in SOME context
 * (e.g. after reconnecting). Implementing this does NOT mean the failure is
 * safe to retry inside the current transaction — that is
 * RetryableTransactionFailureInterface.
 */
interface TransientFailureInterface extends DatabaseExceptionInterface
{
}
```

`src/Database/Exceptions/RetryableTransactionFailureInterface.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Database\Exceptions;

/**
 * Marker for failures where re-running the whole transaction from the top is
 * a sound recovery strategy (deadlock victim, serialization failure, lock
 * contention). TransactionManager's retry loop keys on this interface only.
 */
interface RetryableTransactionFailureInterface extends TransientFailureInterface
{
}
```

- [ ] **Step 4: Write the base class**

`src/Database/Exceptions/DatabaseException.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Database\Exceptions;

/**
 * Base class for classified database failures, and the generic fallback for
 * failures no rule matches.
 *
 * Extends \PDOException so every existing catch (\PDOException) site in the
 * framework, extensions, and applications keeps matching classified failures.
 */
class DatabaseException extends \PDOException implements DatabaseExceptionInterface
{
    protected ?string $sqlStateValue = null;
    protected int|string|null $driverCodeValue = null;
    protected string $driverName = '';

    final public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Build a classified exception from a raw PDO failure, preserving all of
     * its state. Inheritance alone copies nothing.
     */
    public static function fromPdo(\PDOException $e, string $driver): static
    {
        $exception = new static($e->getMessage(), 0, $e);
        // \Exception's constructor only accepts int codes; PDO uses string
        // SQLSTATEs, so the original code is restored by property assignment.
        $exception->code = $e->getCode();
        $exception->errorInfo = $e->errorInfo;
        $exception->sqlStateValue = self::extractSqlState($e);
        $exception->driverCodeValue = self::extractDriverCode($e);
        $exception->driverName = $driver;

        return $exception;
    }

    public function sqlState(): ?string
    {
        return $this->sqlStateValue;
    }

    public function driverCode(): int|string|null
    {
        return $this->driverCodeValue;
    }

    public function driver(): string
    {
        return $this->driverName;
    }

    /**
     * SQLSTATE from errorInfo[0], falling back to getCode() only when it
     * has the five-character alphanumeric SQLSTATE shape.
     */
    public static function extractSqlState(\PDOException $e): ?string
    {
        $candidate = $e->errorInfo[0] ?? null;
        if (is_string($candidate) && preg_match('/^[A-Z0-9]{5}$/', $candidate) === 1) {
            return $candidate;
        }

        $code = $e->getCode();
        if (is_string($code) && preg_match('/^[A-Z0-9]{5}$/', $code) === 1) {
            return $code;
        }

        return null;
    }

    /** Driver-specific error code from errorInfo[1]. */
    public static function extractDriverCode(\PDOException $e): int|string|null
    {
        $value = $e->errorInfo[1] ?? null;

        return is_int($value) || is_string($value) ? $value : null;
    }
}
```

PHPStan notes: the `final` constructor makes `new static()` safe at level 8. If the
analyser rejects `$exception->code = $e->getCode()` (stub typing of `Exception::$code`
vs `PDOException::getCode()`), the level-8-clean alternative is a
`/** @var int|string $originalCode */ $originalCode = $e->getCode();` local before the
assignment — do not use an ignore.

- [ ] **Step 5: Write the eight subclasses**

Each is its own file; only the two markers vary. `src/Database/Exceptions/ConstraintViolationException.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Database\Exceptions;

/**
 * A constraint was violated but the driver did not say which kind.
 * Under default SQLite configuration (no extended result codes), all
 * constraint failures land here — see the spec's SQLite decision.
 */
class ConstraintViolationException extends DatabaseException
{
}
```

`UniqueConstraintViolationException.php`, `ForeignKeyConstraintViolationException.php`,
`NotNullConstraintViolationException.php` — identical shape, `final`, extending
`ConstraintViolationException`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Database\Exceptions;

final class UniqueConstraintViolationException extends ConstraintViolationException
{
}
```

`DeadlockException.php`, `SerializationFailureException.php` — identical shape:

```php
<?php

declare(strict_types=1);

namespace Glueful\Database\Exceptions;

final class DeadlockException extends DatabaseException implements RetryableTransactionFailureInterface
{
}
```

`LockContentionException.php` (named for contention, not timeout — PG `55P03` NOWAIT
and SQLite BUSY occur without any timeout):

```php
<?php

declare(strict_types=1);

namespace Glueful\Database\Exceptions;

/**
 * Lock could not be acquired: MySQL 1205 lock-wait timeout, PostgreSQL 55P03
 * lock_not_available, SQLite SQLITE_BUSY / SQLITE_LOCKED.
 */
final class LockContentionException extends DatabaseException implements RetryableTransactionFailureInterface
{
}
```

`ConnectionLostException.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Database\Exceptions;

/**
 * Connection lost during statement execution or transaction control.
 *
 * Transient but NOT transaction-retryable: replaying a transaction on a dead
 * connection requires reconnect and transaction-state handling that the
 * framework does not provide yet.
 */
final class ConnectionLostException extends DatabaseException implements TransientFailureInterface
{
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Database/Exceptions/DatabaseExceptionTest.php`
Expected: PASS (all hierarchy tests, including the preservation contract for all nine
concrete exception classes).

---

### Task 2: ExceptionClassifier

**Files:**
- Create: `src/Database/Exceptions/ExceptionClassifier.php`
- Test: `tests/Unit/Database/Exceptions/ExceptionClassifierTest.php`

**Interfaces:**
- Consumes: Task 1's classes, `DatabaseException::extractSqlState()` / `extractDriverCode()` / `fromPdo()`.
- Produces: `ExceptionClassifier::classify(\PDOException $exception, string $driver): DatabaseException` — `final`, stateless, safe to construct with `new ExceptionClassifier()` anywhere. Tasks 3–4 call exactly this.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Database/Exceptions/ExceptionClassifierTest.php` (reuse the same
`pdoException()` factory verbatim from Task 1's test):

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\Exceptions;

use Glueful\Database\Exceptions\ConnectionLostException;
use Glueful\Database\Exceptions\ConstraintViolationException;
use Glueful\Database\Exceptions\DatabaseException;
use Glueful\Database\Exceptions\DeadlockException;
use Glueful\Database\Exceptions\ExceptionClassifier;
use Glueful\Database\Exceptions\ForeignKeyConstraintViolationException;
use Glueful\Database\Exceptions\LockContentionException;
use Glueful\Database\Exceptions\NotNullConstraintViolationException;
use Glueful\Database\Exceptions\SerializationFailureException;
use Glueful\Database\Exceptions\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExceptionClassifierTest extends TestCase
{
    private ExceptionClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new ExceptionClassifier();
    }

    /**
     * @param array{0: string, 1?: int|string|null, 2?: string}|null $errorInfo
     */
    private function pdoException(
        string $message,
        ?array $errorInfo,
        int|string|null $code = null
    ): \PDOException {
        return new class ($message, $errorInfo, $code) extends \PDOException {
            /**
             * @param array{0: string, 1?: int|string|null, 2?: string}|null $errorInfo
             */
            public function __construct(
                string $message,
                ?array $errorInfo,
                int|string|null $code
            ) {
                parent::__construct($message);
                $this->errorInfo = $errorInfo;

                if ($code !== null) {
                    $this->code = $code;
                }
            }
        };
    }

    /**
     * @return iterable<string, array{driver: string, errorInfo: array{0: string, 1?: int|string|null, 2?: string}, expected: class-string<DatabaseException>}>
     */
    public static function classificationCases(): iterable
    {
        // MySQL — vendor codes win; SQLSTATE is often generic or misleading.
        yield 'mysql duplicate entry (23000+1062)' => [
            'driver' => 'mysql', 'errorInfo' => ['23000', 1062, "Duplicate entry"],
            'expected' => UniqueConstraintViolationException::class,
        ];
        yield 'mysql fk parent missing (23000+1452)' => [
            'driver' => 'mysql', 'errorInfo' => ['23000', 1452, 'Cannot add or update a child row'],
            'expected' => ForeignKeyConstraintViolationException::class,
        ];
        yield 'mysql fk child rows (23000+1451)' => [
            'driver' => 'mysql', 'errorInfo' => ['23000', 1451, 'Cannot delete or update a parent row'],
            'expected' => ForeignKeyConstraintViolationException::class,
        ];
        yield 'mysql not null (23000+1048)' => [
            'driver' => 'mysql', 'errorInfo' => ['23000', 1048, "Column 'x' cannot be null"],
            'expected' => NotNullConstraintViolationException::class,
        ];
        yield 'mysql deadlock reported with 40001 (vendor-first is load-bearing)' => [
            'driver' => 'mysql', 'errorInfo' => ['40001', 1213, 'Deadlock found when trying to get lock'],
            'expected' => DeadlockException::class,
        ];
        yield 'mysql lock wait timeout (1205)' => [
            'driver' => 'mysql', 'errorInfo' => ['HY000', 1205, 'Lock wait timeout exceeded'],
            'expected' => LockContentionException::class,
        ];
        yield 'mysql server gone away (2006)' => [
            'driver' => 'mysql', 'errorInfo' => ['HY000', 2006, 'MySQL server has gone away'],
            'expected' => ConnectionLostException::class,
        ];
        yield 'mysql lost connection (2013)' => [
            'driver' => 'mysql', 'errorInfo' => ['HY000', 2013, 'Lost connection to MySQL server'],
            'expected' => ConnectionLostException::class,
        ];

        // PostgreSQL — SQLSTATEs are specific; the exact map suffices.
        yield 'pgsql unique (23505)' => [
            'driver' => 'pgsql', 'errorInfo' => ['23505', 7, 'duplicate key value violates unique constraint'],
            'expected' => UniqueConstraintViolationException::class,
        ];
        yield 'pgsql fk (23503)' => [
            'driver' => 'pgsql', 'errorInfo' => ['23503', 7, 'violates foreign key constraint'],
            'expected' => ForeignKeyConstraintViolationException::class,
        ];
        yield 'pgsql not null (23502)' => [
            'driver' => 'pgsql', 'errorInfo' => ['23502', 7, 'null value in column'],
            'expected' => NotNullConstraintViolationException::class,
        ];
        yield 'pgsql serialization failure (40001)' => [
            'driver' => 'pgsql', 'errorInfo' => ['40001', 7, 'could not serialize access'],
            'expected' => SerializationFailureException::class,
        ];
        yield 'pgsql deadlock (40P01)' => [
            'driver' => 'pgsql', 'errorInfo' => ['40P01', 7, 'deadlock detected'],
            'expected' => DeadlockException::class,
        ];
        yield 'pgsql lock not available (55P03)' => [
            'driver' => 'pgsql', 'errorInfo' => ['55P03', 7, 'could not obtain lock'],
            'expected' => LockContentionException::class,
        ];
        yield 'pgsql admin shutdown (57P01)' => [
            'driver' => 'pgsql', 'errorInfo' => ['57P01', 7, 'terminating connection'],
            'expected' => ConnectionLostException::class,
        ];
        yield 'pgsql check violation falls to constraint family (23514)' => [
            'driver' => 'pgsql', 'errorInfo' => ['23514', 7, 'violates check constraint'],
            'expected' => ConstraintViolationException::class,
        ];
        yield 'pgsql connection family (08006)' => [
            'driver' => 'pgsql', 'errorInfo' => ['08006', 7, 'connection failure'],
            'expected' => ConnectionLostException::class,
        ];

        // SQLite — default config is ambiguous; extended codes honored when supplied.
        yield 'sqlite default constraint code is ambiguous (23000+19)' => [
            'driver' => 'sqlite', 'errorInfo' => ['23000', 19, 'UNIQUE constraint failed: t.email'],
            'expected' => ConstraintViolationException::class,
        ];
        yield 'sqlite busy (5)' => [
            'driver' => 'sqlite', 'errorInfo' => ['HY000', 5, 'database is locked'],
            'expected' => LockContentionException::class,
        ];
        yield 'sqlite locked (6)' => [
            'driver' => 'sqlite', 'errorInfo' => ['HY000', 6, 'database table is locked'],
            'expected' => LockContentionException::class,
        ];
        yield 'sqlite extended unique (2067)' => [
            'driver' => 'sqlite', 'errorInfo' => ['HY000', 2067, 'UNIQUE constraint failed'],
            'expected' => UniqueConstraintViolationException::class,
        ];
        yield 'sqlite extended primary key (1555)' => [
            'driver' => 'sqlite', 'errorInfo' => ['HY000', 1555, 'UNIQUE constraint failed: t.id'],
            'expected' => UniqueConstraintViolationException::class,
        ];
        yield 'sqlite extended not null (1299)' => [
            'driver' => 'sqlite', 'errorInfo' => ['HY000', 1299, 'NOT NULL constraint failed'],
            'expected' => NotNullConstraintViolationException::class,
        ];
        yield 'sqlite extended fk (787)' => [
            'driver' => 'sqlite', 'errorInfo' => ['HY000', 787, 'FOREIGN KEY constraint failed'],
            'expected' => ForeignKeyConstraintViolationException::class,
        ];

        // Unknown → generic.
        yield 'unknown code and state → generic' => [
            'driver' => 'mysql', 'errorInfo' => ['HY000', 9999, 'something else'],
            'expected' => DatabaseException::class,
        ];
        yield 'unknown driver uses sqlstate maps' => [
            'driver' => 'sqlsrv', 'errorInfo' => ['23505', 0, 'dup'],
            'expected' => UniqueConstraintViolationException::class,
        ];
    }

    /**
     * @param array{0: string, 1?: int|string|null, 2?: string} $errorInfo
     * @param class-string<DatabaseException> $expected
     */
    #[Test]
    #[DataProvider('classificationCases')]
    public function classifiesDriverErrorShapes(string $driver, array $errorInfo, string $expected): void
    {
        $raw = $this->pdoException('SQLSTATE[' . $errorInfo[0] . ']: test', $errorInfo, $errorInfo[0]);

        $classified = $this->classifier->classify($raw, $driver);

        $this->assertSame($expected, get_class($classified));
        $this->assertSame($raw, $classified->getPrevious());
        $this->assertSame($driver, $classified->driver());
    }

    #[Test]
    public function alreadyClassifiedExceptionsPassThroughUnchanged(): void
    {
        $raw = $this->pdoException('m', ['40P01', 7, 'deadlock detected'], '40P01');
        $classified = DeadlockException::fromPdo($raw, 'pgsql');

        $this->assertSame($classified, $this->classifier->classify($classified, 'pgsql'));
    }

    #[Test]
    public function missingErrorInfoFallsBackToStringCode(): void
    {
        $raw = $this->pdoException('m', null, '23505');

        $this->assertInstanceOf(
            UniqueConstraintViolationException::class,
            $this->classifier->classify($raw, 'pgsql')
        );
    }

    #[Test]
    public function numericStringVendorCodesStillMatch(): void
    {
        $raw = $this->pdoException('m', ['HY000', '1205', 'Lock wait timeout'], 'HY000');

        $this->assertInstanceOf(
            LockContentionException::class,
            $this->classifier->classify($raw, 'mysql')
        );
    }

    #[Test]
    public function noErrorInformationAtAllIsGeneric(): void
    {
        $raw = $this->pdoException('driver exploded', null);

        $this->assertSame(DatabaseException::class, get_class($this->classifier->classify($raw, 'mysql')));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Database/Exceptions/ExceptionClassifierTest.php`
Expected: ERROR — `Class "Glueful\Database\Exceptions\ExceptionClassifier" not found`.

- [ ] **Step 3: Write the classifier**

`src/Database/Exceptions/ExceptionClassifier.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Database\Exceptions;

/**
 * Deterministic PDO-failure classifier: SQLSTATE plus vendor codes in, one
 * typed DatabaseException out. Stateless — construct anywhere.
 *
 * Precedence is specificity-first: driver vendor codes are consulted BEFORE
 * the exact-SQLSTATE map because MySQL reports deadlock 1213 with SQLSTATE
 * 40001 — SQLSTATE-first would misclassify it as a serialization failure.
 * No message matching: driver wording varies by version and locale.
 */
final class ExceptionClassifier
{
    /** @var array<string, class-string<DatabaseException>> Unambiguous SQLSTATEs only — 23000 deliberately excluded (MySQL uses it for both unique and FK violations). */
    private const SQLSTATE_MAP = [
        '23505' => UniqueConstraintViolationException::class,
        '23503' => ForeignKeyConstraintViolationException::class,
        '23502' => NotNullConstraintViolationException::class,
        '40001' => SerializationFailureException::class,
        '40P01' => DeadlockException::class,
        '55P03' => LockContentionException::class,
        '57P01' => ConnectionLostException::class,
        '57P02' => ConnectionLostException::class,
        '57P03' => ConnectionLostException::class,
    ];

    /** @var array<string, array<int, class-string<DatabaseException>>> */
    private const VENDOR_MAP = [
        'mysql' => [
            1062 => UniqueConstraintViolationException::class,
            1451 => ForeignKeyConstraintViolationException::class,
            1452 => ForeignKeyConstraintViolationException::class,
            1048 => NotNullConstraintViolationException::class,
            1213 => DeadlockException::class,
            1205 => LockContentionException::class,
            2006 => ConnectionLostException::class,
            2013 => ConnectionLostException::class,
        ],
        'sqlite' => [
            5 => LockContentionException::class,
            6 => LockContentionException::class,
            // Extended result codes — honored when supplied; the framework
            // does not enable PDO::SQLITE_ATTR_EXTENDED_RESULT_CODES itself
            // (see the spec's SQLite compatibility decision).
            2067 => UniqueConstraintViolationException::class,
            1555 => UniqueConstraintViolationException::class,
            1299 => NotNullConstraintViolationException::class,
            787 => ForeignKeyConstraintViolationException::class,
            // Bare SQLITE_CONSTRAINT: kind is unknowable without messages.
            19 => ConstraintViolationException::class,
        ],
        // PostgreSQL SQLSTATEs are specific; the exact-SQLSTATE map suffices.
        'pgsql' => [],
    ];

    /** @var array<string, class-string<DatabaseException>> Keyed by two-character SQLSTATE class. */
    private const SQLSTATE_FAMILY_MAP = [
        '23' => ConstraintViolationException::class,
        '08' => ConnectionLostException::class,
    ];

    public function classify(\PDOException $exception, string $driver): DatabaseException
    {
        if ($exception instanceof DatabaseException) {
            return $exception;
        }

        $sqlState = DatabaseException::extractSqlState($exception);
        $vendorCode = $this->normalizeVendorCode(DatabaseException::extractDriverCode($exception));

        $class = null;
        if ($vendorCode !== null) {
            $class = self::VENDOR_MAP[$driver][$vendorCode] ?? null;
        }
        if ($class === null && $sqlState !== null) {
            $class = self::SQLSTATE_MAP[$sqlState] ?? null;
        }
        if ($class === null && $sqlState !== null) {
            $class = self::SQLSTATE_FAMILY_MAP[substr($sqlState, 0, 2)] ?? null;
        }

        return ($class ?? DatabaseException::class)::fromPdo($exception, $driver);
    }

    private function normalizeVendorCode(int|string|null $code): ?int
    {
        if (is_int($code)) {
            return $code;
        }
        if (is_string($code) && preg_match('/^\d+$/', $code) === 1) {
            return (int) $code;
        }

        return null;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Database/Exceptions/`
Expected: PASS (both test files; the classifier provider exercises 26 driver/error-shape
cases, plus its focused fallback and passthrough tests).

- [ ] **Step 5: Gates + Commit checkpoint 1**

Run all three gates (full-tree — see Global Constraints). All green, then:

```bash
git add src/Database/Exceptions/DatabaseExceptionInterface.php src/Database/Exceptions/TransientFailureInterface.php src/Database/Exceptions/RetryableTransactionFailureInterface.php src/Database/Exceptions/DatabaseException.php src/Database/Exceptions/ConstraintViolationException.php src/Database/Exceptions/UniqueConstraintViolationException.php src/Database/Exceptions/ForeignKeyConstraintViolationException.php src/Database/Exceptions/NotNullConstraintViolationException.php src/Database/Exceptions/DeadlockException.php src/Database/Exceptions/SerializationFailureException.php src/Database/Exceptions/LockContentionException.php src/Database/Exceptions/ConnectionLostException.php src/Database/Exceptions/ExceptionClassifier.php tests/Unit/Database/Exceptions/DatabaseExceptionTest.php tests/Unit/Database/Exceptions/ExceptionClassifierTest.php
git commit -m "feat(database): add typed database exception hierarchy and classifier"
```

---

### Task 3: QueryExecutor integration

**Files:**
- Modify: `src/Database/Execution/QueryExecutor.php` (constructor ~line 33; catch inside `executeStatement()`'s `$core` closure ~line 259)
- Test: `tests/Integration/Database/TypedExceptionsIntegrationTest.php`

**Interfaces:**
- Consumes: `ExceptionClassifier::classify(\PDOException, string): DatabaseException` (Task 2); existing `QueryExecutor::getDriverName(): string` (line 350).
- Produces: `executeStatement()` (and everything built on it) now throws `Glueful\Database\Exceptions\DatabaseException` subtypes instead of raw `\PDOException`. No signature changes.

- [ ] **Step 1: Write the failing integration test**

`tests/Integration/Database/TypedExceptionsIntegrationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Database;

use Glueful\Database\Exceptions\ConstraintViolationException;
use Glueful\Database\Execution\ParameterBinder;
use Glueful\Database\Execution\QueryExecutor;
use Glueful\Database\QueryLogger;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Real SQLite failures through the real executor. Under default PDO
 * configuration (no extended result codes) every constraint kind is
 * indistinguishable, so the expected type is the generic
 * ConstraintViolationException — the spec's SQLite decision.
 */
final class TypedExceptionsIntegrationTest extends TestCase
{
    private QueryExecutor $executor;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT NOT NULL UNIQUE)');
        $pdo->exec(
            'CREATE TABLE posts (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id))'
        );

        $this->executor = new QueryExecutor($pdo, new ParameterBinder(), new QueryLogger());
    }

    #[Test]
    public function uniqueViolationIsClassifiedAndStillAPdoException(): void
    {
        $this->executor->executeStatement('INSERT INTO users (email) VALUES (?)', ['a@b.c']);

        try {
            $this->executor->executeStatement('INSERT INTO users (email) VALUES (?)', ['a@b.c']);
            $this->fail('Expected a constraint violation');
        } catch (ConstraintViolationException $e) {
            $this->assertInstanceOf(\PDOException::class, $e);
            $this->assertInstanceOf(\PDOException::class, $e->getPrevious());
            $this->assertSame('sqlite', $e->driver());
            $this->assertSame(19, $e->driverCode());
        }
    }

    #[Test]
    public function notNullViolationIsClassified(): void
    {
        $this->expectException(ConstraintViolationException::class);
        $this->executor->executeStatement('INSERT INTO users (email) VALUES (?)', [null]);
    }

    #[Test]
    public function foreignKeyViolationIsClassified(): void
    {
        $this->expectException(ConstraintViolationException::class);
        $this->executor->executeStatement('INSERT INTO posts (user_id) VALUES (?)', [999]);
    }
}
```

> **Spec deviation, resolved deliberately:** the spec calls for MySQL/PostgreSQL
> integration equivalents "behind the existing environment guards", but the framework
> test suite has no MySQL/PG harness or guard pattern to put them behind. Per-driver
> MySQL/PG classification coverage therefore lives in the Task 2 unit tests, which
> drive the classifier with those drivers' real `errorInfo` shapes — the integration
> seam being tested here (executor catch → classify → throw) is driver-independent.
> Building a multi-database integration harness is its own future task, not part of
> this slice.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Database/TypedExceptionsIntegrationTest.php`
Expected: FAIL — raw `PDOException` thrown, not `ConstraintViolationException`.

- [ ] **Step 3: Integrate the classifier**

In `src/Database/Execution/QueryExecutor.php`:

Add the import:

```php
use Glueful\Database\Exceptions\ExceptionClassifier;
```

Add a property and assign it in the existing constructor (no signature change):

```php
    protected ExceptionClassifier $classifier;

    public function __construct(
        PDO $pdo,
        ParameterBinderInterface $binder,
        QueryLogger $logger
    ) {
        $this->pdo = $pdo;
        $this->binder = $binder;
        $this->logger = $logger;
        $this->classifier = new ExceptionClassifier();
    }
```

Change the catch inside `executeStatement()`'s `$core` closure (only the `throw` line
changes — logging still receives the original exception):

```php
            } catch (PDOException $e) {
                $sanitizedBindings = $this->binder->sanitizeBindingsForLog($flattenedParams);
                $this->logger->logQuery($sql, $sanitizedBindings, $timerId, $e, $purpose);
                throw $this->classifier->classify($e, $this->getDriverName());
            }
```

- [ ] **Step 4: Run tests to verify pass**

Run: `vendor/bin/phpunit tests/Integration/Database/TypedExceptionsIntegrationTest.php tests/Unit/Database/`
Expected: PASS, including all pre-existing Database tests (classified exceptions still
satisfy every existing `catch (PDOException)` / `expectException(PDOException::class)`).

---

### Task 4: TransactionManager integration

**Files:**
- Modify: `src/Database/Transaction/TransactionManager.php` (constructor ~line 44; `transaction()` ~line 58; `begin()`/`commit()`/`rollback()` ~lines 128–207; delete `isDeadlock()` ~line 239)
- Modify: `src/Database/Connection.php:825-829` (pass driver to the constructor)
- Test: `tests/Unit/Database/Transaction/TransactionManagerTest.php` (extend existing file)

**Interfaces:**
- Consumes: `ExceptionClassifier::classify()`, `RetryableTransactionFailureInterface`, `DeadlockException::fromPdo()`, `ConnectionLostException::fromPdo()` (Tasks 1–2).
- Produces: `__construct(PDO $pdo, SavepointManagerInterface $savepointManager, QueryLogger $logger, ?string $driver = null)` — optional trailing arg only; existing 3-arg construction keeps working. `begin()`/`commit()`/`rollback()` now throw classified exceptions; `transaction()` rethrows the final typed failure after exhaustion.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/Database/Transaction/TransactionManagerTest.php` (existing file —
keep its `setUp()`; add these imports: `Glueful\Database\Exceptions\ConnectionLostException`,
`Glueful\Database\Exceptions\DatabaseException`, `Glueful\Database\Exceptions\DeadlockException`,
`Glueful\Database\Exceptions\LockContentionException`):

```php
    /**
     * @param array{0: string, 1?: int|string|null, 2?: string} $errorInfo
     */
    private function rawPdoException(string $message, array $errorInfo): \PDOException
    {
        $e = new \PDOException($message);
        $e->errorInfo = $errorInfo;

        return $e;
    }

    /** A real SQLite PDO whose transaction methods can be made to fail. */
    private function faultInjectingPdo(int $failBeginTimes): PDO
    {
        return new class ('sqlite::memory:', $failBeginTimes) extends PDO {
            public int $beginAttempts = 0;
            public int $rollbackCalls = 0;
            public bool $failCommit = false;
            public bool $failRollback = false;

            public function __construct(string $dsn, private int $failBeginTimes)
            {
                parent::__construct($dsn);
                $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            }

            private function lockedException(): \PDOException
            {
                $e = new \PDOException('database is locked');
                $e->errorInfo = ['HY000', 5, 'database is locked'];

                return $e;
            }

            public function beginTransaction(): bool
            {
                $this->beginAttempts++;
                if ($this->beginAttempts <= $this->failBeginTimes) {
                    throw $this->lockedException();
                }

                return parent::beginTransaction();
            }

            public function commit(): bool
            {
                if ($this->failCommit) {
                    throw $this->lockedException();
                }

                return parent::commit();
            }

            public function rollBack(): bool
            {
                $this->rollbackCalls++;
                if ($this->failRollback) {
                    throw $this->lockedException();
                }

                return parent::rollBack();
            }
        };
    }

    #[Test]
    public function retryableFailureRetriesAndSucceeds(): void
    {
        $attempts = 0;
        $deadlock = DeadlockException::fromPdo(
            $this->rawPdoException('deadlock detected', ['40P01', 7, 'deadlock detected']),
            'pgsql'
        );

        $result = $this->manager->transaction(function () use (&$attempts, $deadlock): string {
            $attempts++;
            if ($attempts === 1) {
                throw $deadlock;
            }

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertSame(2, $attempts);
    }

    #[Test]
    public function exhaustionRethrowsTheLastTypedFailure(): void
    {
        $this->manager->setMaxRetries(1);
        $deadlock = DeadlockException::fromPdo(
            $this->rawPdoException('deadlock detected', ['40P01', 7, 'deadlock detected']),
            'pgsql'
        );

        try {
            $this->manager->transaction(static function () use ($deadlock): never {
                throw $deadlock;
            });
            $this->fail('Expected the deadlock to be rethrown');
        } catch (DeadlockException $caught) {
            $this->assertSame($deadlock, $caught);
        }
    }

    #[Test]
    public function connectionLostIsNotRetried(): void
    {
        $attempts = 0;
        $lost = ConnectionLostException::fromPdo(
            $this->rawPdoException('server has gone away', ['HY000', 2006, 'gone']),
            'mysql'
        );

        try {
            $this->manager->transaction(function () use (&$attempts, $lost): never {
                $attempts++;
                throw $lost;
            });
            $this->fail('Expected the failure to propagate');
        } catch (ConnectionLostException $caught) {
            $this->assertSame($lost, $caught);
            $this->assertSame(1, $attempts);
        }
    }

    #[Test]
    public function maxRetriesZeroKeepsTheExistingGenericException(): void
    {
        $this->manager->setMaxRetries(0);
        $invoked = false;

        try {
            $this->manager->transaction(function () use (&$invoked): string {
                $invoked = true;

                return 'unreachable';
            });
            $this->fail('Expected the exhaustion exception');
        } catch (\Exception $e) {
            $this->assertSame('Transaction failed after 0 retries due to deadlock.', $e->getMessage());
            $this->assertNotInstanceOf(DatabaseException::class, $e);
            $this->assertFalse($invoked, 'maxRetries=0 must keep making zero attempts');
        }
    }

    #[Test]
    public function rawPdoExceptionFromCallbackIsClassifiedBeforeTheMarkerCheck(): void
    {
        $manager = new TransactionManager(
            $this->pdo,
            $this->savepointManager,
            $this->logger,
            'mysql'
        );
        $manager->setMaxRetries(1);

        try {
            $manager->transaction(function (): never {
                throw $this->rawPdoException(
                    'Deadlock found when trying to get lock',
                    ['40001', 1213, 'Deadlock found when trying to get lock']
                );
            });
            $this->fail('Expected a classified deadlock');
        } catch (DeadlockException $e) {
            $this->assertSame('mysql', $e->driver());
        }
    }

    #[Test]
    public function beginTimeLockContentionRetriesWithoutRollingBack(): void
    {
        $pdo = $this->faultInjectingPdo(failBeginTimes: 1);
        $manager = new TransactionManager($pdo, $this->savepointManager, $this->logger);

        $result = $manager->transaction(static fn (): string => 'reached');

        $this->assertSame('reached', $result);
        $this->assertSame(2, $pdo->beginAttempts);
        $this->assertSame(0, $pdo->rollbackCalls, 'rollback must not run when begin never succeeded');
    }

    #[Test]
    public function directBeginClassifiesItsOwnFailure(): void
    {
        $pdo = $this->faultInjectingPdo(failBeginTimes: PHP_INT_MAX);
        $manager = new TransactionManager($pdo, $this->savepointManager, $this->logger);

        $this->expectException(LockContentionException::class);
        $manager->begin();
    }

    #[Test]
    public function directCommitClassifiesItsOwnFailure(): void
    {
        $pdo = $this->faultInjectingPdo(failBeginTimes: 0);
        $manager = new TransactionManager($pdo, $this->savepointManager, $this->logger);
        $manager->begin();
        $pdo->failCommit = true;

        $this->expectException(LockContentionException::class);
        $manager->commit();
    }

    #[Test]
    public function directRollbackClassifiesItsOwnFailure(): void
    {
        $pdo = $this->faultInjectingPdo(failBeginTimes: 0);
        $manager = new TransactionManager($pdo, $this->savepointManager, $this->logger);
        $manager->begin();
        $pdo->failRollback = true;

        $this->expectException(LockContentionException::class);
        $manager->rollback();
    }

    #[Test]
    public function nonPdoExceptionsRollBackAndRethrowUnclassified(): void
    {
        $domainFailure = new \RuntimeException('domain rule violated');

        try {
            $this->manager->transaction(static function () use ($domainFailure): never {
                throw $domainFailure;
            });
            $this->fail('Expected the domain exception to propagate');
        } catch (\RuntimeException $caught) {
            $this->assertSame($domainFailure, $caught);
            $this->assertFalse($this->manager->isActive(), 'transaction must have been rolled back');
        }
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Unit/Database/Transaction/TransactionManagerTest.php`
Expected: the new tests FAIL (ctor rejects 4th arg; raw exceptions uncategorized; begin
outside retry; exhaustion throws the generic `Exception`); pre-existing tests still pass.

- [ ] **Step 3: Implement TransactionManager changes**

In `src/Database/Transaction/TransactionManager.php`:

Imports — add:

```php
use Glueful\Database\Exceptions\DatabaseException;
use Glueful\Database\Exceptions\ExceptionClassifier;
use Glueful\Database\Exceptions\RetryableTransactionFailureInterface;
```

Properties and constructor (optional trailing arg only — existing 3-arg callers work
unchanged):

```php
    protected string $driver;
    protected ExceptionClassifier $classifier;

    public function __construct(
        PDO $pdo,
        SavepointManagerInterface $savepointManager,
        QueryLogger $logger,
        ?string $driver = null
    ) {
        $this->pdo = $pdo;
        $this->savepointManager = $savepointManager;
        $this->logger = $logger;
        $driverName = $driver ?? $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $this->driver = is_string($driverName) ? $driverName : '';
        $this->classifier = new ExceptionClassifier();
    }
```

Replace `transaction()` in full (`begin()` moves inside the `try`; raw PDO failures are
classified defensively; the last typed failure survives exhaustion; retry count,
backoff, and every log call are byte-identical to today):

```php
    public function transaction(callable $callback): mixed
    {
        $retryCount = 0;
        $lastFailure = null;

        $this->logger->logEvent("Starting transaction", ['retries_allowed' => $this->maxRetries]);

        while ($retryCount < $this->maxRetries) {
            $began = false;
            try {
                $this->begin();
                $began = true;
                $result = $callback($this);
                $this->commit();

                // Log successful transaction
                $this->logger->logEvent(
                    "Transaction completed successfully",
                    [
                    'retries' => $retryCount,
                    'level' => $this->transactionLevel
                    ],
                    'info'
                );

                return $result;
            } catch (Exception $e) {
                // begin()/commit()/rollback() classify their own failures; a raw
                // PDOException here came from the callback's direct PDO use.
                if ($e instanceof \PDOException && !$e instanceof DatabaseException) {
                    $e = $this->classifier->classify($e, $this->driver);
                }

                if ($e instanceof RetryableTransactionFailureInterface) {
                    if ($began) {
                        $this->rollback();
                    }
                    $lastFailure = $e;
                    $retryCount++;

                    // Log deadlock and retry
                    $this->logger->logEvent(
                        "Transaction deadlock detected, retrying",
                        [
                        'retry' => $retryCount,
                        'max_retries' => $this->maxRetries,
                        'error' => $e->getMessage()
                        ],
                        'warning'
                    );

                    // Progressive backoff
                    usleep(500000 * $retryCount);
                } else {
                    if ($began) {
                        $this->rollback();
                    }

                    // Log transaction failure
                    $this->logger->logEvent(
                        "Transaction failed",
                        [
                        'error' => $e->getMessage(),
                        'code' => $e->getCode(),
                        'level' => $this->transactionLevel
                        ],
                        'error'
                    );

                    throw $e;
                }
            }
        }

        $this->logger->logEvent(
            "Transaction failed after maximum retries",
            [
            'max_retries' => $this->maxRetries
            ],
            'error'
        );

        if ($lastFailure !== null) {
            throw $lastFailure;
        }

        // setMaxRetries(0) is valid and makes zero attempts, so no typed
        // failure exists; retain the historical generic exception for that
        // compatibility edge only.
        throw new Exception("Transaction failed after {$this->maxRetries} retries due to deadlock.");
    }
```

Wrap the PDO and savepoint-manager operations in `begin()`, `commit()`, and
`rollback()` so direct callers get the same typed surface (level bookkeeping and
callback semantics unchanged — the increment still only happens after success):

```php
    public function begin(): void
    {
        try {
            if ($this->transactionLevel === 0) {
                $this->pdo->beginTransaction();
                $this->logger->logEvent("Transaction started", ['level' => 1], 'debug');
            } else {
                $this->savepointManager->create($this->transactionLevel);
                $this->logger->logEvent("Savepoint created", ['level' => $this->transactionLevel + 1], 'debug');
            }
        } catch (\PDOException $e) {
            throw $this->classifier->classify($e, $this->driver);
        }
        $this->transactionLevel++;
    }
```

In `commit()`, wrap only the outermost-branch PDO call:

```php
        if ($level === 1) {
            // Outermost transaction - actually commit to database
            try {
                $this->pdo->commit();
            } catch (\PDOException $e) {
                throw $this->classifier->classify($e, $this->driver);
            }
            $this->logger->logEvent("Transaction committed", ['level' => 1], 'debug');
```

In `rollback()`, wrap both branches' operations the same way:

```php
        if ($level === 1) {
            // Outermost transaction - actually rollback
            try {
                $this->pdo->rollBack();
            } catch (\PDOException $e) {
                throw $this->classifier->classify($e, $this->driver);
            }
            $this->logger->logEvent("Transaction rolled back", ['level' => 1], 'debug');
```

```php
        } else {
            // Nested transaction (savepoint) - rollback to previous savepoint
            try {
                $this->savepointManager->rollbackTo($level - 1);
            } catch (\PDOException $e) {
                throw $this->classifier->classify($e, $this->driver);
            }
            $this->logger->logEvent("Rolled back to savepoint", ['level' => $level - 1], 'debug');
```

Delete `isDeadlock()` (~line 239) and its docblock entirely — nothing references it
after this change (verify with `grep -rn "isDeadlock" src/ tests/`).

In `src/Database/Connection.php` (~line 825), pass the driver at the construction site:

```php
            $this->transactionManager = new \Glueful\Database\Transaction\TransactionManager(
                $this->getPDO(),
                $savepointManager,
                $queryLogger,
                $this->getDriverName()
            );
```

- [ ] **Step 4: Run tests to verify pass**

Run: `vendor/bin/phpunit tests/Unit/Database/ tests/Integration/Database/`
Expected: PASS — new tests and all pre-existing transaction/connection tests.

- [ ] **Step 5: Gates + Commit checkpoint 2**

Run all three gates (full-tree). All green, then:

```bash
git add src/Database/Execution/QueryExecutor.php src/Database/Transaction/TransactionManager.php src/Database/Connection.php tests/Integration/Database/TypedExceptionsIntegrationTest.php tests/Unit/Database/Transaction/TransactionManagerTest.php
git commit -m "feat(database): classify failures at execution and transaction boundaries"
```

---

### Task 5: HTTP handler integration

**Files:**
- Modify: `src/Http/Exceptions/Handler.php` (imports ~line 27; `$dontReport` ~line 64; `render()` match ~line 508; `resolveLogChannel()` ~line 337; new renderer near `renderGenericException()` ~line 566)
- Test: `tests/Unit/Http/Exceptions/HandlerDatabaseExceptionsTest.php`

**Interfaces:**
- Consumes: `DatabaseExceptionInterface`, `UniqueConstraintViolationException`, `DatabaseException::fromPdo()` (Task 1).
- Produces: unique violations render `409` with the exact body message `A conflicting record already exists.`; `shouldReport()` returns `false` for them; `resolveLogChannel()` returns `'database'` for anything implementing `DatabaseExceptionInterface`.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Http/Exceptions/HandlerDatabaseExceptionsTest.php` — note the alias import:
this test references both `DatabaseException` basenames, which is exactly where the
spec's aliasing requirement applies:

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Http\Exceptions;

use Glueful\Database\Exceptions\DatabaseException as TypedDatabaseException;
use Glueful\Database\Exceptions\UniqueConstraintViolationException;
use Glueful\Http\Exceptions\Handler;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HandlerDatabaseExceptionsTest extends TestCase
{
    private function uniqueViolation(): UniqueConstraintViolationException
    {
        $raw = new \PDOException(
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'a@b.c' for key 'users.email'"
        );
        $raw->errorInfo = ['23000', 1062, "Duplicate entry 'a@b.c' for key 'users.email'"];

        return UniqueConstraintViolationException::fromPdo($raw, 'mysql');
    }

    private function genericTyped(): TypedDatabaseException
    {
        $raw = new \PDOException('SQLSTATE[HY000]: General error: 9999 exotic driver failure');
        $raw->errorInfo = ['HY000', 9999, 'exotic driver failure'];

        return TypedDatabaseException::fromPdo($raw, 'mysql');
    }

    #[Test]
    public function uniqueViolationRendersFixed409InNonDebugMode(): void
    {
        $handler = new Handler(debug: false);
        $response = $handler->render($this->uniqueViolation());
        $body = json_decode((string) $response->getContent(), true);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertIsArray($body);
        $this->assertFalse($body['success']);
        $this->assertSame('A conflicting record already exists.', $body['message']);
        $this->assertSame(409, $body['error']['code']);
    }

    #[Test]
    public function uniqueViolationMessageStaysFixedInDebugMode(): void
    {
        $handler = new Handler(debug: true);
        $response = $handler->render($this->uniqueViolation());
        $body = json_decode((string) $response->getContent(), true);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertIsArray($body);
        $this->assertSame('A conflicting record already exists.', $body['message']);
        $this->assertStringNotContainsString('Duplicate entry', (string) $response->getContent());
    }

    #[Test]
    public function uniqueViolationIsNotReported(): void
    {
        $handler = new Handler(debug: false);

        $this->assertFalse($handler->shouldReport($this->uniqueViolation()));
    }

    #[Test]
    public function genericTypedDatabaseExceptionKeepsSanitized500(): void
    {
        $handler = new Handler(debug: false);
        $response = $handler->render($this->genericTyped());
        $body = json_decode((string) $response->getContent(), true);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertIsArray($body);
        $this->assertStringNotContainsString('exotic driver failure', (string) $response->getContent());
        $this->assertTrue($handler->shouldReport($this->genericTyped()));
    }

    #[Test]
    public function defaultSqliteConstraintViolationStaysSanitized500(): void
    {
        // Default SQLite config cannot distinguish constraint kinds (code 19),
        // so the classifier yields the generic parent — which must NOT get the
        // 409 treatment reserved for specifically-classified unique violations.
        $raw = new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed');
        $raw->errorInfo = ['23000', 19, 'UNIQUE constraint failed: users.email'];
        $ambiguous = \Glueful\Database\Exceptions\ConstraintViolationException::fromPdo($raw, 'sqlite');

        $handler = new Handler(debug: false);
        $response = $handler->render($ambiguous);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringNotContainsString('users.email', (string) $response->getContent());
    }

    #[Test]
    public function typedDatabaseExceptionsRouteToDatabaseChannelDespiteFrameworkOrigin(): void
    {
        // fromPdo() instantiates inside framework src/, so isFrameworkException()
        // is true for these — the channel check must run FIRST or they would
        // all route to 'framework'.
        $handler = new class (debug: false) extends Handler {
            public function channelFor(\Throwable $e): string
            {
                return $this->resolveLogChannel($e);
            }
        };

        $this->assertSame('database', $handler->channelFor($this->genericTyped()));
        $this->assertSame('database', $handler->channelFor($this->uniqueViolation()));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Http/Exceptions/HandlerDatabaseExceptionsTest.php`
Expected: FAIL — 500 instead of 409, `shouldReport` true, channel `framework`.

- [ ] **Step 3: Implement the Handler changes**

In `src/Http/Exceptions/Handler.php`:

Imports — add (unique basenames; no alias needed in this file — the existing line-27
`use Glueful\Http\Exceptions\Domain\DatabaseException;` stays as-is, and the typed base
class is never referenced here by name):

```php
use Glueful\Database\Exceptions\DatabaseExceptionInterface;
use Glueful\Database\Exceptions\UniqueConstraintViolationException;
```

`$dontReport` (~line 64) — append to the list:

```php
        UniqueConstraintViolationException::class,
```

`render()` match (~line 508) — add the arm before the `default`:

```php
        return match (true) {
            $e instanceof ValidationException => $this->renderValidationException($e),
            $e instanceof PermissionUnauthorizedException => $this->renderPermissionException($e),
            $e instanceof UniqueConstraintViolationException => $this->renderUniqueConstraintViolation($e),
            $e instanceof HttpException => $this->renderHttpException($e),
            default => $this->renderGenericException($e),
        };
```

New renderer next to `renderGenericException()` (~line 566). The message is fixed in
BOTH modes: the generic renderer echoes `getMessage()` when debug is on, and a unique
violation's message carries table/column/value detail that must not leak:

```php
    /**
     * Render a unique-constraint violation as a conflict
     *
     * The message is fixed even in debug mode: the driver message leaks
     * table, column, and value detail.
     */
    protected function renderUniqueConstraintViolation(UniqueConstraintViolationException $e): Response
    {
        return new Response([
            'success' => false,
            'message' => 'A conflicting record already exists.',
            'error' => $this->buildErrorDetails($e, 409),
        ], 409);
    }
```

`resolveLogChannel()` (~line 337) — targeted first check; do NOT reorder the rest:

```php
    protected function resolveLogChannel(Throwable $e): string
    {
        // Typed database failures route to the database channel. This check
        // must precede isFrameworkException(): these exceptions are
        // constructed inside framework src/, so the origin check would
        // otherwise capture every one of them as 'framework'.
        if ($e instanceof DatabaseExceptionInterface) {
            return 'database';
        }

        if ($this->isFrameworkException($e)) {
            return 'framework';
        }

        foreach ($this->channelMap as $exceptionClass => $channel) {
            if ($e instanceof $exceptionClass) {
                return $channel;
            }
        }

        return 'error';
    }
```

- [ ] **Step 4: Run tests to verify pass**

Run: `vendor/bin/phpunit tests/Unit/Http/`
Expected: PASS — new tests plus the pre-existing `HandlerEnvelopeTest`.

---

### Task 6: Bookkeeping and final gates

**Files:**
- Modify: `CHANGELOG.md` (`[Unreleased]` section)
- Modify: `docs/DATABASE_NATIVE_ROADMAP.md` (mark items 1–2 done)

**Interfaces:**
- Consumes: everything above, finished and green.
- Produces: the release-ready record of the feature.

- [ ] **Step 1: CHANGELOG entry**

Under `## [Unreleased]` in `CHANGELOG.md`, add:

```markdown
### Added
- **Typed database exceptions** (`Glueful\Database\Exceptions`) — database failures are
  now classified at the execution boundary into a typed hierarchy rooted at
  `DatabaseException extends \PDOException` (so every existing `catch (\PDOException)`
  keeps working): `UniqueConstraintViolationException`,
  `ForeignKeyConstraintViolationException`, `NotNullConstraintViolationException` (all
  under a `ConstraintViolationException` parent), `DeadlockException`,
  `SerializationFailureException`, `LockContentionException`, and
  `ConnectionLostException`, each carrying `sqlState()` / `driverCode()` / `driver()`
  accessors with the original message, code, `errorInfo`, and exception chained as
  `previous`. A stateless `ExceptionClassifier` maps SQLSTATE plus vendor codes with
  driver-specific codes taking precedence (MySQL reports deadlocks under SQLSTATE
  40001, so vendor-first is correctness, not style); no message matching. Retryability
  is declared on the types: `RetryableTransactionFailureInterface` (deadlock,
  serialization failure, lock contention) extends `TransientFailureInterface`
  (additionally connection loss). Under default SQLite configuration all constraint
  kinds are indistinguishable and classify as the generic parent; SQLite extended
  result codes are honored when an application enables them.

### Changed
- **`TransactionManager` recognizes retryable failures by type, not by code list** —
  the mixed driver-code/SQLSTATE list is gone; the retry loop checks
  `RetryableTransactionFailureInterface`, `begin()` participates in the retry window,
  and after exhaustion the final typed failure is rethrown instead of a generic
  `Exception` (the `setMaxRetries(0)` zero-attempt edge keeps the historical generic
  exception). Retry count, backoff, and callback semantics are unchanged. `begin()`/
  `commit()`/`rollback()` classify their own PDO failures for direct callers. The
  constructor gains an optional trailing `?string $driver` (derived from the PDO when
  omitted).
- **Unique-constraint violations render as HTTP 409** with a fixed conflict message
  (in debug mode too — the driver message leaks column/value detail), are excluded
  from error reporting, and all typed database exceptions route to the `database` log
  channel.

### Fixed
- **PostgreSQL deadlocks (`40P01`) were never retried** — the old code list only
  matched serialization failure (`40001`); deadlock-victim transactions now retry.
  SQLite `SQLITE_BUSY`/`SQLITE_LOCKED` also become retryable.
```

- [ ] **Step 2: Roadmap tick**

In `docs/DATABASE_NATIVE_ROADMAP.md`, change the item 1 and item 2 headings:

```markdown
### 1. Typed database exceptions — DONE (see CHANGELOG [Unreleased])
```

```markdown
### 2. Unify retry classification — DONE (shipped with item 1)
```

Leave the body text of both sections intact as the historical rationale.

- [ ] **Step 3: Full final gates**

```bash
vendor/bin/phpunit
composer run phpcs
vendor/bin/phpstan clear-result-cache && composer run analyse
```

Expected: full suite green (1934 pre-existing + new tests), phpcs exit 0 across the
tree, `[OK] No errors`.

- [ ] **Step 4: Commit checkpoint 3**

```bash
git add src/Http/Exceptions/Handler.php tests/Unit/Http/Exceptions/HandlerDatabaseExceptionsTest.php CHANGELOG.md docs/DATABASE_NATIVE_ROADMAP.md
git commit -m "feat(http): render unique-constraint violations as 409 and route database exceptions to their log channel"
```

(The spec and this plan stay uncommitted. Never push.)

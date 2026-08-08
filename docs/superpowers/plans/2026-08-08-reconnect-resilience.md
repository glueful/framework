# Reconnect Resilience Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Automatic replay of provably-uncommitted transactions after connection loss, an explicit idempotent-read primitive, and a lazy reconnect seam — under one shared, configurable retry budget with an injectable clock; commit-ambiguous losses surface as a non-replayable exception.

**Architecture:** New `Glueful\Database\Resilience` namespace (`SleeperInterface`, `UsleepSleeper`, `RetryBudget`). `TransactionManager` consumes the budget only for retryable transaction failures and gains commit-ambiguity + rollback-precedence semantics with a presumed-dead signal. `Connection` owns connection-loss recovery: canonical key, `invalidate()`/`reconnect()`, an outermost `transaction()` wrapper, and `idempotentRead()`. `ConnectionPool` gains a discard path that never touches a dead handle.

**Tech Stack:** PHP 8.3, PDO, PHPUnit 10 attributes, PHPStan level 8 (`phpVersion: 80300`, no baseline), PSR-12.

**Spec:** `docs/superpowers/specs/2026-08-08-reconnect-resilience-design.md` — the authority; read before starting.

## Global Constraints

- Gates before every commit (full-tree, CI-parity): `vendor/bin/phpunit` && `composer run phpcs` && `vendor/bin/phpstan clear-result-cache && composer run analyse`. PHPStan level 8, no baseline/suppressions. PSR-12, 120 cols (tests excluded from phpcs).
- Work on `dev`; never push; no AI attribution; exact-path staging; never stage `CLAUDE.md` or `docs/superpowers/`; commits only at the three checkpoints.
- **One shared budget, two consumers**: `TransactionManager` consumes ONLY for `RetryableTransactionFailureInterface`; `Connection` consumes ONLY for eligible `ConnectionLostException`s; a connection loss passes through the manager without consuming; a failed reconnect consumes the next attempt; exhaustion rethrows the last classified exception unchanged.
- `max_attempts` counts total executions including the first; delays are `backoff_base_ms × failed-attempt-number`; NO sleep after the terminal failure; defaults (3, 500) produce exactly `[500, 1000]` ms.
- **Commit-phase connection loss is never replayed** — `CommitOutcomeUnknownException` (no transient markers), bookkeeping and BOTH callback collections cleared, `Connection` invalidates without reconnecting, exception propagates unmasked.
- `setMaxRetries(0)` direct-use keeps its historical zero-execution behavior; only the config path rejects `max_attempts < 1`.
- Test fault injection: synthetic losses use `errorInfo` beginning `'08006'` (SQLSTATE class 08 → `ConnectionLostException` under any driver); replay integration tests use **file-backed** SQLite (`tempnam`) so schema/data survive reconnection — `:memory:` would vanish.

---

### Task 1: Resilience primitives

**Files:**
- Create: `src/Database/Resilience/SleeperInterface.php`
- Create: `src/Database/Resilience/UsleepSleeper.php`
- Create: `src/Database/Resilience/RetryBudget.php`
- Test: `tests/Unit/Database/Resilience/RetryBudgetTest.php`

**Interfaces:**
- Produces (later tasks depend on exact names): `SleeperInterface::sleepMilliseconds(int $milliseconds): void`; `RetryBudget::fromConfig(array $config, SleeperInterface $sleeper): self` (validates; keys `max_attempts`, `backoff_base_ms`); `RetryBudget::forAttempts(int $maxAttempts, int $backoffBaseMs, SleeperInterface $sleeper): self` (same invariants; the manager handles its legacy zero edge before calling it); `tryConsume(): bool`; `attemptsUsed(): int` (**total authorized executions, including the initial one**); `maxAttempts(): int`; `lastDelayMilliseconds(): int` (the delay attached to the most recently granted retry, for observability).

- [ ] **Step 1: Write the failing test**

`tests/Unit/Database/Resilience/RetryBudgetTest.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\Resilience;

use Glueful\Database\Resilience\RetryBudget;
use Glueful\Database\Resilience\SleeperInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RetryBudgetTest extends TestCase
{
    /** @var list<int> */
    private array $sleeps = [];

    private function recordingSleeper(): SleeperInterface
    {
        $this->sleeps = [];

        return new class ($this->sleeps) implements SleeperInterface {
            /** @param list<int> $sleeps */
            public function __construct(private array &$sleeps)
            {
            }

            public function sleepMilliseconds(int $milliseconds): void
            {
                $this->sleeps[] = $milliseconds;
            }
        };
    }

    #[Test]
    public function defaultsProduceExactlyTheDocumentedDelaySequence(): void
    {
        $budget = RetryBudget::fromConfig(
            ['max_attempts' => 3, 'backoff_base_ms' => 500],
            $this->recordingSleeper()
        );

        // First execution is free (not a retry). Two retries remain.
        $this->assertTrue($budget->tryConsume());   // authorizes attempt 2, sleeps 500
        $this->assertTrue($budget->tryConsume());   // authorizes attempt 3, sleeps 1000
        $this->assertFalse($budget->tryConsume());  // exhausted — NO terminal sleep
        $this->assertSame([500, 1000], $this->sleeps);
        $this->assertSame(3, $budget->attemptsUsed());
        $this->assertSame(1000, $budget->lastDelayMilliseconds());
    }

    #[Test]
    public function zeroBackoffMeansImmediateRetriesWithNoSleepCalls(): void
    {
        $budget = RetryBudget::fromConfig(
            ['max_attempts' => 3, 'backoff_base_ms' => 0],
            $this->recordingSleeper()
        );

        $this->assertTrue($budget->tryConsume());
        $this->assertTrue($budget->tryConsume());
        $this->assertSame([], $this->sleeps, 'zero backoff must not call the sleeper at all');
    }

    #[Test]
    public function maxAttemptsOneMeansNoRetriesEver(): void
    {
        $budget = RetryBudget::fromConfig(
            ['max_attempts' => 1, 'backoff_base_ms' => 500],
            $this->recordingSleeper()
        );

        $this->assertFalse($budget->tryConsume());
        $this->assertSame([], $this->sleeps);
    }

    #[Test]
    public function configPathValidates(): void
    {
        $sleeper = $this->recordingSleeper();

        try {
            RetryBudget::fromConfig(['max_attempts' => 0, 'backoff_base_ms' => 500], $sleeper);
            $this->fail('Expected rejection of max_attempts < 1');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('max_attempts', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        RetryBudget::fromConfig(['max_attempts' => 3, 'backoff_base_ms' => -1], $sleeper);
    }

    #[Test]
    public function forAttemptsAcceptsThePostZeroGuardDirectUsePath(): void
    {
        // setMaxRetries(0) legacy semantics are handled BEFORE budget construction
        // by the manager. Therefore the factory only receives values >= 1.
        $budget = RetryBudget::forAttempts(1, 500, $this->recordingSleeper());
        $this->assertFalse($budget->tryConsume());

        $this->expectException(\InvalidArgumentException::class);
        RetryBudget::forAttempts(0, 500, $this->recordingSleeper());
    }

    #[Test]
    public function missingConfigKeysFallBackToDefaults(): void
    {
        $budget = RetryBudget::fromConfig([], $this->recordingSleeper());

        $this->assertSame(3, $budget->maxAttempts());
        $this->assertTrue($budget->tryConsume());
        $this->assertSame([500], $this->sleeps);
    }
}
```

- [ ] **Step 2: Run to verify failure** — `vendor/bin/phpunit tests/Unit/Database/Resilience/RetryBudgetTest.php` → class-not-found.

- [ ] **Step 3: Implement**

`src/Database/Resilience/SleeperInterface.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Database\Resilience;

/**
 * Clock seam for retry backoff. Production wraps usleep(); tests record
 * requested delays instead of actually waiting.
 */
interface SleeperInterface
{
    public function sleepMilliseconds(int $milliseconds): void;
}
```

`src/Database/Resilience/UsleepSleeper.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Database\Resilience;

final class UsleepSleeper implements SleeperInterface
{
    public function sleepMilliseconds(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }
}
```

`src/Database/Resilience/RetryBudget.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Database\Resilience;

/**
 * Mutable per-invocation retry budget shared by every consumer of one
 * database operation: TransactionManager consumes it for retryable
 * transaction failures, Connection for eligible connection losses. One
 * counter — changing failure type never resets the allowance.
 *
 * max_attempts counts TOTAL executions including the first; tryConsume()
 * atomically authorizes a retry and applies linear backoff
 * (backoff_base_ms × failed-attempt-number). Refusal sleeps nothing:
 * there is no delay after a terminal failure.
 */
final class RetryBudget
{
    /** The initial execution is authorized when the per-call budget is created. */
    private int $attemptsUsed = 1;
    private int $lastDelayMilliseconds = 0;

    private function __construct(
        private readonly int $maxAttempts,
        private readonly int $backoffBaseMs,
        private readonly SleeperInterface $sleeper,
    ) {
    }

    /**
     * Build from configuration, validating operator input.
     *
     * @param array<string, mixed> $config Keys: max_attempts, backoff_base_ms
     */
    public static function fromConfig(array $config, SleeperInterface $sleeper): self
    {
        $maxAttempts = (int) ($config['max_attempts'] ?? 3);
        $backoffBaseMs = (int) ($config['backoff_base_ms'] ?? 500);

        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException(
                "database retry max_attempts must be >= 1, got {$maxAttempts}"
            );
        }
        if ($backoffBaseMs < 0) {
            throw new \InvalidArgumentException(
                "database retry backoff_base_ms must be >= 0, got {$backoffBaseMs}"
            );
        }

        return new self($maxAttempts, $backoffBaseMs, $sleeper);
    }

    /**
     * Build from code-level values. The direct-use setMaxRetries(0) edge is
     * handled by TransactionManager before this factory is called, so an
     * invalid RetryBudget is never representable.
     */
    public static function forAttempts(int $maxAttempts, int $backoffBaseMs, SleeperInterface $sleeper): self
    {
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException("retry max attempts must be >= 1, got {$maxAttempts}");
        }
        if ($backoffBaseMs < 0) {
            throw new \InvalidArgumentException("retry backoff must be >= 0, got {$backoffBaseMs}");
        }

        return new self($maxAttempts, $backoffBaseMs, $sleeper);
    }

    /**
     * Atomically authorize one retry and apply its backoff delay.
     * Returns false — sleeping nothing — when the budget is exhausted.
     */
    public function tryConsume(): bool
    {
        if ($this->attemptsUsed >= $this->maxAttempts) {
            return false;
        }

        // attemptsUsed is also the one-based number of the failure that
        // authorizes this retry: 1 => 500 ms, 2 => 1000 ms at defaults.
        $this->lastDelayMilliseconds = $this->backoffBaseMs * $this->attemptsUsed;
        $this->attemptsUsed++;
        if ($this->lastDelayMilliseconds > 0) {
            $this->sleeper->sleepMilliseconds($this->lastDelayMilliseconds);
        }

        return true;
    }

    public function attemptsUsed(): int
    {
        return $this->attemptsUsed;
    }

    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function lastDelayMilliseconds(): int
    {
        return $this->lastDelayMilliseconds;
    }
}
```

- [ ] **Step 4: Run to verify pass**, then `vendor/bin/phpcs src/Database/Resilience/` and `vendor/bin/phpstan analyse src/Database/Resilience --level=8 --memory-limit=512M --no-progress` — clean. Stage exact paths; **no commit** (checkpoint 1 is Task 2's).

---

### Task 2: TransactionManager — budget, ambiguity, precedence

**Files:**
- Create: `src/Database/Exceptions/CommitOutcomeUnknownException.php`
- Modify: `src/Database/Transaction/TransactionManager.php` (ctor; `transaction()`; `commit()`; new `connectionPresumedDead()`)
- Modify: `src/Database/Transaction/Interfaces/TransactionManagerInterface.php:19` (optional param)
- Test: `tests/Unit/Database/Transaction/TransactionManagerResilienceTest.php` (new file; the existing `TransactionManagerTest.php` stays untouched except where noted)

**Interfaces:**
- Consumes: Task 1 exact names.
- Produces: `transaction(callable $callback, ?RetryBudget $budget = null): mixed`; ctor gains trailing `?SleeperInterface $sleeper = null` (defaults `UsleepSleeper`); `connectionPresumedDead(): bool`; `CommitOutcomeUnknownException extends DatabaseException` with the classified loss as previous and **all typed PDO metadata preserved** (`code`, `errorInfo`, SQLSTATE, driver code, driver name).

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Database/Transaction/TransactionManagerResilienceTest.php` — complete file:

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\Transaction;

use Glueful\Database\Exceptions\CommitOutcomeUnknownException;
use Glueful\Database\Exceptions\ConnectionLostException;
use Glueful\Database\Exceptions\DeadlockException;
use Glueful\Database\QueryLogger;
use Glueful\Database\Resilience\RetryBudget;
use Glueful\Database\Resilience\SleeperInterface;
use Glueful\Database\Transaction\Interfaces\SavepointManagerInterface;
use Glueful\Database\Transaction\TransactionManager;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TransactionManagerResilienceTest extends TestCase
{
    /** @var list<int> */
    private array $sleeps = [];

    private function sleeper(): SleeperInterface
    {
        $this->sleeps = [];

        return new class ($this->sleeps) implements SleeperInterface {
            /** @param list<int> $sleeps */
            public function __construct(private array &$sleeps)
            {
            }

            public function sleepMilliseconds(int $milliseconds): void
            {
                $this->sleeps[] = $milliseconds;
            }
        };
    }

    private function pdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    private function manager(PDO $pdo, ?SleeperInterface $sleeper = null): TransactionManager
    {
        return new TransactionManager(
            $pdo,
            $this->createMock(SavepointManagerInterface::class),
            new QueryLogger(),
            'sqlite',
            $sleeper ?? $this->sleeper()
        );
    }

    private function lossException(): \PDOException
    {
        $e = new \PDOException('server closed the connection unexpectedly');
        $e->errorInfo = ['08006', 7, 'server closed the connection unexpectedly'];

        return $e;
    }

    private function deadlock(): DeadlockException
    {
        $raw = new \PDOException('deadlock detected');
        $raw->errorInfo = ['40P01', 7, 'deadlock detected'];

        return DeadlockException::fromPdo($raw, 'pgsql');
    }

    #[Test]
    public function retryableFailuresConsumeTheProvidedBudgetWithBackoff(): void
    {
        $manager = $this->manager($this->pdo());
        $budget = RetryBudget::fromConfig(['max_attempts' => 3, 'backoff_base_ms' => 500], $this->sleeper());
        $attempts = 0;
        $deadlock = $this->deadlock();

        try {
            $manager->transaction(function () use (&$attempts, $deadlock): never {
                $attempts++;
                throw $deadlock;
            }, $budget);
            $this->fail('Expected exhaustion');
        } catch (DeadlockException $caught) {
            $this->assertSame($deadlock, $caught, 'last classified exception rethrown unchanged');
        }

        $this->assertSame(3, $attempts, 'max_attempts counts total executions');
        $this->assertSame([500, 1000], $this->sleeps, 'linear backoff, no terminal sleep');
        $this->assertSame(3, $budget->attemptsUsed());
    }

    #[Test]
    public function connectionLossPassesThroughWithoutConsumingTheBudget(): void
    {
        $manager = $this->manager($this->pdo());
        $budget = RetryBudget::fromConfig(['max_attempts' => 3, 'backoff_base_ms' => 500], $this->sleeper());
        $attempts = 0;

        try {
            $manager->transaction(function () use (&$attempts): never {
                $attempts++;
                throw $this->lossException();
            }, $budget);
            $this->fail('Expected the loss to propagate');
        } catch (ConnectionLostException) {
            $this->assertSame(1, $attempts, 'no in-manager retry for connection loss');
            $this->assertSame(1, $budget->attemptsUsed(), 'loss must not authorize another execution');
            $this->assertSame([], $this->sleeps);
        }
    }

    #[Test]
    public function setMaxRetriesZeroKeepsHistoricalZeroExecutionBehavior(): void
    {
        $manager = $this->manager($this->pdo());
        $manager->setMaxRetries(0);
        $invoked = false;

        try {
            $manager->transaction(function () use (&$invoked): string {
                $invoked = true;

                return 'unreachable';
            });
            $this->fail('Expected the historical exhaustion exception');
        } catch (\Exception $e) {
            $this->assertFalse($invoked);
            $this->assertStringContainsString('after 0 retries', $e->getMessage());
        }
    }

    #[Test]
    public function nullBudgetHonorsSetMaxRetriesViaALocalBudget(): void
    {
        $sleeper = $this->sleeper();
        $manager = $this->manager($this->pdo(), $sleeper);
        $manager->setMaxRetries(2);
        $attempts = 0;

        try {
            $manager->transaction(function () use (&$attempts): never {
                $attempts++;
                throw $this->deadlock();
            });
            $this->fail('Expected exhaustion');
        } catch (DeadlockException) {
            $this->assertSame(2, $attempts, 'setMaxRetries(2) = two total executions');
            $this->assertSame([500], $this->sleeps);
        }
    }

    #[Test]
    public function errorsFromCallbacksRollBackAndPropagate(): void
    {
        $pdo = $this->pdo();
        $manager = $this->manager($pdo);

        try {
            $manager->transaction(static function (): never {
                throw new \TypeError('boom');
            });
            $this->fail('Expected the Error to propagate');
        } catch (\TypeError $e) {
            $this->assertSame('boom', $e->getMessage());
            $this->assertFalse($manager->isActive(), 'transaction must have been rolled back');
            $this->assertFalse($pdo->inTransaction());
        }
    }

    #[Test]
    public function commitPhaseLossBecomesCommitOutcomeUnknownAndClearsEverything(): void
    {
        $pdo = new class ('sqlite::memory:') extends PDO {
            public bool $failCommit = false;

            public function __construct(string $dsn)
            {
                parent::__construct($dsn);
                $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            }

            public function commit(): bool
            {
                if ($this->failCommit) {
                    $e = new \PDOException('server closed the connection unexpectedly');
                    $e->errorInfo = ['08006', 7, 'connection lost during commit'];
                    throw $e;
                }

                return parent::commit();
            }
        };
        $manager = $this->manager($pdo);
        $budget = RetryBudget::fromConfig(['max_attempts' => 3, 'backoff_base_ms' => 0], $this->sleeper());
        $commitCallbackRan = false;
        $rollbackCallbackRan = false;
        $attempts = 0;

        try {
            $manager->transaction(function () use (
                $manager,
                $pdo,
                &$commitCallbackRan,
                &$rollbackCallbackRan,
                &$attempts
            ): string {
                $attempts++;
                $manager->afterCommit(function () use (&$commitCallbackRan): void {
                    $commitCallbackRan = true;
                });
                $manager->afterRollback(function () use (&$rollbackCallbackRan): void {
                    $rollbackCallbackRan = true;
                });
                $pdo->failCommit = true;

                return 'value';
            }, $budget);
            $this->fail('Expected CommitOutcomeUnknownException');
        } catch (CommitOutcomeUnknownException $e) {
            $this->assertSame(1, $attempts, 'commit ambiguity must never replay');
            $this->assertSame(1, $budget->attemptsUsed());
            $this->assertInstanceOf(ConnectionLostException::class, $e->getPrevious());
            $this->assertSame('08006', $e->sqlState());
            $this->assertSame(7, $e->driverCode());
            $this->assertSame('sqlite', $e->driver());
            $this->assertFalse($commitCallbackRan, 'neither callback outcome is inferable');
            $this->assertFalse($rollbackCallbackRan);
            $this->assertFalse($manager->isActive(), 'bookkeeping cleared');
            $this->assertTrue($manager->connectionPresumedDead());
        }
    }

    #[Test]
    public function commitPhaseNonLossFailureStaysUnwrapped(): void
    {
        // A commit failure that is NOT a connection loss (e.g. deferred-FK
        // violation) keeps its classified type — ambiguity wrapping is
        // strictly for losses.
        $pdo = new class ('sqlite::memory:') extends PDO {
            public bool $failCommit = false;

            public function __construct(string $dsn)
            {
                parent::__construct($dsn);
                $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            }

            public function commit(): bool
            {
                if ($this->failCommit) {
                    $e = new \PDOException('constraint failed at commit');
                    $e->errorInfo = ['23000', 19, 'constraint failed'];
                    throw $e;
                }

                return parent::commit();
            }
        };
        $manager = $this->manager($pdo);

        try {
            $manager->transaction(function () use ($pdo): string {
                $pdo->failCommit = true;

                return 'x';
            });
            $this->fail('Expected the constraint failure');
        } catch (CommitOutcomeUnknownException) {
            $this->fail('Non-loss commit failures must not be wrapped as outcome-unknown');
        } catch (\PDOException $e) {
            $this->assertStringNotContainsString('outcome', strtolower($e->getMessage()));
            $this->assertFalse($manager->connectionPresumedDead());
        }
    }

    #[Test]
    public function afterCommitCallbackThrowingClassifiedLossDoesNotEscape(): void
    {
        // Pinned contract: the commit is durable before callbacks run, and
        // executeCallbacks() logs-and-swallows — a loss thrown by a callback
        // must not escape commit() and must never look replayable.
        $manager = $this->manager($this->pdo());

        $result = $manager->transaction(function () use ($manager): string {
            $manager->afterCommit(function (): never {
                throw ConnectionLostException::fromPdo(
                    (static function (): \PDOException {
                        $e = new \PDOException('lost after commit');
                        $e->errorInfo = ['08006', 7, 'lost after commit'];

                        return $e;
                    })(),
                    'sqlite'
                );
            });

            return 'committed';
        });

        $this->assertSame('committed', $result);
        $this->assertFalse($manager->isActive());
    }

    #[Test]
    public function rollbackLossWithRetryablePrimarySurfacesTheLoss(): void
    {
        $pdo = new class ('sqlite::memory:') extends PDO {
            public bool $failRollback = false;

            public function __construct(string $dsn)
            {
                parent::__construct($dsn);
                $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            }

            public function rollBack(): bool
            {
                if ($this->failRollback) {
                    $e = new \PDOException('connection gone during rollback');
                    $e->errorInfo = ['08006', 7, 'gone'];
                    throw $e;
                }

                return parent::rollBack();
            }
        };
        $manager = $this->manager($pdo);
        $budget = RetryBudget::fromConfig(['max_attempts' => 3, 'backoff_base_ms' => 0], $this->sleeper());
        $attempts = 0;

        try {
            $manager->transaction(function () use ($pdo, &$attempts): never {
                $attempts++;
                $pdo->failRollback = true;
                throw $this->deadlock();
            }, $budget);
            $this->fail('Expected the surfaced connection loss');
        } catch (ConnectionLostException) {
            $this->assertSame(1, $attempts, 'manager must NOT retry on the dead handle');
            $this->assertSame(1, $budget->attemptsUsed(), 'loss consumption belongs to Connection');
            $this->assertFalse($manager->isActive(), 'bookkeeping reset — server rolls back on disconnect');
            $this->assertTrue($manager->connectionPresumedDead());
        }
    }

    #[Test]
    public function rollbackLossWithNonRetryablePrimaryPreservesThePrimary(): void
    {
        $pdo = new class ('sqlite::memory:') extends PDO {
            public bool $failRollback = false;

            public function __construct(string $dsn)
            {
                parent::__construct($dsn);
                $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            }

            public function rollBack(): bool
            {
                if ($this->failRollback) {
                    $e = new \PDOException('connection gone during rollback');
                    $e->errorInfo = ['08006', 7, 'gone'];
                    throw $e;
                }

                return parent::rollBack();
            }
        };
        $manager = $this->manager($pdo);
        $domain = new \RuntimeException('domain failure');

        try {
            $manager->transaction(function () use ($pdo, $domain): never {
                $pdo->failRollback = true;
                throw $domain;
            });
            $this->fail('Expected the domain failure');
        } catch (\RuntimeException $caught) {
            $this->assertSame($domain, $caught, 'primary preserved');
            $this->assertTrue($manager->connectionPresumedDead(), 'dead connection flagged for invalidation');
            $this->assertFalse($manager->isActive());
        }
    }

    #[Test]
    public function rollbackLossWithLossPrimaryPreservesThePrimaryLoss(): void
    {
        $pdo = new class ('sqlite::memory:') extends PDO {
            public bool $failRollback = false;

            public function __construct(string $dsn)
            {
                parent::__construct($dsn);
                $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            }

            public function rollBack(): bool
            {
                if ($this->failRollback) {
                    $e = new \PDOException('second loss');
                    $e->errorInfo = ['08006', 7, 'second loss'];
                    throw $e;
                }

                return parent::rollBack();
            }
        };
        $manager = $this->manager($pdo);
        $primary = ConnectionLostException::fromPdo($this->lossException(), 'sqlite');

        try {
            $manager->transaction(function () use ($pdo, $primary): never {
                $pdo->failRollback = true;
                throw $primary;
            });
            $this->fail('Expected the primary loss');
        } catch (ConnectionLostException $caught) {
            $this->assertSame($primary, $caught, 'primary loss preserved over the secondary');
        }
    }

    #[Test]
    public function nonLossRollbackFailureResetsBookkeepingWithoutFlaggingTheConnection(): void
    {
        // A rollback that reports a NON-loss error still leaves this manager's
        // transactionLevel unreset (rollback() threw before its own reset), so
        // the guard must clear bookkeeping on every rollback-failure branch.
        $pdo = new class ('sqlite::memory:') extends PDO {
            public bool $failRollback = false;

            public function __construct(string $dsn)
            {
                parent::__construct($dsn);
                $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            }

            public function rollBack(): bool
            {
                if ($this->failRollback) {
                    $this->failRollback = false;
                    parent::rollBack();
                    $e = new \PDOException('rollback reported a driver error');
                    $e->errorInfo = ['HY000', 1, 'rollback reported a driver error'];
                    throw $e;
                }

                return parent::rollBack();
            }
        };
        $manager = $this->manager($pdo);
        $domain = new \RuntimeException('domain failure');

        try {
            $manager->transaction(function () use ($pdo, $domain): never {
                $pdo->failRollback = true;
                throw $domain;
            });
            $this->fail('Expected the domain failure');
        } catch (\RuntimeException $caught) {
            $this->assertSame($domain, $caught, 'primary preserved');
            $this->assertFalse($manager->isActive(), 'bookkeeping reset despite the failed rollback');
            $this->assertFalse(
                $manager->connectionPresumedDead(),
                'a non-loss rollback failure must not flag the handle as dead'
            );
        }

        // The next transaction on the SAME manager must begin at level 1 —
        // a stale level would send begin() down the savepoint path.
        $observedLevels = [];
        $manager->transaction(function () use ($manager, &$observedLevels): string {
            $observedLevels[] = $manager->getLevel();

            return 'ok';
        });
        $this->assertSame([1], $observedLevels);
    }
}
```

- [ ] **Step 2: Run to verify failures** — new ctor param / class / accessor missing.

- [ ] **Step 3: Implement**

`src/Database/Exceptions/CommitOutcomeUnknownException.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Database\Exceptions;

/**
 * The connection was lost while COMMIT was in flight: the server may have
 * committed before the acknowledgement was lost. Replaying could duplicate
 * writes, so this failure is NEVER retried automatically — it implements
 * no transient marker. The classified loss is chained as previous.
 */
final class CommitOutcomeUnknownException extends DatabaseException
{
    public static function fromLoss(ConnectionLostException $loss): self
    {
        $exception = new self(
            'Transaction commit outcome unknown: the connection was lost while COMMIT was in flight. '
            . 'The transaction may or may not have committed; it was NOT replayed.',
            0,
            $loss
        );
        // \Exception's constructor only accepts int codes; PDO uses string
        // SQLSTATEs. The annotated intermediate keeps level 8 from seeing a
        // mixed assignment — exactly DatabaseException::fromPdo's pattern.
        /** @var int|string $originalCode */
        $originalCode = $loss->getCode();
        $exception->code = $originalCode;
        $exception->errorInfo = $loss->errorInfo;
        $exception->sqlStateValue = $loss->sqlState();
        $exception->driverCodeValue = $loss->driverCode();
        $exception->driverName = $loss->driver();

        return $exception;
    }
}
```

(Note: `DatabaseException::__construct` is `final` — this signature matches it. `fromLoss` mirrors `fromPdo`'s complete preservation pattern, including the typed metadata accessors; preserving only `code`/`errorInfo` is insufficient.)

`TransactionManager` changes (the brief text below is the contract; the current method bodies are in the file — modify surgically):

1. **Ctor**: trailing `?SleeperInterface $sleeper = null`, stored as `$this->sleeper = $sleeper ?? new UsleepSleeper();`. Add `private bool $connectionPresumedDead = false;` and `public function connectionPresumedDead(): bool`.
2. **`transaction(callable $callback, ?RetryBudget $budget = null): mixed`** (interface updated to match, docblock explains the param):
   - Zero-edge first: `if ($budget === null && $this->maxRetries === 0) { … historical path … }` — log the start event, then throw the historical `new Exception("Transaction failed after 0 retries due to deadlock.")` without invoking the callback (exactly today's observable behavior).
   - `$budget ??= RetryBudget::forAttempts($this->maxRetries, 500, $this->sleeper);` — local budget honoring `setMaxRetries()`, 500 ms base (today's constant).
   - Replace the `while ($retryCount < $this->maxRetries)` loop with `do { … } while` driven by the budget: run one attempt; on `RetryableTransactionFailureInterface`, first call `if (!$budget->tryConsume()) { break; }`; only after a retry is granted, emit the existing "deadlock detected, retrying" event, then loop. This prevents a false "retrying" event at exhaustion. The `usleep()` call is deleted — the budget sleeps. That event's context changes with its source: the old `'retry' => $retryCount` key is **renamed to `'attempt' => $budget->attemptsUsed()`** (the budget reports the number of the upcoming attempt, not a retry counter — keeping the old key would mislabel the value), and `'delay_ms' => $budget->lastDelayMilliseconds()` is added.
   - Catch **`\Throwable`** instead of `Exception` (classification of raw `PDOException` stays as-is; non-PDO `Throwable`s take the non-retryable path).
   - **Rollback guard with precedence**: replace the two bare `$this->rollback()` calls in the catch path with a private `rollbackForFailure(\Throwable $primary): ?\Throwable` implementing exactly:
     ```php
     private function rollbackForFailure(\Throwable $primary): ?\Throwable
     {
         try {
             $this->rollback();

             return null;
         } catch (\PDOException $rollbackFailure) {
             $classified = $rollbackFailure instanceof DatabaseException
                 ? $rollbackFailure
                 : $this->classifier->classify($rollbackFailure, $this->driver);

             if (!$classified instanceof ConnectionLostException) {
                 // Non-loss rollback failure: rollback() threw BEFORE reaching
                 // its own `transactionLevel = 0`, so this manager's transaction
                 // state is unknown. Reset bookkeeping anyway — otherwise the
                 // retryable branch would retry at level 1 (begin() takes the
                 // savepoint path against a possibly-nonexistent transaction)
                 // and a later loss primary would trip Connection's reconnect
                 // guard and be masked by a LogicException. The connection may
                 // well be alive, so do NOT set connectionPresumedDead — only
                 // loss-classified rollback failures flag the handle.
                 $this->transactionLevel = 0;
                 $this->commitCallbacks = [];
                 $this->rollbackCallbacks = [];
                 $this->logger->logEvent(
                     'Rollback failed while handling a transaction failure',
                     ['primary' => $primary->getMessage(), 'secondary' => $classified->getMessage()],
                     'error'
                 );

                 return null; // preserve the primary
             }

             // The connection died during rollback. The server rolls back on
             // disconnect; reset bookkeeping and flag the dead handle.
             $this->transactionLevel = 0;
             $this->commitCallbacks = [];
             $this->rollbackCallbacks = [];
             $this->connectionPresumedDead = true;

             if ($primary instanceof ConnectionLostException) {
                 $this->logger->logEvent(
                     'Connection also lost during rollback; preserving primary loss',
                     ['secondary' => $classified->getMessage()],
                     'warning'
                 );

                 return null; // rule 1: preserve primary loss
             }
             if ($primary instanceof RetryableTransactionFailureInterface) {
                 $this->logger->logEvent(
                     'Retryable failure superseded by connection loss during rollback',
                     ['primary' => $primary->getMessage()],
                     'warning'
                 );

                 return $classified; // rule 3: surface the loss to Connection
             }
             $this->logger->logEvent(
                 'Connection lost during rollback; preserving non-retryable primary',
                 ['primary' => $primary->getMessage(), 'secondary' => $classified->getMessage()],
                 'error'
             );

             return null; // rule 2: preserve primary, dead handle flagged
         }
     }
     ```
     In the retryable branch: `$superseding = $this->rollbackForFailure($e); if ($superseding !== null) { throw $superseding; }` then the retry-consume logic. In the non-retryable branch: `$this->rollbackForFailure($e);` then the existing failure log + `throw $e;`.
   - Connection loss from the callback/begin (already classified by the existing defensive classification) is **not** retryable here — it takes the non-retryable branch (rollback guarded, primary rethrown) and thus passes through without consuming the budget. ✔ spec.
3. **`commit()`** — the level-1 branch's existing classify-and-throw wrap changes to:
   ```php
   try {
       $this->pdo->commit();
   } catch (\PDOException $e) {
       $classified = $e instanceof DatabaseException ? $e : $this->classifier->classify($e, $this->driver);
       if ($classified instanceof ConnectionLostException) {
           // Ambiguous outcome: the server may have committed before the
           // acknowledgement was lost. Never replayable; nothing about
           // either callback set can be inferred.
           $this->transactionLevel = 0;
           $this->commitCallbacks = [];
           $this->rollbackCallbacks = [];
           $this->connectionPresumedDead = true;
           throw CommitOutcomeUnknownException::fromLoss($classified);
       }
       throw $classified;
   }
   ```
   The success path is untouched: `transactionLevel = 0` is already set before `executeCallbacks()` runs (commit durable before callbacks — the pinned contract; `executeCallbacks()` at line 337 already logs-and-swallows `Throwable`).
4. PHPStan notes: import `RetryableTransactionFailureInterface`, `ConnectionLostException`, `CommitOutcomeUnknownException`, `RetryBudget`, `SleeperInterface`, `UsleepSleeper`. `$this->maxRetries === 0` zero-edge must come before budget construction.

- [ ] **Step 4: Run** `vendor/bin/phpunit tests/Unit/Database/Transaction/` — the new file green AND the pre-existing `TransactionManagerTest.php` green unchanged (its `maxRetries=0`, exhaustion-`assertSame`, non-PDO passthrough, and begin-retry tests pin the preserved semantics; the two tests asserting sleep behavior implicitly speed up — if any existing test asserts the terminal sleep, that assertion is a behavior contradiction: STOP and report, do not edit silently).

- [ ] **Step 5: Full gates + Commit checkpoint 1**

```bash
git add src/Database/Resilience/SleeperInterface.php src/Database/Resilience/UsleepSleeper.php src/Database/Resilience/RetryBudget.php src/Database/Exceptions/CommitOutcomeUnknownException.php src/Database/Transaction/TransactionManager.php src/Database/Transaction/Interfaces/TransactionManagerInterface.php tests/Unit/Database/Resilience/RetryBudgetTest.php tests/Unit/Database/Transaction/TransactionManagerResilienceTest.php
git commit -m "feat(database): shared retry budget, commit-ambiguity, and rollback precedence in transactions"
```

---

### Task 3: Connection — canonical key, invalidate/reconnect, wrapper, idempotentRead

**Files:**
- Modify: `src/Database/Connection.php` (ctor cache ~185-197; `getPDO()` fallback ~553-560; `transaction()` ~912; `getTransactionManager()` ~819; new methods)
- Modify: `src/Database/ConnectionPool.php` (new `discard()`)
- Test: `tests/Unit/Database/ConnectionResilienceTest.php`

**Interfaces:**
- Consumes: Tasks 1–2 exact names.
- Produces: `Connection::reconnect(): void`; `Connection::idempotentRead(callable $fn): mixed`; `Connection::transaction(callable $callback): mixed` (signature unchanged, now the replay wrapper); `ConnectionPool::discard(PooledConnection $connection): void`.

Key implementation contract (each block a discrete edit):

1. **Owned resilience logger and non-lazy lifecycle inspection.** Add one memoized `QueryLogger` property initialized as `new QueryLogger(null, $this->context)` in the constructor; `Connection` resilience events and its memoized `TransactionManager` use this instance. Do not claim that `Connection` already owns a logger — today it creates independent local instances. Add `currentPdo(): ?PDO`, which returns the existing pooled handle's PDO or the initialized non-pooled `$pdo` **without acquiring/creating anything**, and `hasActiveTransaction(): bool`, which checks the existing manager and that current raw PDO. The pooled PDO must be checked too. If native `inTransaction()` itself throws a classified connection loss, the handle is already unusable and may be invalidated; it must not trigger lazy construction.
2. **Canonical key.** Private `connectionKey(): string` returning the ctor's full-identity key (`engine|dsn|user|schema`). The ctor cache block and the `getPDO()` legacy fallback both use it (the fallback's engine-only `self::$instances[$this->engine]` reads/writes are replaced). **Both of the ctor's existing exclusions must survive the unification, in both places** — the guard is the ctor's `$this->engine === 'sqlite' || $this->context === null` (`src/Database/Connection.php:184-197`), not SQLite alone. SQLite is excluded because a `:memory:` database is private to each connection; AD-HOC connections (built without an `ApplicationContext`) are excluded because, as that ctor comment spells out, collapsing hand-built sessions into one shared handle turns session-level semantics — advisory locks, transactions — into self-interactions and deadlocks the caller. An excluded connection bypasses the cache **read AND the cache write** on both paths: it always opens a fresh backend and never publishes it. Factor the predicate once (e.g. a private `sharesStaticHandle(): bool`) so the ctor and the fallback cannot drift apart again.
3. **`invalidate()`** (private): capture `$deadPdo = $this->currentPdo()` first. If pooled — `$this->pool->discard($this->pooledConnection)` when non-null, then `$this->pooledConnection = null` (so `__destruct` and `getPDO()` cannot touch the dead handle; next `getPDO()` acquires fresh). If non-pooled — remove `self::$instances[$key]` **only when it still references the same `$deadPdo`** (do not evict a replacement installed by another `Connection`), then `unset($this->pdo)`. The identity-safe purge is inherently a no-op for the item-2 exclusions: SQLite and ad-hoc (`$this->context === null`) connections never publish to the static cache, so there is nothing keyed to them to remove — and after `unset($this->pdo)` the lazy fallback must not let them adopt someone else's cached handle either. Always set `$this->transactionManager = null;`. Log `connection.invalidated` through the owned resilience logger.
4. **`reconnect()`** (public): refuse when `hasActiveTransaction()` is true; this guard must not call `transactionLevel()`, `getTransactionManager()`, or `getPDO()` because those are lazy and could create a fresh handle merely to discard it. Then `invalidate()` and call `getPDO()` once to establish. Classify any raw `PDOException` from establishment with `ExceptionClassifier`; log attempt/success/failure without masking the classified exception.
5. **`ConnectionPool::discard()`**: `unset($this->activeConnections[$connection->getId()])`; `$connection->markUnhealthy()`; `$this->destroyConnection($connection)`; increment `total_discards`. Add `total_discards => 0` to the stats initializer so incrementing it never reads an undefined key and `getStats()` exposes it. NO `rollbackOpenTransaction`, NO `resetSession` — the handle is presumed dead and must not be touched.
6. **One explicit reconnect-recovery loop.** Add a private helper (exact name at implementation discretion) with this contract:
   ```php
   private function reconnectWithinBudget(
       RetryBudget $budget,
       ConnectionLostException $lastLoss,
       string $surface
   ): void {
       while (true) {
           if (!$budget->tryConsume()) {
               throw $lastLoss; // exact final classified failure, unchanged
           }

           $this->resilienceLogger->logEvent(
               'connection.reconnect.attempt',
               [
                   'surface' => $surface,
                   'attempt' => $budget->attemptsUsed(),
                   'delay_ms' => $budget->lastDelayMilliseconds(),
               ],
               'warning'
           );

           try {
               $this->reconnect();
               $this->resilienceLogger->logEvent(
                   'connection.reconnect.success',
                   ['surface' => $surface, 'attempt' => $budget->attemptsUsed()],
                   'info'
               );

               return;
           } catch (ConnectionLostException $reconnectLoss) {
               $lastLoss = $reconnectLoss;
               $this->resilienceLogger->logEvent(
                   'connection.reconnect.failure',
                   ['surface' => $surface, 'attempt' => $budget->attemptsUsed()],
                   'warning'
               );
               // Loop back: the NEXT reconnect is gated by another tryConsume().
           }
       }
   }
   ```
   This helper fixes the failed-reconnect hole: a failed establishment never falls through to an unguarded `getTransactionManager()`/callback invocation, and every recovery cycle consumes exactly one shared attempt. A non-connection establishment failure propagates immediately.
7. **`transaction()` wrapper** — full replacement:
   ```php
   public function transaction(callable $callback): mixed
   {
       // Nested call (or an outer wrapper already active): delegate with the
       // active budget — a null here would mint a second retry allowance.
       if ($this->activeRetryBudget !== null || $this->transactionManager?->isActive() === true) {
           return $this->getTransactionManager()->transaction($callback, $this->activeRetryBudget);
       }

       $retryConfig = $this->config['retry'] ?? [];
       $budget = RetryBudget::fromConfig(
           is_array($retryConfig) ? $retryConfig : [],
           new UsleepSleeper()
       );
       $this->activeRetryBudget = $budget;

       try {
           while (true) {
               $manager = null;
               try {
                   // Manager construction is inside the catch boundary: on a
                   // pooled/lazy connection it may itself detect connection loss.
                   $manager = $this->getTransactionManager();
                   return $manager->transaction($callback, $budget);
               } catch (\Throwable $failure) {
                   // ONE arm, deliberately. Invalidation FIRST, dispatch second:
                   // every classified database failure here is a PDOException
                   // subclass, so type-ordered arms cannot separate them safely.
                   if ($manager?->connectionPresumedDead() === true) {
                       $this->invalidate();
                   }

                   $loss = null;
                   if ($failure instanceof ConnectionLostException) {
                       $loss = $failure;
                   } elseif ($failure instanceof \PDOException && !$failure instanceof DatabaseException) {
                       // Defensive boundary for a RAW failure during lazy
                       // manager/PDO construction — the only unclassified
                       // shape that reaches here. Manager callback failures
                       // classify internally.
                       $classified = (new ExceptionClassifier())->classify($failure, $this->getDriverName());
                       if (!$classified instanceof ConnectionLostException) {
                           throw $classified;
                       }
                       $loss = $classified;
                   }

                   if ($loss === null) {
                       // Already-classified non-loss failures — including
                       // CommitOutcomeUnknownException and rule-2 primaries —
                       // propagate unchanged, unmasked, never replayed. The
                       // dead handle was already dropped above.
                       throw $failure;
                   }

                   $this->reconnectWithinBudget($budget, $loss, 'transaction');
                   $this->resilienceLogger->logEvent(
                       'connection.transaction.replay',
                       ['attempt' => $budget->attemptsUsed()],
                       'warning'
                   );
                   // Fall out of the catch: the while(true) loop replays.
               }
           }
       } finally {
           $this->activeRetryBudget = null;
       }
   }
   ```
   Notes for the implementer: `activeRetryBudget` is a new `private ?RetryBudget` property; `DatabaseException` joins `ConnectionLostException`/`ExceptionClassifier` in the imports. **The single `catch (\Throwable)` is load-bearing.** `CommitOutcomeUnknownException extends DatabaseException extends \PDOException`, so with type-ordered arms (`ConnectionLostException` → `\PDOException` → `\Throwable`) a commit-unknown would land in the `\PDOException` arm, where `ExceptionClassifier::classify()` returns already-classified instances unchanged — and it would be rethrown WITHOUT the presumed-dead invalidation. Rule-2 non-retryable primaries that are `PDOException`s have exactly the same problem. Collapsing to one arm makes the presumed-dead invalidation run first for every failure type, before any type dispatch. So commit-unknown is: invalidated via the presumed-dead check, never consumed, never replayed, propagated unmasked — and the `instanceof DatabaseException` guard on the classify branch keeps it (and every other already-classified failure) out of the classifier entirely. Invalidate-before-reconnect ordering is preserved — `reconnectWithinBudget()` → `reconnect()`, which is invalidate + establish — and no invalidation belongs in `finally`, because that could destroy a successfully reconnected handle.
8. **`getTransactionManager()`** — pass the sleeper and the owned logger: construct `TransactionManager(..., $this->getDriverName(), new UsleepSleeper())` with `$this->resilienceLogger` rather than constructing another `QueryLogger`.
9. **`idempotentRead()`**:
   ```php
   public function idempotentRead(callable $fn): mixed
   {
       if ($this->hasActiveTransaction()) {
           throw new \LogicException(
               'idempotentRead() cannot run inside a transaction: a reconnect would abandon it'
           );
       }

       $retryConfig = $this->config['retry'] ?? [];
       $budget = RetryBudget::fromConfig(
           is_array($retryConfig) ? $retryConfig : [],
           new UsleepSleeper()
       );

       while (true) {
           try {
               return $fn($this);
           } catch (ConnectionLostException $loss) {
               $this->reconnectWithinBudget($budget, $loss, 'idempotent_read');
           }
       }
   }
   ```
10. **Config**: add to `config/database.php` (top level, beside `pooling`):
   ```php
   // Database-operation retry budget (transaction replay after connection loss,
   // deadlock retries, idempotentRead). DISTINCT from pooling.retry_attempts,
   // which governs connection ACQUISITION, not operation recovery.
   'retry' => [
       'max_attempts' => (int) env('DB_RETRY_MAX_ATTEMPTS', 3),
       'backoff_base_ms' => (int) env('DB_RETRY_BACKOFF_MS', 500),
   ],
   ```
   `Connection::$config` is the WHOLE database config array, not a per-engine slice — the ctor sets `$this->config = array_merge($this->loadConfig(), $config);` (`src/Database/Connection.php:146`), and per-engine values are read as `$this->config[$this->engine]`. So a top-level `retry` block reaches `$this->config['retry']` directly, exactly as `pooling` reaches `$this->config['pooling']['enabled']`; no threading is required. Both read sites narrow it before handing it to `RetryBudget::fromConfig()` (level 8: `mixed` into an `array` parameter). `.env.example` gains the two keys with comments.

- [ ] **Step 1: Write the failing tests** — `tests/Unit/Database/ConnectionResilienceTest.php`. **Documented deviation (same pattern as prior plans): the tests below are intent-skeletons — each names its exact scenario and assertions; write every one fully at implementation time.** Fixture: file-backed SQLite via the `SchemaBuilderAlterIndexTest::sqliteConnection()` transcription pattern (engine/sqlite.primary/pooling.enabled=false + a `retry` config block with `backoff_base_ms => 0` so tests never sleep). A fault-injection seam: since `Connection` creates real PDOs internally, inject losses by running callbacks that throw synthetic classified losses (`errorInfo ['08006', …]` raw `\PDOException` thrown from inside `table()`-executed SQL is not injectable — instead the callback itself throws `ConnectionLostException::fromPdo(...)` on the first N invocations, then succeeds). Tests (write fully):
  - `outermostTransactionReplaysAfterConnectionLoss`: callback throws a classified loss on invocation 1, succeeds on invocation 2 writing a row; assert the row exists, 2 invocations, and a fresh `table()` chain works after.
  - `replayExhaustionRethrowsTheLastLossUnchanged`: always-throwing callback with `max_attempts => 2`; `assertSame` on the caught instance; 2 invocations.
  - `nestedTransactionCallsShareOneBudget`: outer `Connection::transaction` whose callback calls `$connection->transaction(...)` (nested) where the INNER callback throws a deadlock (classified, `40P01`) every time; with `max_attempts => 3` assert exactly 3 total inner executions (not 9 — no allowance multiplication) and the deadlock surfaces.
  - `sharedBudgetSpansExceptionTypes`: callback throws deadlock on invocation 1 (manager consumes), classified loss on invocation 2 (Connection consumes), succeeds on invocation 3; `max_attempts => 3` → success with zero budget left; then the same sequence with `max_attempts => 2` → the loss on invocation 2 exhausts and rethrows.
  - `commitOutcomeUnknownPropagatesWithoutReplayAndInvalidates`: exercise the **real manager commit path**, not a callback that merely throws `CommitOutcomeUnknownException` (that shortcut never sets `connectionPresumedDead` and cannot prove invalidation). Use the Task 2 faulting file-backed SQLite PDO and `Closure::bind`/reflection to install that PDO plus a matching `TransactionManager` into the `Connection`; fail level-1 `commit()`, then assert the new exception propagates unchanged, callback invocation count is 1, the stale PDO/manager were invalidated, and the next operation lazily binds a different PDO. No production-only injection API is added.
  - `failedReconnectConsumesEveryAttempt`: use an anonymous `Connection` test subclass whose overridden public `reconnect()` throws distinct classified losses for successive calls. With `max_attempts => 3`, assert exactly two reconnect calls, the final reconnect loss is rethrown by identity, and no unguarded manager/callback invocation occurs between failures.
  - `idempotentReadReplaysAndReturns` / `idempotentReadRefusesInsideTransaction` / `idempotentReadExhaustionRethrows`.
  - `reconnectRefusesMidTransaction` (`\LogicException`) for both manager-tracked transactions and a raw transaction on the current **pooled** PDO; assert the guard performs no acquisition or replacement.
  - `reconnectSurvivesSchemaAndData` (file-backed: create table + row, `reconnect()`, fresh `table()` chain reads the row).
  - `canonicalKeyUnifiesConstructorAndFallbackCaches` — extract the full-identity calculation into the one `connectionKey()` method and assert its deterministic output with a reflection-created, unconnected `Connection` whose required config/engine fields are populated directly (no MySQL connection). A focused source-level assertion/test then pins that both constructor and lazy fallback call this method rather than indexing `self::$instances` independently; do not substitute a SQLite cache test, because SQLite deliberately bypasses sharing and cannot prove cache consistency.
  - `invalidateDoesNotEvictANewerSharedHandle`: seed the static cache key with a replacement PDO different from the instance's dead PDO, invoke invalidation through the bound test seam, and assert the replacement remains cached.
  - `adHocConnectionNeverAdoptsACachedSharedHandle`: pins the item-2 ad-hoc exclusion on the unified path. With the same reflection-created, unconnected fixture as the canonical-key test (no `ApplicationContext`, non-SQLite engine, no server contacted), seed `self::$instances` under that instance's `connectionKey()` with a foreign PDO and assert the sharing predicate reports false — neither the ctor nor the post-invalidate lazy fallback may read or overwrite that entry. Pair it with a real ad-hoc file-backed `Connection` driven through `invalidate()` + `reconnect()` via the bound test seam: its PDO must be a fresh instance and `self::$instances` must gain no entry, proving an excluded connection opens a private backend instead of adopting or publishing a shared one.
  - `poolDiscardNeverTouchesTheDeadHandle`: unit-level `ConnectionPool::discard()` test with a stub `PooledConnection` — after discard, the handle is destroyed, absent from available/active sets, no rollback/reset was attempted (spy PDO asserting zero calls), and `getStats()['total_discards']` increments from its initialized zero value.
- [ ] **Step 2–3: implement per the contract above; run** `vendor/bin/phpunit tests/Unit/Database/` (pre-existing `ConnectionNewPdoTest`, `ConnectionPoolReleaseTest`, `ConnectionTableHookTest` must stay green — the canonical-key change touches the fallback those may exercise).
- [ ] **Step 4: Full gates + Commit checkpoint 2**

```bash
git add src/Database/Connection.php src/Database/ConnectionPool.php config/database.php .env.example tests/Unit/Database/ConnectionResilienceTest.php
git commit -m "feat(database): connection-loss replay, idempotent reads, and lazy reconnect on Connection"
```

---

### Task 4: Bookkeeping and final gates

**Files:**
- Modify: `CHANGELOG.md` (`[Unreleased]` — merge into existing sections)
- Modify: `docs/DATABASE_NATIVE_ROADMAP.md` (item 4 → DONE; roadmap-complete note)
- Modify: `src/Database/QueryBuilder.php:833` (docblock only — no reconnect capability, use `Connection::transaction()` for replay)

- [ ] **Step 1: CHANGELOG** — merge under the existing `## [Unreleased]` sections:

```markdown
### Added
- **Reconnect resilience** (`Glueful\Database\Resilience`) — the database layer now
  recovers from connection loss at the two provably-safe boundaries. Outermost
  `Connection::transaction()` calls replay the callback after a connection loss the
  framework can prove uncommitted (begin- or callback-phase; the server rolls back on
  disconnect) — the same replay contract deadlock retries always had, under ONE shared
  budget: deadlocks, serialization failures, lock contention, and eligible connection
  losses all draw from the same configurable allowance
  (`DB_RETRY_MAX_ATTEMPTS`/`DB_RETRY_BACKOFF_MS`, default 3 total attempts with
  500 ms linear backoff; distinct from the pool's `DB_POOL_RETRY_*` acquisition
  settings). New `Connection::idempotentRead(callable)` re-runs a caller-declared
  idempotent read after reconnecting, and `Connection::reconnect()` re-establishes a
  connection outside transactions. A connection loss detected while COMMIT is in
  flight is NEVER replayed — the server may have committed before the acknowledgement
  was lost — and surfaces as the new non-retryable `CommitOutcomeUnknownException`
  with the connection invalidated for lazy reconnection.

### Changed
- **`TransactionManager` retry mechanics**: retries are driven by an injectable
  `RetryBudget` + `SleeperInterface` (tests no longer really sleep); the historical
  sleep AFTER the final failed attempt is removed (~1.5 s saved at defaults);
  `transaction()` now catches `\Throwable`, so an `Error` thrown by a callback rolls
  the transaction back before propagating; rollback failures during exception handling
  follow explicit precedence (a connection loss during rollback of a retryable failure
  now surfaces the loss instead of retrying on a dead handle; a loss during rollback
  of a non-retryable failure preserves the primary and flags the connection for
  invalidation). `TransactionManagerInterface::transaction()` gained an optional
  trailing `?RetryBudget` parameter — external implementors must add it (BC note);
  `setMaxRetries()` keeps its exact historical semantics including the zero-execution
  edge.

### Upgrade Notes
- Replay is available through `Connection::transaction()` and
  `Connection::idempotentRead()` only. `QueryBuilder::transaction()` delegates to its
  captured manager and cannot reconnect. Replay callbacks must build query chains
  INSIDE the callback from the supplied connection — prebuilt builders retain the
  stale PDO.
- Commit-phase connection loss previously surfaced as `ConnectionLostException`; it
  is now `CommitOutcomeUnknownException` (still a `PDOException` subclass). Code that
  retried on the old type was risking duplicate commits — audit any such handler.
```

- [ ] **Step 2: Roadmap** — item 4 heading → `### 4. Reconnect resilience — DONE (see CHANGELOG [Unreleased])`; replace the body with a shipped-summary paragraph (shared budget, two consumers, commit-ambiguity rule, config keys, spec pointer `docs/superpowers/specs/2026-08-08-reconnect-resilience-design.md`); add a closing line under the roadmap intro: items 1–4 complete, item 5 remains deliberately deferred.
- [ ] **Step 3: QueryBuilder docblock** at `transaction()` (line ~833): note it delegates to the captured manager/PDO and does not reconnect; use `Connection::transaction()` for connection-loss replay.
- [ ] **Step 4: Full CI-parity gates** (all three).
- [ ] **Step 5: Commit checkpoint 3**

```bash
git add CHANGELOG.md docs/DATABASE_NATIVE_ROADMAP.md src/Database/QueryBuilder.php
git commit -m "docs(database): reconnect-resilience bookkeeping — roadmap complete"
```

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

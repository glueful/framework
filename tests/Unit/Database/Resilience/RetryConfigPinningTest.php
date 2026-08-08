<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\Resilience;

use Glueful\Database\Connection;
use Glueful\Database\Exceptions\DeadlockException;
use Glueful\Database\QueryLogger;
use Glueful\Database\Resilience\RetryBudget;
use Glueful\Database\Resilience\UsleepSleeper;
use Glueful\Database\Transaction\Interfaces\SavepointManagerInterface;
use Glueful\Database\Transaction\TransactionManager;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Regression pins for two fixes the surrounding suites do not observe:
 * the env-fallback retry config block, and retry logs deriving their
 * allowance from the governing budget rather than the manager's local
 * setMaxRetries() value.
 */
final class RetryConfigPinningTest extends TestCase
{
    #[Test]
    public function envFallbackConfigCarriesTheRetryBlock(): void
    {
        // buildConfigFromEnv() is the no-ApplicationContext fallback; if it
        // omits the retry block, DB_RETRY_* env vars are silently ignored on
        // exactly the connections operators tune via env.
        $connection = (new \ReflectionClass(Connection::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(Connection::class, 'buildConfigFromEnv');
        /** @var array<string, mixed> $config */
        $config = $method->invoke($connection);

        $this->assertArrayHasKey('retry', $config);
        $this->assertIsArray($config['retry']);
        $this->assertSame(3, $config['retry']['max_attempts']);
        $this->assertSame(500, $config['retry']['backoff_base_ms']);
    }

    #[Test]
    public function retryLogsDeriveTheirAllowanceFromTheGoverningBudget(): void
    {
        $records = [];
        $collector = new class ($records) extends AbstractLogger {
            /** @param list<array{message: string, context: array<string, mixed>}> $records */
            public function __construct(private array &$records)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['message' => (string) $message, 'context' => $context];
            }
        };

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Debug mode ON so the debug-level "Starting transaction" event
        // reaches the collector alongside the warning/error events.
        $queryLogger = (new QueryLogger($collector))->configure(enableDebug: true, enableTiming: false);
        $manager = new TransactionManager(
            $pdo,
            $this->createMock(SavepointManagerInterface::class),
            $queryLogger,
            'sqlite',
            new UsleepSleeper()
        );
        // The manager's local setting is deliberately DIFFERENT from the
        // governing budget: the logs must report the budget's allowance.
        $manager->setMaxRetries(9);
        $budget = RetryBudget::forAttempts(5, 0, new UsleepSleeper());

        $raw = new \PDOException('deadlock detected');
        $raw->errorInfo = ['40P01', 7, 'deadlock detected'];
        $deadlock = DeadlockException::fromPdo($raw, 'pgsql');

        try {
            $manager->transaction(static function () use ($deadlock): never {
                throw $deadlock;
            }, $budget);
            $this->fail('Expected exhaustion');
        } catch (DeadlockException) {
            // expected
        }

        $byMessage = static function (string $message) use (&$records): array {
            return array_values(array_filter(
                $records,
                static fn (array $r): bool => $r['message'] === $message
            ));
        };

        $starts = $byMessage('Starting transaction');
        $this->assertCount(1, $starts);
        $this->assertSame(4, $starts[0]['context']['retries_allowed'], 'budget allowance, not setMaxRetries(9)');

        $retries = $byMessage('Transaction deadlock detected, retrying');
        $this->assertCount(4, $retries, 'five executions produce four retry events');
        foreach ($retries as $record) {
            $this->assertSame(4, $record['context']['max_retries']);
        }

        $exhaustion = $byMessage('Transaction failed after maximum retries');
        $this->assertCount(1, $exhaustion);
        $this->assertSame(4, $exhaustion[0]['context']['max_retries']);
    }
}

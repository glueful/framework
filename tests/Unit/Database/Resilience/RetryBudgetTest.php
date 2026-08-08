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

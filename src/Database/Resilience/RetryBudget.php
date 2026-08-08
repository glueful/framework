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

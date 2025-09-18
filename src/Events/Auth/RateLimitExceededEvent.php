<?php

declare(strict_types=1);

namespace Glueful\Events\Auth;

use Glueful\Events\Contracts\BaseEvent;

/**
 * Rate Limit Exceeded Event
 *
 * Dispatched when rate limits are exceeded.
 * Used for security monitoring, adaptive rate limiting, and blocking.
 *
 * @package Glueful\Events\Auth
 */
class RateLimitExceededEvent extends BaseEvent
{
    /**
     * @param string $clientIp Client IP address
     * @param string $rule Rate limit rule that was exceeded
     * @param int $currentCount Current request count
     * @param int $limit Rate limit threshold
     * @param int $windowSeconds Time window in seconds
     * @param array<string, mixed> $metadata Additional metadata
     */
    public function __construct(
        private readonly string $clientIp,
        private readonly string $rule,
        private readonly int $currentCount,
        private readonly int $limit,
        private readonly int $windowSeconds,
        array $metadata = []
    ) {
        parent::__construct();

        foreach ($metadata as $key => $value) {
            $this->setMetadata($key, $value);
        }
    }

    public function getClientIp(): string
    {
        return $this->clientIp;
    }

    public function getRule(): string
    {
        return $this->rule;
    }

    public function getCurrentCount(): int
    {
        return $this->currentCount;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    public function getWindowSeconds(): int
    {
        return $this->windowSeconds;
    }

    public function getExcessCount(): int
    {
        return max(0, $this->currentCount - $this->limit);
    }

    public function getExcessPercentage(): float
    {
        if ($this->limit === 0) {
            return 0.0;
        }
        return ($this->getExcessCount() / $this->limit) * 100;
    }

    public function isSevereViolation(): bool
    {
        return $this->getExcessPercentage() > 200; // More than 200% over limit
    }
}

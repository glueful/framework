<?php

declare(strict_types=1);

namespace Glueful\Events\Cache;

use Glueful\Events\Contracts\BaseEvent;

/**
 * Cache Hit Event
 *
 * Dispatched when a cache key is successfully retrieved.
 * Used for cache analytics and performance monitoring.
 *
 * @package Glueful\Events\Cache
 */
class CacheHitEvent extends BaseEvent
{
    /**
     * @param string $key Cache key
     * @param mixed $value Retrieved value
     * @param array<int, string> $tags Cache tags
     * @param float $retrievalTime Time to retrieve in seconds
     */
    public function __construct(
        private readonly string $key,
        private readonly mixed $value,
        private readonly array $tags = [],
        private readonly float $retrievalTime = 0.0
    ) {
        parent::__construct();
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    /**
     * @return array<int, string>
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    public function getRetrievalTime(): float
    {
        return $this->retrievalTime;
    }

    public function getValueSize(): int
    {
        return strlen(serialize($this->value));
    }

    public function isSlow(float $threshold = 0.1): bool
    {
        return $this->retrievalTime > $threshold;
    }
}

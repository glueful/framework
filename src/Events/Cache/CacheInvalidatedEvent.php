<?php

declare(strict_types=1);

namespace Glueful\Events\Cache;

use Glueful\Events\Contracts\BaseEvent;

/**
 * Cache Invalidated Event
 *
 * Dispatched when cache entries are invalidated.
 * Used for cache coordination and analytics.
 *
 * @package Glueful\Events\Cache
 */
class CacheInvalidatedEvent extends BaseEvent
{
    /**
     * @param array<int, string> $keys Invalidated cache keys
     * @param array<int, string> $tags Invalidated cache tags
     * @param string $reason Invalidation reason
     * @param array<string, mixed> $metadata Additional metadata
     */
    public function __construct(
        private readonly array $keys = [],
        private readonly array $tags = [],
        private readonly string $reason = 'manual',
        array $metadata = []
    ) {
        parent::__construct();

        foreach ($metadata as $key => $value) {
            $this->setMetadata($key, $value);
        }
    }

    /**
     * @return array<int, string>
     */
    public function getKeys(): array
    {
        return $this->keys;
    }

    /**
     * @return array<int, string>
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getTotalCount(): int
    {
        return count($this->keys) + ($this->getMetadata('tag_count') ?? 0);
    }

    public function isAutomatic(): bool
    {
        return in_array($this->reason, ['automatic', 'ttl_expired', 'data_changed'], true);
    }

    public function isDataDriven(): bool
    {
        return $this->reason === 'data_changed';
    }
}

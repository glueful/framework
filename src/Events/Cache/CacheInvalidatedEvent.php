<?php

declare(strict_types=1);

namespace Glueful\Events\Cache;

use Glueful\Events\BaseEvent;

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
     * @param array $keys Invalidated cache keys
     * @param array $tags Invalidated cache tags
     * @param string $reason Invalidation reason
     * @param array $metadata Additional metadata
     */
    public function __construct(
        private readonly array $keys = [],
        private readonly array $tags = [],
        private readonly string $reason = 'manual',
        array $metadata = []
    ) {
        parent::__construct();

        // Set metadata using BaseEvent's setMetadata method
        foreach ($metadata as $key => $value) {
            $this->setMetadata($key, $value);
        }
    }

    /**
     * Get invalidated keys
     *
     * @return array Cache keys
     */
    public function getKeys(): array
    {
        return $this->keys;
    }

    /**
     * Get invalidated tags
     *
     * @return array Cache tags
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    /**
     * Get invalidation reason
     *
     * @return string Reason
     */
    public function getReason(): string
    {
        return $this->reason;
    }


    /**
     * Get total invalidated entries count
     *
     * @return int Total count
     */
    public function getTotalCount(): int
    {
        return count($this->keys) + ($this->getMetadata('tag_count') ?? 0);
    }

    /**
     * Check if invalidation was automatic
     *
     * @return bool True if automatic
     */
    public function isAutomatic(): bool
    {
        return in_array($this->reason, ['automatic', 'ttl_expired', 'data_changed']);
    }

    /**
     * Check if invalidation was due to data changes
     *
     * @return bool True if data-driven
     */
    public function isDataDriven(): bool
    {
        return $this->reason === 'data_changed';
    }
}

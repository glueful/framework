<?php

declare(strict_types=1);

namespace Glueful\Events\Cache;

use Glueful\Events\Contracts\BaseEvent;

/**
 * Cache Miss Event
 *
 * Dispatched when a cache key is not found.
 * Used for cache analytics and warming strategies.
 *
 * @package Glueful\Events\Cache
 */
class CacheMissEvent extends BaseEvent
{
    /**
     * @param string $key Cache key that was missed
     * @param array<int, string> $tags Expected cache tags
     * @param mixed $valueLoader Callback to load the value
     */
    public function __construct(
        private readonly string $key,
        private readonly array $tags = [],
        private readonly mixed $valueLoader = null
    ) {
        parent::__construct();
    }

    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * @return array<int, string>
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    public function getValueLoader(): mixed
    {
        return $this->valueLoader;
    }

    public function hasValueLoader(): bool
    {
        return $this->valueLoader !== null && is_callable($this->valueLoader);
    }

    public function loadValue(): mixed
    {
        if (!$this->hasValueLoader()) {
            throw new \RuntimeException('No value loader available');
        }
        return call_user_func($this->valueLoader);
    }
}

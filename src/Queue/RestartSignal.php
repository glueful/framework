<?php

declare(strict_types=1);

namespace Glueful\Queue;

use Glueful\Cache\CacheStore;

/**
 * A request for every running worker to exit after its current job (queue:restart). A supervisor
 * starts it again on whatever code is installed then. Without a cache store nothing is signalled.
 */
final class RestartSignal
{
    public const KEY = 'queue:restart';

    /** @param CacheStore<mixed>|null $cache */
    public function __construct(private readonly ?CacheStore $cache)
    {
    }

    public function signal(int $at): void
    {
        $this->cache?->set(self::KEY, $at);
    }

    public function requestedAfter(int $startedAt): bool
    {
        $at = $this->cache?->get(self::KEY);
        return is_int($at) && $at >= $startedAt;
    }
}

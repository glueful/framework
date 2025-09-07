<?php

declare(strict_types=1);

namespace Glueful\Lock\Store;

use Symfony\Component\Lock\Store\RedisStore;

class RedisLockStore extends RedisStore
{
    /** @var array{prefix: string, ttl: int} */
    private array $options;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(\Redis|\RedisArray|\RedisCluster $redis, array $options = [])
    {
        $this->options = array_merge([
            'prefix' => 'glueful_lock_',
            'ttl' => 300, // 5 minutes default
        ], $options);

        parent::__construct($redis);
    }

    /**
     * @return array{prefix: string, ttl: int}
     */
    public function getOptions(): array
    {
        return $this->options;
    }
}

<?php

declare(strict_types=1);

namespace Glueful\Auth\TwoFactor;

use Glueful\Cache\CacheStore;

/**
 * Cache-backed single-use enforcement for 2FA challenge-token JTIs.
 *
 * Once a challenge token is verified, its `jti` is consumed for the remainder
 * of the token's lifetime so the same token cannot be replayed.
 */
final class JtiBlocklist
{
    /**
     * @param CacheStore<mixed> $cache
     */
    public function __construct(private CacheStore $cache)
    {
    }

    public function consume(string $jti, int $ttl): void
    {
        $this->cache->set("2fa:consumed_jti:{$jti}", 1, $ttl);
    }

    public function isConsumed(string $jti): bool
    {
        return $this->cache->has("2fa:consumed_jti:{$jti}");
    }
}

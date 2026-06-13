<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Api\RateLimiting;

use Glueful\Api\RateLimiting\Contracts\StorageInterface;
use Glueful\Api\RateLimiting\Contracts\TierResolverInterface;
use Glueful\Api\RateLimiting\RateLimitManager;
use Glueful\Api\RateLimiting\TierManager;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Request;

final class RateLimitManagerKeyTest extends TestCase
{
    public function testEndpointKeyDoesNotExposeRawPathOrIp(): void
    {
        $request = Request::create('/reset/token/secret-token', 'POST', server: ['REMOTE_ADDR' => '203.0.113.10']);

        $key = $this->buildKey($request, ['by' => 'endpoint', 'decaySeconds' => 60], 'anonymous');

        $this->assertStringNotContainsString('/reset/token/secret-token', $key);
        $this->assertStringNotContainsString('203.0.113.10', $key);
    }

    public function testCustomPatternKeyDoesNotExposeRawReplacements(): void
    {
        $request = Request::create('/invite/secret-code', 'GET', server: ['REMOTE_ADDR' => '203.0.113.10']);

        $key = $this->buildKey($request, ['key' => 'custom:{ip}:{path}:{tier}'], 'anonymous');

        $this->assertStringNotContainsString('/invite/secret-code', $key);
        $this->assertStringNotContainsString('203.0.113.10', $key);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function buildKey(Request $request, array $config, string $tier): string
    {
        $manager = new RateLimitManager(
            $this->storage(),
            $this->tierResolver(),
            new TierManager(['tiers' => ['anonymous' => ['requests_per_minute' => 60]]])
        );
        $method = new ReflectionMethod($manager, 'buildKey');
        $method->setAccessible(true);
        $key = $method->invoke($manager, $request, $config, $tier);

        return is_string($key) ? $key : '';
    }

    private function tierResolver(): TierResolverInterface
    {
        return new class implements TierResolverInterface {
            public function resolve(Request $request): string
            {
                return 'anonymous';
            }
        };
    }

    private function storage(): StorageInterface
    {
        return new class implements StorageInterface {
            public function increment(string $key, int $amount = 1): int { return $amount; }
            public function decrement(string $key, int $amount = 1): int { return -$amount; }
            public function get(string $key): ?string { return null; }
            public function set(string $key, mixed $value, int $ttl): bool { return true; }
            public function delete(string $key): bool { return true; }
            public function expire(string $key, int $seconds): bool { return true; }
            public function ttl(string $key): int { return -2; }
            public function zadd(string $key, array $scoreValues): bool { return true; }
            public function zremrangebyscore(string $key, string $min, string $max): int { return 0; }
            public function zcard(string $key): int { return 0; }
            public function zrange(string $key, int $start, int $stop): array { return []; }
            public function exists(string $key): bool { return false; }
        };
    }
}

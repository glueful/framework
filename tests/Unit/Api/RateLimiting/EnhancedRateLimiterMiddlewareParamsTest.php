<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Api\RateLimiting;

use Glueful\Api\RateLimiting\Contracts\StorageInterface;
use Glueful\Api\RateLimiting\Contracts\TierResolverInterface;
use Glueful\Api\RateLimiting\Middleware\EnhancedRateLimiterMiddleware;
use Glueful\Api\RateLimiting\RateLimitHeaders;
use Glueful\Api\RateLimiting\RateLimitManager;
use Glueful\Api\RateLimiting\TierManager;
use Glueful\Routing\Route;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The "rate_limit:attempts,windowSeconds" middleware string form must enforce
 * the given limits when the route carries no rate limit config — previously the
 * params were silently ignored and routes fell back to tier/global defaults.
 */
final class EnhancedRateLimiterMiddlewareParamsTest extends TestCase
{
    public function testStringParamsEnforceLimit(): void
    {
        $middleware = $this->middleware();
        $next = static fn(Request $req): Response => new Response('ok');

        $first = $middleware->handle($this->request(), $next, '2', '60');
        $second = $middleware->handle($this->request(), $next, '2', '60');
        $third = $middleware->handle($this->request(), $next, '2', '60');

        $this->assertInstanceOf(Response::class, $first);
        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame(200, $second->getStatusCode());
        $this->assertSame(429, $third->getStatusCode());
    }

    public function testRouteConfigTakesPrecedenceOverParams(): void
    {
        $middleware = $this->middleware();
        $next = static fn(Request $req): Response => new Response('ok');

        /** @var Route $route */
        $route = (new \ReflectionClass(Route::class))->newInstanceWithoutConstructor();
        $route->setRateLimitConfig([['attempts' => 5, 'decaySeconds' => 60]]);

        // Params say 1/min; route config says 5/min — route config must win.
        $first = $middleware->handle($this->request($route), $next, '1', '60');
        $second = $middleware->handle($this->request($route), $next, '1', '60');

        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame(200, $second->getStatusCode());
    }

    public function testParamsParsing(): void
    {
        $parse = function (array $params): array {
            $method = new ReflectionMethod(EnhancedRateLimiterMiddleware::class, 'getLimitsFromParams');
            $method->setAccessible(true);
            return $method->invoke($this->middleware(), $params);
        };

        $this->assertSame(
            [['attempts' => 5, 'decaySeconds' => 60]],
            $parse(['5', '60'])
        );
        // Window defaults to 60 seconds when omitted
        $this->assertSame(
            [['attempts' => 10, 'decaySeconds' => 60]],
            $parse(['10'])
        );
        // Non-numeric or non-positive params produce no limits (defaults apply)
        $this->assertSame([], $parse([]));
        $this->assertSame([], $parse(['abc']));
        $this->assertSame([], $parse(['0', '60']));
        $this->assertSame([], $parse(['-5', '60']));
    }

    private function middleware(): EnhancedRateLimiterMiddleware
    {
        $manager = new RateLimitManager(
            $this->storage(),
            $this->tierResolver(),
            new TierManager(['tiers' => ['anonymous' => ['requests_per_minute' => 1000]]])
        );

        return new EnhancedRateLimiterMiddleware($manager, new RateLimitHeaders());
    }

    private function request(?Route $route = null): Request
    {
        $request = Request::create('/notiva/devices', 'POST', server: ['REMOTE_ADDR' => '203.0.113.10']);
        if ($route !== null) {
            $request->attributes->set('_route', $route);
        }
        return $request;
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

    /**
     * In-memory sorted-set storage so the sliding-window limiter counts for real.
     */
    private function storage(): StorageInterface
    {
        return new class implements StorageInterface {
            /** @var array<string, array<string, float>> */
            private array $sets = [];

            public function increment(string $key, int $amount = 1): int
            {
                return $amount;
            }
            public function decrement(string $key, int $amount = 1): int
            {
                return -$amount;
            }
            public function get(string $key): ?string
            {
                return null;
            }
            public function set(string $key, mixed $value, int $ttl): bool
            {
                return true;
            }
            public function delete(string $key): bool
            {
                unset($this->sets[$key]);
                return true;
            }
            public function expire(string $key, int $seconds): bool
            {
                return true;
            }
            public function ttl(string $key): int
            {
                return -2;
            }

            public function zadd(string $key, array $scoreValues): bool
            {
                foreach ($scoreValues as $member => $score) {
                    $this->sets[$key][(string) $member] = (float) $score;
                }
                return true;
            }

            public function zremrangebyscore(string $key, string $min, string $max): int
            {
                $removed = 0;
                $maxScore = $max === '+inf' ? PHP_FLOAT_MAX : (float) $max;
                $minScore = $min === '-inf' ? -PHP_FLOAT_MAX : (float) $min;
                foreach ($this->sets[$key] ?? [] as $member => $score) {
                    if ($score >= $minScore && $score <= $maxScore) {
                        unset($this->sets[$key][$member]);
                        $removed++;
                    }
                }
                return $removed;
            }

            public function zcard(string $key): int
            {
                return count($this->sets[$key] ?? []);
            }

            public function zrange(string $key, int $start, int $stop): array
            {
                $scores = array_values($this->sets[$key] ?? []);
                sort($scores);
                $slice = $stop === -1 ? array_slice($scores, $start) : array_slice($scores, $start, $stop - $start + 1);
                return array_map('strval', $slice);
            }

            public function exists(string $key): bool
            {
                return isset($this->sets[$key]);
            }
        };
    }
}

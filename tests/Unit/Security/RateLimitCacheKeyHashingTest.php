<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Security;

use Glueful\Cache\Drivers\ArrayCacheDriver;
use Glueful\Helpers\CacheHelper;
use Glueful\Routing\Middleware\CSRFMiddleware;
use Glueful\Security\SecurityManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class RateLimitCacheKeyHashingTest extends TestCase
{
    public function testCsrfRateLimitKeyHashesClientIp(): void
    {
        $cache = new ArrayCacheDriver();
        $middleware = new CSRFMiddleware(cache: $cache);
        $request = Request::create('/csrf-token', 'GET', server: ['REMOTE_ADDR' => '198.51.100.10']);

        $method = new \ReflectionMethod($middleware, 'checkRateLimit');
        $method->setAccessible(true);
        self::assertTrue($method->invoke($middleware, $request));

        $keys = $this->arrayCacheKeys($cache);

        self::assertCount(1, $keys);
        self::assertStringNotContainsString('198.51.100.10', $keys[0]);
        self::assertStringContainsString(hash('sha256', '198.51.100.10'), $keys[0]);
    }

    public function testSecurityManagerRateLimitKeyHashesClientIp(): void
    {
        $cache = new ArrayCacheDriver();
        $manager = (new \ReflectionClass(SecurityManager::class))->newInstanceWithoutConstructor();
        $this->setPrivateProperty($manager, 'cache', $cache);
        $this->setPrivateProperty($manager, 'events', null);
        $this->setPrivateProperty($manager, 'config', [
            'rate_limit' => [
                'enabled' => true,
                'whitelist_ips' => [],
                'default_limit' => 1000,
                'window_seconds' => 3600,
            ],
        ]);

        $manager->enforceRateLimit('198.51.100.10');

        $keys = $this->arrayCacheKeys($cache);

        self::assertCount(1, $keys);
        self::assertStringNotContainsString('198.51.100.10', $keys[0]);
        self::assertStringContainsString(hash('sha256', '198.51.100.10'), $keys[0]);
    }

    public function testCacheHelperRateLimitKeyHashesIdentifier(): void
    {
        $key = CacheHelper::rateLimitKey('198.51.100.10', 'login');

        self::assertStringNotContainsString('198.51.100.10', $key);
        self::assertStringContainsString(hash('sha256', '198.51.100.10'), $key);
        self::assertStringContainsString('rate_limit:login:', $key);
    }

    /**
     * @return list<string>
     */
    private function arrayCacheKeys(ArrayCacheDriver $cache): array
    {
        $property = new \ReflectionProperty($cache, 'cache');
        $property->setAccessible(true);

        /** @var array<string, mixed> $values */
        $values = $property->getValue($cache);

        return array_keys($values);
    }

    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $ref = new \ReflectionProperty($object, $property);
        $ref->setAccessible(true);
        $ref->setValue($object, $value);
    }
}

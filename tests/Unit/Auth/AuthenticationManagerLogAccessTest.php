<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Auth;

use Glueful\Auth\AuthenticationManager;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AuthenticationManagerLogAccessTest extends TestCase
{
    public function testLogAccessUriSanitizerRedactsSensitiveQueryValues(): void
    {
        $manager = new AuthenticationManager();

        $sanitized = $this->sanitize($manager, '/account?token=secret&code=oauth&next=/admin');

        $this->assertSame('/account?token=%5BREDACTED%5D&code=%5BREDACTED%5D&next=%2Fadmin', $sanitized);
        $this->assertStringNotContainsString('secret', $sanitized);
        $this->assertStringNotContainsString('oauth', $sanitized);
    }

    private function sanitize(AuthenticationManager $manager, string $uri): string
    {
        $method = new ReflectionMethod($manager, 'sanitizeLogUri');
        $method->setAccessible(true);
        $result = $method->invoke($manager, $uri);

        return is_string($result) ? $result : '';
    }
}

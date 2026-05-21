<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Auth\ApiKey;

use Glueful\Auth\ApiKey\Support\CidrMatcher;
use PHPUnit\Framework\TestCase;

class CidrMatcherTest extends TestCase
{
    public function testMatchesExactSingleIp(): void
    {
        $this->assertTrue(CidrMatcher::matches('203.0.113.42', '203.0.113.42'));
        $this->assertFalse(CidrMatcher::matches('203.0.113.43', '203.0.113.42'));
    }

    public function testMatchesCidrRange(): void
    {
        $this->assertTrue(CidrMatcher::matches('192.168.1.50', '192.168.1.0/24'));
        $this->assertTrue(CidrMatcher::matches('192.168.1.0', '192.168.1.0/24'));
        $this->assertTrue(CidrMatcher::matches('192.168.1.255', '192.168.1.0/24'));
        $this->assertFalse(CidrMatcher::matches('192.168.2.1', '192.168.1.0/24'));
    }

    public function testMatchesSlash32AsExactIp(): void
    {
        $this->assertTrue(CidrMatcher::matches('10.0.0.5', '10.0.0.5/32'));
        $this->assertFalse(CidrMatcher::matches('10.0.0.6', '10.0.0.5/32'));
    }

    public function testMatchesAny(): void
    {
        $allowed = ['192.168.1.0/24', '203.0.113.42'];
        $this->assertTrue(CidrMatcher::matchesAny('192.168.1.5', $allowed));
        $this->assertTrue(CidrMatcher::matchesAny('203.0.113.42', $allowed));
        $this->assertFalse(CidrMatcher::matchesAny('10.0.0.1', $allowed));
    }

    public function testEmptyAllowlistMatchesEverything(): void
    {
        // Empty allowlist is treated as "no restriction"
        $this->assertTrue(CidrMatcher::matchesAny('1.2.3.4', []));
    }

    public function testMalformedInputReturnsFalse(): void
    {
        $this->assertFalse(CidrMatcher::matches('1.2.3.4', 'not-a-cidr'));
        $this->assertFalse(CidrMatcher::matches('1.2.3.4', '999.999.999.999/24'));
        $this->assertFalse(CidrMatcher::matches('not-an-ip', '192.168.1.0/24'));
    }
}

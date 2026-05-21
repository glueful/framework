<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Auth\ApiKey;

use Glueful\Auth\ApiKey\ApiKeyService;
use PHPUnit\Framework\TestCase;

class ApiKeyServiceTest extends TestCase
{
    public function testGeneratedKeyHasEnvironmentPrefix(): void
    {
        $this->assertStringStartsWith('gf_live_', ApiKeyService::generatePlainKey('production'));
        $this->assertStringStartsWith('gf_test_', ApiKeyService::generatePlainKey('testing'));
        $this->assertStringStartsWith('gf_test_', ApiKeyService::generatePlainKey('development'));
    }

    public function testGeneratedKeyHasEnoughEntropy(): void
    {
        // 'gf_live_' is 8 chars; the random part must be at least 32 chars
        $this->assertGreaterThanOrEqual(40, strlen(ApiKeyService::generatePlainKey('production')));
    }

    public function testGeneratedKeysAreUnique(): void
    {
        $keys = [];
        for ($i = 0; $i < 50; $i++) {
            $keys[] = ApiKeyService::generatePlainKey('production');
        }
        $this->assertCount(50, array_unique($keys));
    }

    public function testPrefixExtractionTakesFirst16Chars(): void
    {
        $this->assertSame(
            'gf_live_abcdef01',
            ApiKeyService::extractPrefix('gf_live_abcdef0123456789moretext')
        );
    }

    public function testHashIsSha256Hex(): void
    {
        $key = 'gf_live_known_key_for_test';
        $hash = ApiKeyService::hashKey($key);
        $this->assertSame(64, strlen($hash));
        $this->assertSame(hash('sha256', $key), $hash);
    }

    public function testScopeMatchExact(): void
    {
        $this->assertTrue(ApiKeyService::scopeSatisfies(['read:posts'], 'read:posts'));
        $this->assertFalse(ApiKeyService::scopeSatisfies(['read:posts'], 'write:posts'));
    }

    public function testScopeMatchWildcard(): void
    {
        $this->assertTrue(ApiKeyService::scopeSatisfies(['read:*'], 'read:posts'));
        $this->assertTrue(ApiKeyService::scopeSatisfies(['*'], 'anything:at:all'));
        $this->assertFalse(ApiKeyService::scopeSatisfies(['read:*'], 'write:posts'));
    }

    public function testEmptyScopeListGrantsFullAccess(): void
    {
        $this->assertTrue(ApiKeyService::scopeSatisfies([], 'anything'));
    }
}

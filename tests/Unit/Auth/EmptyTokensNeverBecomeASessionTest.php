<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Auth;

use Glueful\Auth\RefreshTokenStore;
use Glueful\Auth\TokenManager;
use PHPUnit\Framework\TestCase;

/**
 * The JWT provider swallows a token-generation failure and answers empty strings for both
 * tokens; createUserSession() accepted them (an empty string is a string), stored a session and
 * issued a refresh token of "" — whose hash is a constant, so the first failed login poisoned
 * auth_refresh_tokens and every later login answered 409 (unique token_hash). Empty tokens are
 * a failed login, and the refresh-token store refuses an empty token outright.
 */
final class EmptyTokensNeverBecomeASessionTest extends TestCase
{
    public function testTokensAreUsableOnlyWhenBothAreNonEmptyStrings(): void
    {
        self::assertTrue(TokenManager::tokensAreUsable(['access_token' => 'a.b.c', 'refresh_token' => 'r']));
        self::assertFalse(TokenManager::tokensAreUsable(['access_token' => '', 'refresh_token' => 'r']));
        self::assertFalse(TokenManager::tokensAreUsable(['access_token' => 'a.b.c', 'refresh_token' => '']));
        self::assertFalse(TokenManager::tokensAreUsable(['access_token' => '', 'refresh_token' => '']));
        self::assertFalse(TokenManager::tokensAreUsable(['access_token' => null, 'refresh_token' => 'r']));
        self::assertFalse(TokenManager::tokensAreUsable([]));
    }

    public function testTheRefreshTokenStoreRefusesAnEmptyToken(): void
    {
        $store = (new \ReflectionClass(RefreshTokenStore::class))->newInstanceWithoutConstructor();

        $this->expectException(\InvalidArgumentException::class);
        $store->issue('session', 'user', '', 3600);
    }
}

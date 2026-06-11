<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Permissions\Middleware;

use Glueful\Permissions\Middleware\AuthToRequestAttributesMiddleware;
use PHPUnit\Framework\TestCase;

/**
 * Lock: claims are only ever derived from a verified token. A structurally-valid JWT with
 * an unverifiable (forged) signature must yield NO claims -- there must be no unverified
 * base64-decode fallback that would let a forged `scope`/`role` claim reach the gate.
 */
final class AuthJwtClaimsVerificationTest extends TestCase
{
    public function test_forged_unsigned_token_yields_no_claims(): void
    {
        $b64 = static fn(array $data): string =>
            rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=');

        $forged = $b64(['alg' => 'HS256', 'typ' => 'JWT'])
            . '.' . $b64(['scope' => 'admin:*', 'sub' => 'attacker', 'roles' => ['admin']])
            . '.forged-signature-not-verifiable';

        $middleware = (new \ReflectionClass(AuthToRequestAttributesMiddleware::class))
            ->newInstanceWithoutConstructor();
        $extract = new \ReflectionMethod($middleware, 'extractJwtClaims');
        $extract->setAccessible(true);

        $claims = $extract->invoke($middleware, $forged);

        self::assertNull($claims, 'a forged/unverified token must not produce claims');
    }
}

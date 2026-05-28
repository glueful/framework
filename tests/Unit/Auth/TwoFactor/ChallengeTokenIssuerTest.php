<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Auth\TwoFactor;

use Glueful\Auth\JWTService;
use Glueful\Auth\TwoFactor\ChallengeTokenIssuer;
use Glueful\Auth\TwoFactor\Exceptions\InvalidChallengeTokenException;
use Glueful\Auth\TwoFactor\JtiBlocklist;
use Glueful\Cache\Drivers\ArrayCacheDriver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(ChallengeTokenIssuer::class)]
final class ChallengeTokenIssuerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // JWTService::generate/decode are static and read a configured key.
        // Set it directly (same approach as TokenManagerSessionVersionTest).
        $ref = new ReflectionClass(JWTService::class);
        $ref->getProperty('key')->setValue(null, 'test-2fa-challenge-key');
    }

    private function newIssuer(int $ttl = 300): ChallengeTokenIssuer
    {
        return new ChallengeTokenIssuer(new JtiBlocklist(new ArrayCacheDriver()), $ttl);
    }

    public function testIssueVerifyRoundTripForBothPurposes(): void
    {
        foreach ([ChallengeTokenIssuer::PURPOSE_ENABLE, ChallengeTokenIssuer::PURPOSE_LOGIN] as $purpose) {
            $issuer = $this->newIssuer();
            $issued = $issuer->issue('user-123', $purpose);

            $claims = $issuer->verify($issued['token']);

            $this->assertSame($issued['jti'], $claims['jti']);
            $this->assertSame($purpose, $claims['purpose']);
            $this->assertSame('user-123', $claims['user_uuid']);
        }
    }

    public function testUnknownPurposeThrowsInvalidArgument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->newIssuer()->issue('user-123', 'totp');
    }

    public function testTamperedSignatureFailsVerification(): void
    {
        $issuer = $this->newIssuer();
        $issued = $issuer->issue('user-123', ChallengeTokenIssuer::PURPOSE_LOGIN);

        // Flip a character in the signature segment.
        $tampered = $issued['token'] . 'x';

        $this->expectException(InvalidChallengeTokenException::class);
        $issuer->verify($tampered);
    }

    public function testExpiredTokenFailsVerification(): void
    {
        // Build an already-expired challenge token directly (negative expiration),
        // bypassing issue()'s own generate→decode round-trip.
        $expiredToken = JWTService::generate(
            ['purpose' => ChallengeTokenIssuer::PURPOSE_LOGIN, 'user_uuid' => 'user-123'],
            -10
        );

        $this->expectException(InvalidChallengeTokenException::class);
        $this->newIssuer()->verify($expiredToken);
    }

    public function testWrongPurposeTokenFailsVerification(): void
    {
        // A valid JWT that is not a 2FA challenge (no/!valid purpose claim).
        $foreignToken = JWTService::generate(['sub' => 'user-123', 'purpose' => 'password_reset'], 300);

        $this->expectException(InvalidChallengeTokenException::class);
        $this->newIssuer()->verify($foreignToken);
    }

    public function testConsumedJtiFailsVerification(): void
    {
        $blocklist = new JtiBlocklist(new ArrayCacheDriver());
        $issuer = new ChallengeTokenIssuer($blocklist, 300);
        $issued = $issuer->issue('user-123', ChallengeTokenIssuer::PURPOSE_LOGIN);

        // Simulate a prior successful verify having consumed the jti.
        $blocklist->consume($issued['jti'], 300);

        $this->expectException(InvalidChallengeTokenException::class);
        $issuer->verify($issued['token']);
    }
}

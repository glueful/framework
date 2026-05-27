<?php

declare(strict_types=1);

namespace Glueful\Auth\TwoFactor;

use Glueful\Auth\JWTService;
use Glueful\Auth\TwoFactor\Exceptions\InvalidChallengeTokenException;

/**
 * Issues and verifies short-lived 2FA challenge JWTs via the framework's JWTService.
 *
 * Tokens carry a `purpose` claim (`2fa_enable` | `2fa_login`) and the target
 * `user_uuid`. Single-use is enforced via {@see JtiBlocklist}: once verified, the
 * token's framework-assigned `jti` is consumed for the remainder of its lifetime.
 */
final class ChallengeTokenIssuer
{
    public const PURPOSE_ENABLE = '2fa_enable';
    public const PURPOSE_LOGIN = '2fa_login';

    public function __construct(
        private JtiBlocklist $blocklist,
        private int $ttl = 300,
    ) {
    }

    /**
     * @return array{token: string, jti: string, exp: int}
     */
    public function issue(string $userUuid, string $purpose): array
    {
        if (!in_array($purpose, [self::PURPOSE_ENABLE, self::PURPOSE_LOGIN], true)) {
            throw new \InvalidArgumentException("Unknown 2FA purpose: {$purpose}");
        }

        // JWTService::generate() is static and auto-assigns iat/exp/jti
        // (src/Auth/JWTService.php:79-81), overwriting any values we provide.
        // We supply only the domain claims and read back the framework-chosen jti.
        $token = JWTService::generate([
            'purpose' => $purpose,
            'user_uuid' => $userUuid,
        ], $this->ttl);

        $payload = JWTService::decode($token);
        if (!is_array($payload) || !isset($payload['jti'], $payload['exp'])) {
            // Generate-then-decode round-trip failed — only happens if key/alg state is broken.
            throw new \RuntimeException('Failed to read freshly-issued challenge token');
        }

        return [
            'token' => $token,
            'jti' => (string) $payload['jti'],
            'exp' => (int) $payload['exp'],
        ];
    }

    /**
     * @return array{jti: string, exp: int, purpose: string, user_uuid: string}
     * @throws InvalidChallengeTokenException
     */
    public function verify(string $token): array
    {
        // decode() returns null on signature/format/exp failure — no exceptions thrown.
        $claims = JWTService::decode($token);
        if ($claims === null) {
            throw new InvalidChallengeTokenException('Invalid or expired challenge token');
        }

        $purpose = $claims['purpose'] ?? null;
        if ($purpose !== self::PURPOSE_ENABLE && $purpose !== self::PURPOSE_LOGIN) {
            throw new InvalidChallengeTokenException('Wrong token purpose');
        }

        $jti = (string) ($claims['jti'] ?? '');
        if ($jti === '' || $this->blocklist->isConsumed($jti)) {
            throw new InvalidChallengeTokenException('Token already consumed');
        }

        return [
            'jti' => $jti,
            'exp' => (int) $claims['exp'],
            'purpose' => $purpose,
            'user_uuid' => (string) ($claims['user_uuid'] ?? ''),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Glueful\Auth\TwoFactor;

use Glueful\Auth\JWTService;
use Glueful\Auth\TokenManager;
use Glueful\Auth\TwoFactor\Exceptions\InvalidTwoFactorCodeException;
use Glueful\Auth\TwoFactor\Exceptions\TwoFactorNotEnabledException;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\CacheStore;
use Glueful\Database\Connection;
use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Notifications\Services\NotificationService;
use Glueful\Security\OTP;

/**
 * Front door for the core email-PIN 2FA feature.
 *
 * Coordinates the challenge-token issuer, the single-use jti blocklist, the PIN
 * cache, email dispatch via NotificationService, the users table, and (on a
 * successful login-purpose verify) TokenManager::createUserSession for real
 * session issuance plus a session-scoped freshness marker for /2fa/disable.
 */
final class TwoFactorService
{
    /**
     * Fields cached in 2fa:pin:{jti}.user. Anything not on this list is dropped
     * before the cache write, so raw repository rows (including password hashes)
     * can be passed by the caller without leaking through the cache layer.
     */
    private const ALLOWED_USER_FIELDS = [
        'uuid',
        'email',
        'email_verified_at',
        'username',
        'profile',
        'remember_me',
        'status',
    ];

    /**
     * @param CacheStore<mixed> $cache
     */
    public function __construct(
        private ApplicationContext $context,
        private Connection $db,
        private CacheStore $cache,
        private NotificationService $notifications,
        private ChallengeTokenIssuer $issuer,
        private JtiBlocklist $blocklist,
        private TokenManager $tokenManager,
        private int $pinLength = 6,
        private int $pinTtl = 300,
        private int $disableFreshness = 300,
        private string $templateName = 'two-factor-pin',
        private bool $masterEnabled = true,
    ) {
    }

    /**
     * Strip the incoming user array to the allowlist. Drops password hashes,
     * audit timestamps, soft-delete flags, etc.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function projectUser(array $user): array
    {
        $projected = [];
        foreach (self::ALLOWED_USER_FIELDS as $key) {
            if (array_key_exists($key, $user)) {
                $projected[$key] = $user[$key];
            }
        }
        return $projected;
    }

    public function isEnabled(string $userUuid): bool
    {
        // Master kill-switch short-circuits BEFORE touching the DB, so fresh
        // installs without the migration never read the (possibly missing) column.
        if (!$this->masterEnabled) {
            return false;
        }
        try {
            $row = $this->db->table('users')
                ->select(['two_factor_enabled'])
                ->where('uuid', $userUuid)
                ->first();
        } catch (\Throwable $e) {
            // If an operator flips the master switch on before running the migration,
            // the column is missing. Log and fail closed (no 2FA) rather than 500.
            error_log('TwoFactorService::isEnabled query failed — column missing? ' . $e->getMessage());
            return false;
        }
        $value = $row['two_factor_enabled'] ?? false;
        return $value === true || $value === 1 || $value === '1';
    }

    /**
     * Begin a 2FA-enrollment challenge. Only uuid + email are needed because the
     * enable-purpose verify path does not issue tokens.
     *
     * @return array{token: string, expires_in: int, delivered_to: string}
     */
    public function beginEnable(string $userUuid, string $email): array
    {
        return $this->dispatchChallenge(
            ['uuid' => $userUuid, 'email' => $email],
            $email,
            ChallengeTokenIssuer::PURPOSE_ENABLE
        );
    }

    /**
     * Begin a 2FA-login challenge. Projects the user to ALLOWED_USER_FIELDS before
     * caching and stashes the preferred token provider so /2fa/verify can issue the
     * final session via the same provider the original /auth/login requested.
     *
     * @param array<string, mixed> $user Must include uuid + email; fields outside
     *                                    ALLOWED_USER_FIELDS are silently dropped.
     * @param string|null $preferredProvider Provider name from /auth/login (jwt, ldap, saml, ...).
     * @return array{token: string, expires_in: int, delivered_to: string}
     */
    public function beginLogin(array $user, ?string $preferredProvider = null): array
    {
        if (!isset($user['uuid'], $user['email'])) {
            throw new \InvalidArgumentException('beginLogin requires user[uuid] and user[email]');
        }
        $projected = $this->projectUser($user);
        return $this->dispatchChallenge(
            $projected,
            (string) $projected['email'],
            ChallengeTokenIssuer::PURPOSE_LOGIN,
            $preferredProvider
        );
    }

    /**
     * @return array{purpose: string, user_uuid: string, sid?: string, session?: array<string, mixed>}
     * @throws \Glueful\Auth\TwoFactor\Exceptions\InvalidChallengeTokenException
     * @throws InvalidTwoFactorCodeException
     * @throws TwoFactorNotEnabledException
     */
    public function verify(string $challengeToken, string $code): array
    {
        $claims = $this->issuer->verify($challengeToken);

        $pinEntry = $this->cache->get("2fa:pin:{$claims['jti']}");
        if (
            !is_array($pinEntry)
            || !is_array($pinEntry['user'] ?? null)
            || ($pinEntry['user']['uuid'] ?? null) !== $claims['user_uuid']
        ) {
            throw new InvalidTwoFactorCodeException('No active PIN for this challenge');
        }

        // bcrypt verify is constant-time — no separate hash_equals needed.
        if (!OTP::verifyHashedOTP($code, (string) $pinEntry['code_hash'])) {
            throw new InvalidTwoFactorCodeException('Wrong code');
        }

        // Consume: delete the PIN, blocklist the jti for the rest of its lifetime.
        $this->cache->delete("2fa:pin:{$claims['jti']}");
        $this->blocklist->consume($claims['jti'], max(1, $claims['exp'] - time()));

        if ($claims['purpose'] === ChallengeTokenIssuer::PURPOSE_ENABLE) {
            $this->db->table('users')
                ->where('uuid', $claims['user_uuid'])
                ->update(['two_factor_enabled' => true]);

            return ['purpose' => 'enabled', 'user_uuid' => $claims['user_uuid']];
        }

        // PURPOSE_LOGIN. Re-validate current DB state before minting a session —
        // the cached snapshot could be up to pinTtl seconds stale.
        $current = $this->db->table('users')
            ->select(['uuid', 'status', 'two_factor_enabled'])
            ->where('uuid', $claims['user_uuid'])
            ->first();

        if ($current === null) {
            throw new InvalidTwoFactorCodeException('Account no longer exists');
        }

        /** @var array<int, string> $allowedStatuses */
        $allowedStatuses = (array) config(
            $this->context,
            'security.auth.allowed_login_statuses',
            ['active']
        );
        $userStatus = (string) ($current['status'] ?? '');
        if ($allowedStatuses !== [] && !in_array($userStatus, $allowedStatuses, true)) {
            throw new InvalidTwoFactorCodeException('Account is not eligible to log in');
        }

        $twoFactorEnabled = $current['two_factor_enabled'] ?? false;
        if ($twoFactorEnabled !== true && $twoFactorEnabled !== 1 && $twoFactorEnabled !== '1') {
            throw new TwoFactorNotEnabledException('Two-factor authentication is no longer enabled');
        }

        // State checks pass — create a real session via TokenManager::createUserSession.
        /** @var array<string, mixed> $user */
        $user = $pinEntry['user'];
        $user['status'] = $userStatus;
        $preferredProvider = (string) ($pinEntry['preferred_provider'] ?? 'jwt');
        $session = $this->tokenManager->createUserSession($user, $preferredProvider);
        if ($session === []) {
            throw new \RuntimeException('Failed to create session after successful 2FA verify');
        }

        // Scope the freshness marker to *this* session via the issued token's sid.
        $accessClaims = JWTService::decode((string) ($session['access_token'] ?? ''));
        $sid = is_array($accessClaims) ? (string) ($accessClaims['sid'] ?? '') : '';
        if ($sid === '') {
            throw new \RuntimeException('Issued access token has no sid claim');
        }
        $this->cache->set("2fa:fresh:{$sid}", time(), $this->disableFreshness);

        return [
            'purpose' => 'login',
            'user_uuid' => $claims['user_uuid'],
            'sid' => $sid,
            'session' => $session,
        ];
    }

    /**
     * True if the session identified by $sid completed a 2FA verification within
     * the last disableFreshness seconds. Session-scoped (not user-scoped).
     */
    public function hasFreshVerification(string $sid): bool
    {
        if ($sid === '') {
            return false;
        }
        return $this->cache->has("2fa:fresh:{$sid}");
    }

    public function disable(string $userUuid, ?string $sid = null): void
    {
        $this->db->table('users')
            ->where('uuid', $userUuid)
            ->update(['two_factor_enabled' => false]);

        // Clear the freshness marker for the calling session only.
        if ($sid !== null && $sid !== '') {
            $this->cache->delete("2fa:fresh:{$sid}");
        }
    }

    /**
     * @param array<string, mixed> $user
     * @return array{token: string, expires_in: int, delivered_to: string}
     */
    private function dispatchChallenge(
        array $user,
        string $email,
        string $purpose,
        ?string $preferredProvider = null
    ): array {
        $challenge = $this->issuer->issue((string) $user['uuid'], $purpose);

        // Generate + bcrypt-hash via the framework's OTP primitives. Cache the
        // projected user array + requested provider — verify() reads both back.
        $pin = OTP::generateNumeric($this->pinLength);
        $this->cache->set(
            "2fa:pin:{$challenge['jti']}",
            [
                'user' => $user,
                'code_hash' => OTP::hashOTP($pin),
                'preferred_provider' => $preferredProvider,
            ],
            $this->pinTtl
        );

        $this->sendPin($email, $pin);

        return [
            'token' => $challenge['token'],
            'expires_in' => $challenge['exp'] - time(),
            'delivered_to' => $this->maskEmail($email),
        ];
    }

    private function sendPin(string $email, string $pin): void
    {
        $notifiable = new class ($email) implements Notifiable {
            public function __construct(private string $email)
            {
            }
            public function routeNotificationFor(string $channel): ?string
            {
                return $channel === 'email' ? $this->email : null;
            }
            public function getNotifiableId(): string
            {
                return md5($this->email);
            }
            public function getNotifiableType(): string
            {
                return 'two_factor_recipient';
            }
            public function shouldReceiveNotification(string $type, string $channel): bool
            {
                return $channel === 'email';
            }
            /** @return array<string, mixed> */
            public function getNotificationPreferences(): array
            {
                return ['email' => true];
            }
        };

        $this->notifications->send(
            'two_factor_pin',
            $notifiable,
            'Your two-factor verification code',
            [
                'pin' => $pin,
                'ttl_minutes' => (int) ceil($this->pinTtl / 60),
                'subject' => 'Your two-factor verification code',
                'template_name' => $this->templateName,
            ],
            ['channels' => ['email']]
        );
    }

    private function maskEmail(string $email): string
    {
        $at = strpos($email, '@');
        if ($at === false || $at <= 1) {
            return '***';
        }
        return substr($email, 0, 1) . str_repeat('*', $at - 1) . substr($email, $at);
    }
}

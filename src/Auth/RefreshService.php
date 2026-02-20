<?php

declare(strict_types=1);

namespace Glueful\Auth;

use Glueful\Auth\Interfaces\SessionStoreInterface;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Helpers\CacheHelper;
use Glueful\Repository\UserRepository;

class RefreshService
{
    public function __construct(
        private readonly RefreshTokenRepository $refreshTokenRepository,
        private readonly SessionRepository $sessionRepository,
        private readonly AccessTokenIssuer $accessTokenIssuer,
        private readonly ProviderTokenIssuer $providerTokenIssuer,
        private readonly SessionStateCache $sessionStateCache,
        private readonly SessionStoreInterface $sessionStore,
        private readonly UserRepository $userRepository,
        private readonly ?ApplicationContext $context = null
    ) {
    }

    /** @return array<string, mixed>|null */
    public function refresh(string $refreshToken): ?array
    {
        $startedAt = microtime(true);
        $this->incrementMetric('auth_refresh_requests_total');

        $session = $this->refreshTokenRepository->findActiveSessionByToken($refreshToken);
        if ($session === null) {
            return $this->fail('invalid_refresh_token', $startedAt);
        }

        $provider = (string) ($session['provider'] ?? 'jwt');
        $rememberMe = (bool) ($session['remember_me'] ?? false);
        $refreshTtl = $this->sessionStore->getRefreshTtl($provider, $rememberMe);
        $accessTtl = $this->sessionStore->getAccessTtl($provider, $rememberMe);

        $sessionUuid = (string) ($session['session_uuid'] ?? '');
        $userUuid = (string) ($session['user_uuid'] ?? '');
        if ($sessionUuid === '' || $userUuid === '') {
            return $this->fail('invalid_session_context', $startedAt, $sessionUuid, $userUuid);
        }

        $rotated = null;
        if ($provider === 'jwt') {
            $nextRefreshToken = bin2hex(random_bytes(32));
            $rotated = $this->refreshTokenRepository->rotateOneTimeToken($refreshToken, $nextRefreshToken, $refreshTtl);
            if ($rotated === null) {
                return $this->fail('rotate_failed', $startedAt, $sessionUuid, $userUuid);
            }

            $sessionData = [
                'uuid' => $userUuid,
                'created_at' => (string) ($rotated['session_created_at'] ?? date('Y-m-d H:i:s')),
                'provider' => $provider,
                'remember_me' => $rememberMe,
                'refresh_token' => $refreshToken,
                'sid' => $sessionUuid,
                'ver' => (int) ($rotated['session_version'] ?? 1),
            ];
            $tokens = $this->accessTokenIssuer->issuePair($sessionData, $accessTtl, $refreshTtl, $nextRefreshToken);
        } else {
            $sessionData = [
                'uuid' => $userUuid,
                'created_at' => (string) ($session['session_created_at'] ?? date('Y-m-d H:i:s')),
                'provider' => $provider,
                'remember_me' => $rememberMe,
                'refresh_token' => $refreshToken,
                'sid' => $sessionUuid,
                'ver' => (int) ($session['session_version'] ?? 1),
            ];
            $tokens = $this->providerTokenIssuer->refresh($refreshToken, $provider, $sessionData);
            if ($tokens === null || $tokens === []) {
                $this->refreshTokenRepository->revokeSessionFamily($sessionUuid);
                return $this->fail('provider_refresh_failed', $startedAt, $sessionUuid, $userUuid);
            }

            $newRefresh = (string) ($tokens['refresh_token'] ?? '');
            $rotated = $this->refreshTokenRepository->rotateOneTimeToken(
                $refreshToken,
                $newRefresh,
                $refreshTtl
            );
            if ($rotated === null) {
                return $this->fail('rotate_failed', $startedAt, $sessionUuid, $userUuid);
            }
        }

        $lockWaitMs = (int) ($rotated['lock_wait_ms'] ?? 0);
        if ($lockWaitMs > 0) {
            $this->incrementMetric('auth_refresh_lock_wait_ms_total', $lockWaitMs);
            $this->incrementMetric('auth_refresh_lock_wait_samples_total');
        }

        $persisted = $this->sessionStateCache->persistRotatedSession($sessionUuid, $tokens);
        if ($persisted === false) {
            $this->refreshTokenRepository->revokeSessionFamily($sessionUuid);
            $this->sessionStateCache->invalidateSession($sessionUuid);
            return $this->fail('session_persist_failed', $startedAt, $sessionUuid, $userUuid);
        }

        $user = $this->loadUser($userUuid);
        if ($user === null) {
            return $this->fail('user_not_found', $startedAt, $sessionUuid, $userUuid);
        }

        $this->incrementMetric('auth_refresh_success_total');
        $this->recordLatency($startedAt);
        $this->logEvent('refresh_success', [
            'session_uuid' => $sessionUuid,
            'user_uuid' => $userUuid,
            'provider' => $provider,
            'lock_wait_ms' => $lockWaitMs,
            'reason_code' => 'success',
        ]);

        return [
            'access_token' => (string) $tokens['access_token'],
            'refresh_token' => (string) $tokens['refresh_token'],
            'expires_in' => (int) ($tokens['expires_in'] ?? $accessTtl),
            'token_type' => 'Bearer',
            'user' => $user,
        ];
    }

    private function fail(string $reason, float $startedAt, string $sessionUuid = '', string $userUuid = ''): null
    {
        $this->incrementMetric('auth_refresh_fail_total');
        $this->incrementMetric('auth_refresh_fail_reason_' . $reason . '_total');
        $this->recordLatency($startedAt);
        $this->logEvent('refresh_failed', [
            'session_uuid' => $sessionUuid,
            'user_uuid' => $userUuid,
            'reason_code' => $reason,
        ]);

        return null;
    }

    /** @return array<string, mixed>|null */
    private function loadUser(string $userUuid): ?array
    {
        $user = $this->userRepository->findByUuid($userUuid);
        if ($user === null || $user === []) {
            return null;
        }

        $profile = $this->userRepository->getProfile($userUuid);
        $firstName = (string) ($profile['first_name'] ?? '');
        $lastName = (string) ($profile['last_name'] ?? '');

        $payload = [
            'id' => $user['uuid'],
            'email' => $user['email'] ?? null,
            'email_verified' => (bool) ($user['email_verified_at'] ?? false),
            'username' => $user['username'] ?? null,
            'locale' => $user['locale'] ?? 'en-US',
            'updated_at' => isset($user['updated_at']) ? strtotime((string) $user['updated_at']) : time(),
        ];

        if ($firstName !== '' || $lastName !== '') {
            $payload['name'] = trim($firstName . ' ' . $lastName);
            $payload['given_name'] = $firstName !== '' ? $firstName : null;
            $payload['family_name'] = $lastName !== '' ? $lastName : null;
        }
        if (($profile['photo_url'] ?? '') !== '') {
            $payload['picture'] = $profile['photo_url'];
        }

        return $payload;
    }

    private function incrementMetric(string $name, int $value = 1): void
    {
        $cache = CacheHelper::createCacheInstance($this->context);
        if ($cache === null) {
            return;
        }

        try {
            $cache->increment('metrics:auth:' . $name, $value);
        } catch (\Throwable) {
            // best effort
        }
    }

    private function recordLatency(float $startedAt): void
    {
        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
        $this->incrementMetric('auth_refresh_latency_ms_total', $latencyMs);
        $this->incrementMetric('auth_refresh_latency_samples_total');
    }

    /** @param array<string, mixed> $context */
    private function logEvent(string $event, array $context): void
    {
        $context['event'] = $event;
        $context['component'] = 'auth.refresh';
        error_log('[AuthRefresh] ' . json_encode($context, JSON_UNESCAPED_SLASHES));
    }
}

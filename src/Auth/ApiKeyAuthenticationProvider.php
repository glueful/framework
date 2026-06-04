<?php

declare(strict_types=1);

namespace Glueful\Auth;

use Symfony\Component\HttpFoundation\Request;
use Glueful\Auth\ApiKey\ApiKeyService;
use Glueful\Auth\ApiKey\Exceptions\ApiKeyExpiredException;
use Glueful\Auth\ApiKey\Exceptions\InvalidApiKeyException;
use Glueful\Auth\Contracts\UserProviderInterface;
use Glueful\Auth\Interfaces\AuthenticationProviderInterface;
use Glueful\Bootstrap\ApplicationContext;

/**
 * API Key Authentication Provider
 *
 * Implements authentication using API keys stored in the dedicated
 * `api_keys` table. Verification, scope extraction, IP allowlisting,
 * expiration, and revocation all flow through ApiKeyService.
 *
 * Single-track: no legacy `users.api_key` fallback. The previous dual-
 * track approach was both dead code (canonical schema has no api_key
 * column) and a security hole (a revoked key whose plaintext still
 * existed in a custom column could re-authenticate).
 */
class ApiKeyAuthenticationProvider implements AuthenticationProviderInterface
{
    /** @var string|null Last authentication error message */
    private ?string $lastError = null;

    /** @var UserProviderInterface|null Provider for looking up the key's user by uuid */
    private ?UserProviderInterface $userProvider = null;
    private ?ApplicationContext $context = null;
    private ?AuthenticationManager $authManager = null;

    public function __construct(?ApplicationContext $context = null)
    {
        $this->context = $context;
    }

    public function setAuthManager(AuthenticationManager $authManager): void
    {
        $this->authManager = $authManager;
    }

    /** Override the identity provider (defaults to the container's UserProviderInterface). */
    public function setUserProvider(UserProviderInterface $userProvider): void
    {
        $this->userProvider = $userProvider;
    }

    private function getUserProvider(): UserProviderInterface
    {
        if ($this->userProvider === null) {
            $this->userProvider = ($this->context !== null && $this->context->hasContainer())
                ? $this->context->getContainer()->get(UserProviderInterface::class)
                : new NullUserProvider();
        }
        return $this->userProvider;
    }

    /**
     * {@inheritdoc}
     */
    public function authenticate(Request $request): ?array
    {
        $this->lastError = null;

        $apiKey = $this->extractApiKeyFromRequest($request);
        if ($apiKey === null || $apiKey === '') {
            $this->lastError = 'API key not found in request';
            return null;
        }

        if ($this->context === null) {
            $this->lastError = 'No application context available for API key verification';
            return null;
        }

        try {
            $key = ApiKeyService::verify(
                $this->context,
                $apiKey,
                $request->getClientIp() ?? ''
            );

            $identity = $this->getUserProvider()->findByUuid($key->user_uuid);
            if ($identity === null) {
                $this->lastError = 'API key belongs to no known user';
                return null;
            }
            // Identity-only shape (clean break): user_data is the UserIdentity array, not a
            // full user-table row.
            $userData = $identity->toArray();

            $request->attributes->set('authenticated', true);
            // Request attribute name stays 'user_id' (the generic auth-principal key read by
            // SessionContext); its value is the principal uuid from the api_keys.user_uuid column.
            $request->attributes->set('user_id', $key->user_uuid);
            $request->attributes->set('user_data', $userData);
            $request->attributes->set('auth_method', 'api_key');
            $request->attributes->set('api_key_scopes', $key->getScopes());

            return $userData;
        } catch (ApiKeyExpiredException) {
            $this->lastError = 'Expired API key';
            return null;
        } catch (InvalidApiKeyException) {
            $this->lastError = 'Invalid API key';
            return null;
        } catch (\Throwable $e) {
            $this->lastError = 'Authentication error: ' . $e->getMessage();
            return null;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isAdmin(array $userData): bool
    {
        try {
            if ($this->authManager !== null) {
                return $this->authManager->isAdmin($userData);
            }
        } catch (\Throwable) {
            $user = $userData['user'] ?? $userData;
            return (bool)($user['is_admin'] ?? false);
        }

        $user = $userData['user'] ?? $userData;
        return (bool)($user['is_admin'] ?? false);
    }

    /**
     * {@inheritdoc}
     */
    public function getError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Extract API key from request
     */
    private function extractApiKeyFromRequest(Request $request): ?string
    {
        // Check for API key in the X-API-Key header (preferred)
        $apiKey = $request->headers->get('X-API-Key');
        if ($apiKey !== null && $apiKey !== '') {
            return $apiKey;
        }

        // Check for API key in the query string
        $apiKey = $request->query->get('api_key');
        if (is_string($apiKey) && $apiKey !== '') {
            return $apiKey;
        }

        // Check for API key in the Authorization header with "ApiKey" prefix
        $authHeader = $request->headers->get('Authorization');
        if ($authHeader !== null && $authHeader !== '' && strpos($authHeader, 'ApiKey ') === 0) {
            return substr($authHeader, 7);
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function validateToken(string $token): bool
    {
        if ($this->context === null) {
            $this->lastError = 'No application context available for API key verification';
            return false;
        }

        try {
            // Client IP isn't available here (no Request). Passing empty IP
            // means rows with an allowed_ips restriction will reject. Routes
            // that need IP-aware validation should use authenticate(Request).
            ApiKeyService::verify($this->context, $token, '');
            return true;
        } catch (ApiKeyExpiredException) {
            $this->lastError = 'Expired API key';
            return false;
        } catch (InvalidApiKeyException) {
            $this->lastError = 'Invalid API key';
            return false;
        } catch (\Throwable $e) {
            $this->lastError = 'API key validation error: ' . $e->getMessage();
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function canHandleToken(string $token): bool
    {
        // API keys match this shape: gf_live_/gf_test_ prefix or a generic
        // 16-64 char alphanumeric/dash/underscore token. The generic match
        // stays for backward compatibility with consumers that experimented
        // with custom token shapes.
        return (bool) preg_match('/^[a-zA-Z0-9_\-]{16,64}$/', $token);
    }

    /**
     * {@inheritdoc}
     *
     * API keys are administratively created via `php glueful apikey:create`,
     * not generated at authentication time. Callers that arrive here are
     * typically trying to use the JWT-style auth code paths against an API
     * key provider — that's a programming error that should be reported
     * explicitly rather than papered over with an empty token response.
     *
     * @param array<string, mixed> $userData
     * @return array<string, mixed>
     */
    public function generateTokens(
        array $userData,
        ?int $accessTokenLifetime = null,
        ?int $refreshTokenLifetime = null
    ): array {
        $this->lastError = 'API keys are created administratively via apikey:create CLI, '
            . 'not generated at authentication time. Use ApiKeyService::create() directly '
            . 'or the CLI command if you need to mint a new key.';

        return [
            'access_token'  => '',
            'refresh_token' => '',
            'expires_in'    => 0,
        ];
    }

    /**
     * {@inheritdoc}
     *
     * For API keys "refresh" means "the same key is still valid, here it is
     * again" — no token rotation in the OAuth/JWT sense. Verify via the new
     * table and return the same plaintext key with an updated expires_in.
     *
     * @param array<string, mixed> $sessionData
     * @return array{access_token: string, refresh_token: string, expires_in: int}|null
     */
    public function refreshTokens(string $refreshToken, array $sessionData): ?array
    {
        if ($this->context === null) {
            $this->lastError = 'No application context available for API key verification';
            return null;
        }

        try {
            $key = ApiKeyService::verify($this->context, $refreshToken, '');

            $expiresIn = 0;
            $expiresAt = $key->expires_at ?? null;
            if (is_string($expiresAt) && $expiresAt !== '') {
                $ts = strtotime($expiresAt);
                if ($ts !== false) {
                    $expiresIn = max(0, $ts - time());
                }
            }

            return [
                'access_token'  => $refreshToken,
                'refresh_token' => $refreshToken,
                'expires_in'    => $expiresIn,
            ];
        } catch (\Throwable $e) {
            $this->lastError = 'Token refresh error: ' . $e->getMessage();
            return null;
        }
    }
}

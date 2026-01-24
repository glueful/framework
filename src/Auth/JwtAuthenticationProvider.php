<?php

declare(strict_types=1);

namespace Glueful\Auth;

use Symfony\Component\HttpFoundation\Request;
use Glueful\Auth\Interfaces\AuthenticationProviderInterface;
use Glueful\Auth\Traits\ResolvesSessionStore;

/**
 * JWT Authentication Provider
 *
 * Implements authentication using JWT tokens and the existing
 * authentication infrastructure in the Glueful framework.
 *
 * This provider leverages the TokenManager and SessionStore
 * while providing a standardized interface for authentication.
 */
class JwtAuthenticationProvider implements AuthenticationProviderInterface
{
    use ResolvesSessionStore;

    /** @var string|null Last authentication error message */
    private ?string $lastError = null;

    /**
     * {@inheritdoc}
     */
    public function authenticate(Request $request): ?array
    {
        $this->lastError = null;

        try {
            // Extract token using centralized extractor
            $token = TokenManager::extractTokenFromRequest();

            if ($token === null || $token === '') {
                $this->lastError = 'No authentication token provided';
                return null;
            }

            // Validate token and get session data using SessionStore via DI
            $sessionStore = $this->getSessionStore();
            $sessionData = $sessionStore->getByAccessToken($token);
            if ($sessionData === null) {
                $this->lastError = 'Invalid or expired authentication token';
                return null;
            }

            // Decode JWT token to get the full user data
            $payload = JWTService::decode($token);
            if ($payload === null) {
                $this->lastError = 'Invalid JWT token payload';
                return null;
            }

            // Use the JWT payload as user data since it contains all user information
            $userData = $payload;
            $userData['session_uuid'] = $sessionData['uuid'];
            $userData['provider'] = $sessionData['provider'] ?? 'jwt';

            // Store authentication info in request attributes for middleware
            $request->attributes->set('authenticated', true);
            $request->attributes->set('user_id', $userData['uuid'] ?? null);
            $request->attributes->set('user_data', $userData);

            return $userData;
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
        // Delegate to the central AuthenticationManager to avoid duplication
        try {
            $manager = AuthBootstrap::getManager();
            return $manager->isAdmin($userData);
        } catch (\Throwable) {
            // Conservative fallback to original heuristic
            $user = $userData['user'] ?? $userData;
            return (bool)($user['is_admin'] ?? false);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getError(): ?string
    {
        return $this->lastError;
    }

    /**
     * {@inheritdoc}
     */
    public function validateToken(string $token): bool
    {
        try {
            // Use JWTService to verify the token
            return JWTService::verify($token);
        } catch (\Throwable $e) {
            $this->lastError = 'Token validation error: ' . $e->getMessage();
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function canHandleToken(string $token): bool
    {
        try {
            // Check if the token is a valid JWT structure
            // JWT tokens consist of 3 parts separated by periods
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                return false;
            }

            // Try to decode the header (first part) with base64url handling
            $b64 = strtr($parts[0], '-_', '+/');
            $b64 .= str_repeat('=', (4 - strlen($b64) % 4) % 4);
            $headerJson = base64_decode($b64, true);
            if ($headerJson === false || $headerJson === '') {
                return false;
            }

            $header = json_decode($headerJson, true);
            // Check if it has typical JWT header fields
            return is_array($header) && isset($header['alg']) && isset($header['typ']);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function generateTokens(
        array $userData,
        ?int $accessTokenLifetime = null,
        ?int $refreshTokenLifetime = null
    ): array {
        try {
            // Use TokenManager to generate token pair
            return TokenManager::generateTokenPair(
                $userData,
                $accessTokenLifetime,
                $refreshTokenLifetime
            );
        } catch (\Throwable $e) {
            $this->lastError = 'Token generation error: ' . $e->getMessage();
            return [
                'access_token' => '',
                'refresh_token' => '',
                'expires_in' => 0
            ];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function refreshTokens(string $refreshToken, array $sessionData): ?array
    {
        try {
            // Verify that the refresh token matches the one in session data
            if (!isset($sessionData['refresh_token']) || $sessionData['refresh_token'] !== $refreshToken) {
                $this->lastError = 'Invalid refresh token';
                return null;
            }

            // Generate new token pair for existing session
            return $this->generateTokens($sessionData);
        } catch (\Throwable $e) {
            $this->lastError = 'Token refresh error: ' . $e->getMessage();
            return null;
        }
    }
}

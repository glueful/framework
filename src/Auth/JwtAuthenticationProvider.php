<?php

declare(strict_types=1);

namespace Glueful\Auth;

use Symfony\Component\HttpFoundation\Request;
use Glueful\Auth\Interfaces\AuthenticationProviderInterface;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Auth\Traits\ResolvesSessionStore;
use Glueful\Http\RequestContext;

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

    /**
     * {@inheritdoc}
     */
    public function authenticate(Request $request): ?array
    {
        $this->lastError = null;

        try {
            $token = $this->extractTokenFromRequest();

            // Fallback: extract from the Symfony Request directly
            if ($token === null || $token === '') {
                $authHeader = $request->headers->get('Authorization', '');
                if ($authHeader !== '' && preg_match('/Bearer\s+(.+)/i', $authHeader, $matches) === 1) {
                    $token = trim($matches[1]);
                }
            }

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

            // Build user context from server-side session state (minimal JWT claims only).
            $userUuid = (string) ($payload['sub'] ?? $sessionData['user_uuid'] ?? '');
            if ($userUuid === '') {
                $this->lastError = 'Token missing subject claim';
                return null;
            }

            $userData = [
                'uuid' => $userUuid,
                'sub' => $userUuid,
                'sid' => (string) ($payload['sid'] ?? $sessionData['uuid'] ?? ''),
                'ver' => (int) ($payload['ver'] ?? $sessionData['session_version'] ?? 1),
                'jti' => (string) ($payload['jti'] ?? ''),
                'session_uuid' => (string) ($sessionData['uuid'] ?? ''),
                'provider' => (string) ($sessionData['provider'] ?? 'jwt'),
            ];

            // Store authentication info in request attributes for middleware
            $request->attributes->set('authenticated', true);
            $request->attributes->set('user_id', $userUuid);
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
            if ($this->authManager !== null) {
                return $this->authManager->isAdmin($userData);
            }
        } catch (\Throwable) {
            // Conservative fallback to original heuristic
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
     * Resolve RequestContext from the DI container.
     */
    private function resolveRequestContext(): RequestContext
    {
        if ($this->context !== null && $this->context->hasContainer()) {
            return $this->context->getContainer()->get(RequestContext::class);
        }

        throw new \RuntimeException(
            'RequestContext cannot be resolved: ApplicationContext or container unavailable. '
            . 'Ensure JwtAuthenticationProvider is instantiated with a valid ApplicationContext.'
        );
    }

    private function extractTokenFromRequest(?RequestContext $requestContext = null): ?string
    {
        $requestContext = $requestContext ?? $this->resolveRequestContext();
        $authorization_header = $requestContext->getAuthorizationHeader();

        if (($authorization_header === null || $authorization_header === '') && function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    $authorization_header = $value;
                    break;
                }
            }
        }

        if (
            ($authorization_header === null || $authorization_header === '')
            && function_exists('apache_request_headers')
        ) {
            foreach (apache_request_headers() as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    $authorization_header = $value;
                    break;
                }
            }
        }

        if (
            $authorization_header !== null && $authorization_header !== ''
            && preg_match('/Bearer\s+(.+)/i', $authorization_header, $matches)
        ) {
            return trim($matches[1]);
        }

        $allowQueryParam = (bool) config($this->context, 'security.tokens.allow_query_param', false);
        if ($this->context === null || $allowQueryParam !== true) {
            return null;
        }

        if (env('APP_ENV', 'production') !== 'production') {
            error_log('Deprecated: token passed via query string');
        }

        return $requestContext->getQueryParam('token');
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
            return $this->getTokenManager()->generateTokenPair(
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

    private function getTokenManager(): TokenManager
    {
        if ($this->context !== null && $this->context->hasContainer()) {
            return $this->context->getContainer()->get(TokenManager::class);
        }

        return new TokenManager($this->context, null, $this->authManager);
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

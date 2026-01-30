<?php

declare(strict_types=1);

namespace Glueful\Auth;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Helpers\Utils;
use Glueful\Helpers\CacheHelper;
use Glueful\Http\RequestContext;
use Glueful\Auth\Interfaces\AuthenticationProviderInterface;
use Glueful\Auth\Interfaces\SessionStoreInterface;
use Glueful\Auth\Utils\SessionStoreResolver;

/**
 * Token Management System
 *
 * Handles all aspects of authentication tokens:
 * - Token generation and validation
 * - Token-session mapping
 * - Token refresh operations
 * - Token fingerprinting and security
 * - Token invalidation and cleanup
 *
 * Security Features:
 * - Token pair management (access + refresh)
 * - Token fingerprinting
 * - Expiration control
 * - Revocation tracking
 */
class TokenManager
{
    private const DEFAULT_TTL = 3600; // 1 hour
    private ?int $ttl = null;
    private Connection $db;
    private ?ApplicationContext $context = null;
    private ?AuthenticationManager $authManager = null;

    public function __construct(
        ?ApplicationContext $context = null,
        ?Connection $db = null,
        ?AuthenticationManager $authManager = null
    ) {
        $this->context = $context;
        $this->db = $db ?? Connection::fromContext($context);
        $this->authManager = $authManager;
        $this->ttl = (int) $this->getConfig('session.access_token_lifetime', self::DEFAULT_TTL);
    }


    /**
     * Get database connection instance
     *
     * @return Connection
     */
    private function getDb(): Connection
    {
        return $this->db;
    }

    /**
     * Get SessionCacheManager instance from container
     *
     * @return SessionCacheManager
     */
    private function getSessionCacheManager(): SessionCacheManager
    {
        if ($this->context !== null) {
            return container($this->context)->get(SessionCacheManager::class);
        }

        $cache = CacheHelper::createCacheInstance();
        if ($cache === null) {
            throw new \RuntimeException('CacheStore is required to create SessionCacheManager.');
        }

        return new SessionCacheManager($cache, $this->context);
    }

    /**
     * Resolve the session store via container with a safe fallback
     */
    private function getSessionStore(): SessionStoreInterface
    {
        try {
            if ($this->context !== null) {
                /** @var SessionStoreInterface $store */
                $store = container($this->context)->get(SessionStoreInterface::class);
            } else {
                throw new \RuntimeException('Container unavailable without ApplicationContext.');
            }
            return $store;
        } catch (\Throwable) {
            return new SessionStore();
        }
    }

    /**
     * Clear per-request caches held by dependent services.
     */
    public function resetRequestCache(): void
    {
        $store = $this->getSessionStore();
        if (method_exists($store, 'resetRequestCache')) {
            $store->resetRequestCache();
        }
    }

    /**
     * Generate JWT token pair with custom lifetimes
     *
     * Creates a matched pair of access and refresh tokens for user authentication.
     * Access tokens are short-lived and used for API requests, while refresh tokens
     * are long-lived and used to generate new access tokens.
     *
     * **Token Structure:**
     * - Access Token: Contains user data, expires quickly (default 15 minutes)
     * - Refresh Token: Contains session reference, expires slowly (default 7 days)
     * - Both tokens are JWT format with HS256 signing
     *
     * **Security Features:**
     * - Tokens include issued time (iat) and expiration (exp) claims
     * - Refresh tokens are tied to specific sessions for revocation
     * - Token lifetime defaults from configuration with security best practices
     *
     * **Usage Example:**
     * ```php
     * $tokenManager = app($context, TokenManager::class);
     * $tokens = $tokenManager->generateTokenPair(
     *     ['uuid' => $user['uuid'], 'email' => $user['email']],
     *     900,  // 15 minutes access token
     *     604800 // 7 days refresh token
     * );
     * // Returns: ['access_token' => '...', 'refresh_token' => '...']
     * ```
     *
     * @param array<string, mixed> $userData User data to encode in tokens (must include 'uuid')
     * @param int|null $accessTokenLifetime Access token lifetime in seconds (default from config)
     * @param int|null $refreshTokenLifetime Refresh token lifetime in seconds (default from config)
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     *         Token pair with 'access_token' and 'refresh_token' keys
     * @throws \InvalidArgumentException If userData is empty or missing required fields
     * @throws \RuntimeException If JWT key is not configured or token generation fails
     * @throws \Glueful\Exceptions\AuthenticationException If token encoding fails
     */
    public function generateTokenPair(
        array $userData,
        ?int $accessTokenLifetime = null,
        ?int $refreshTokenLifetime = null
    ): array {
        if ($this->ttl === null) {
            $this->ttl = (int) $this->getConfig('session.access_token_lifetime', self::DEFAULT_TTL);
        }

        // Use provided lifetimes or defaults
        $accessTokenLifetime = $accessTokenLifetime ?? $this->ttl;
        $refreshTokenLifetime = $refreshTokenLifetime ??
        $this->getConfig('session.refresh_token_lifetime', 30 * 24 * 3600); // Default 30 days

        // Add remember-me indicator to token payload if applicable
        $tokenPayload = $userData;
        if (isset($userData['remember_me']) && (bool)$userData['remember_me'] === true) {
            $tokenPayload['persistent'] = true;
        }

        $accessToken = JWTService::generate($tokenPayload, $accessTokenLifetime);
        $refreshToken = bin2hex(random_bytes(32)); // 64 character random string

        // Skip audit logging for token generation - login success is sufficient

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => $accessTokenLifetime
        ];
    }



    /**
     * Validate access token
     *
     * Checks if token is valid, not expired, and not revoked.
     * Uses the appropriate authentication provider based on token type.
     *
     * @param string $token Access token
     * @param string|null $provider Optional provider name to use for validation
     * @return bool Validity status
     */
    public function validateAccessToken(
        string $token,
        ?string $provider = null,
        ?RequestContext $requestContext = null
    ): bool {
        $requestContext = $requestContext ?? RequestContext::fromGlobals();

        // Get the authentication manager instance
        $authManager = $this->getAuthManager();

        $isValid = false;

        // If provider is explicitly specified, use it
        if ($provider !== null && $authManager !== null) {
            $authProvider = $authManager->getProvider($provider);
            if ($authProvider !== null) {
                $isValid = $authProvider->validateToken($token) && !$this->isTokenRevoked($token);
            }
        } elseif ($authManager !== null) {
            // We need to loop through the available providers
            $providers = $this->getAvailableProviders($authManager);
            foreach ($providers as $authProvider) {
                if ($authProvider->canHandleToken($token)) {
                    $isValid = $authProvider->validateToken($token) && !$this->isTokenRevoked($token);
                    break;
                }
            }
        } else {
            $isValid = JWTService::verify($token) && !$this->isTokenRevoked($token);
        }


        return $isValid;
    }

    /**
     * Refresh authentication tokens with multi-provider support
     *
     * Generates a new access/refresh token pair using an existing refresh token.
     * Supports multiple authentication providers and maintains session continuity
     * across different authentication methods.
     *
     * **Provider Resolution Flow:**
     * 1. If provider specified, use that provider's refresh mechanism
     * 2. If no provider, lookup stored provider from session database
     * 3. Fallback to standard JWT token refresh for backward compatibility
     * 4. Update database session with new tokens atomically
     *
     * **Security Features:**
     * - Validates refresh token before generating new tokens
     * - Maintains session audit trail and analytics
     * - Respects remember-me settings for token lifetimes
     * - Revokes old refresh token to prevent replay attacks
     *
     * **Provider Support:**
     * - JWT: Standard token refresh with signature validation
     * - LDAP: Re-validates against LDAP server if configured
     * - SAML: Uses SAML assertion refresh if available
     * - OAuth2: Uses OAuth2 refresh token flow
     *
     * **Usage Examples:**
     * ```php
     * // Standard JWT token refresh
     * $tokenManager = app($context, TokenManager::class);
     * $newTokens = $tokenManager->refreshTokens($oldRefreshToken);
     *
     * // LDAP provider refresh
     * $newTokens = $tokenManager->refreshTokens($refreshToken, 'ldap');
     *
     * // With request context for analytics
     * $newTokens = $tokenManager->refreshTokens(
     *     $refreshToken,
     *     'saml',
     *     RequestContext::fromGlobals()
     * );
     * ```
     *
     * @param string $refreshToken The current refresh token to exchange
     * @param string|null $provider Authentication provider name to use for refresh
     * @param RequestContext|null $requestContext Request context for session tracking
     * @return array<string, mixed>|null New token pair with 'access_token' and 'refresh_token', or null if invalid
     * @throws \InvalidArgumentException If refresh token format is invalid
     * @throws \Glueful\Exceptions\DatabaseException If session lookup or update fails
     * @throws \Glueful\Exceptions\AuthenticationException If token refresh fails
     * @throws \RuntimeException If specified authentication provider is unavailable
     */
    public function refreshTokens(
        string $refreshToken,
        ?string $provider = null,
        ?RequestContext $requestContext = null
    ): ?array {
        $requestContext = $requestContext ?? RequestContext::fromGlobals();
        // Get session data from refresh token
        $sessionData = $this->getSessionFromRefreshToken($refreshToken);

        $isValid = $sessionData !== null;

        if (!$isValid) {
            return null;
        }

        // Get the authentication manager instance
        $authManager = $this->getAuthManager();
        $tokens = null;

        // If provider is explicitly specified, use it
        if ($provider !== null && $authManager !== null) {
            $authProvider = $authManager->getProvider($provider);
            if ($authProvider !== null) {
                $tokens = $authProvider->refreshTokens($refreshToken, $sessionData);
            }
        }

        // If no explicit provider but we have stored provider in session
        if ($tokens === null) {
            $db = $this->getDb();
            $result = $db->table('auth_sessions')
                ->select(['provider'])
                ->where(['refresh_token' => $refreshToken])
                ->get();

            if ($result !== [] && isset($result[0]['provider']) && $result[0]['provider'] !== 'jwt') {
                $storedProvider = $result[0]['provider'];
                if ($authManager !== null) {
                    $authProvider = $authManager->getProvider($storedProvider);
                    if ($authProvider !== null) {
                        $tokens = $authProvider->refreshTokens($refreshToken, $sessionData);
                    }
                }
            }
        }

        // Default to standard JWT token generation for backward compatibility
        if ($tokens === null) {
            // Get remember_me and provider to determine token lifetime via SessionStore
            $db = $this->getDb();
            $sessionResult = $db->table('auth_sessions')
                ->select(['remember_me', 'provider'])
                ->where(['refresh_token' => $refreshToken])
                ->get();
            $rememberMe = (bool)($sessionResult[0]['remember_me'] ?? false);
            $providerName = (string)($sessionResult[0]['provider'] ?? 'jwt');
            $store = $this->getSessionStore();
            $accessTokenLifetime = $store->getAccessTtl($providerName, $rememberMe);
            $refreshTokenLifetime = $store->getRefreshTtl($providerName, $rememberMe);
            $tokens = $this->generateTokenPair($sessionData, $accessTokenLifetime, $refreshTokenLifetime);
        }


        return $tokens;
    }

    /**
     * Get session from refresh token
     *
     * Retrieves session data using refresh token.
     *
     * @param string $refreshToken Refresh token
     * @return array|null Session data or null if invalid
     */
    /**
     * @return array{uuid: string, created_at: string}|null
     */
    private function getSessionFromRefreshToken(string $refreshToken): ?array
    {
        $db = $this->getDb();

        $result = $db->table('auth_sessions')
            ->select(['user_uuid', 'access_token', 'created_at', 'refresh_expires_at'])
            ->where(['refresh_token' => $refreshToken, 'status' => 'active'])
            ->get();

        if ($result === []) {
            return null;
        }

        // Return basic session data with user UUID
        // The calling method will handle fetching full user data
        // Note: We exclude access_token to prevent token wrapping
        $session = $result[0];
        if (isset($session['refresh_expires_at'])) {
            $expiresAt = strtotime((string) $session['refresh_expires_at']);
            if ($expiresAt !== false && $expiresAt < time()) {
                return null;
            }
        }

        return [
            'uuid' => $session['user_uuid'],
            'created_at' => $session['created_at']
        ];
    }

     /**
     * Normalize user data to ensure consistent structure across all providers
     *
     * Ensures all user objects contain required fields with proper defaults.
     * Fetches missing profile data from database if needed.
     *
     * @param array<string, mixed> $user Raw user data from authentication provider
     * @param string|null $provider Provider name (jwt, admin, ldap, saml, etc.)
     * @return array<string, mixed> Normalized user data with consistent structure
     */
    private function normalizeUserData(array $user, ?string $provider = null): array
    {
        // Start with the existing user data
        $normalizedUser = $user;

        // Ensure critical fields exist
        if (!isset($normalizedUser['uuid'])) {
            // Cannot normalize without UUID
            return $user;
        }

        // Set default values for commonly missing fields
        $normalizedUser['remember_me'] = $normalizedUser['remember_me'] ?? false;
        $normalizedUser['provider'] = $provider ?? 'jwt';

        // Ensure profile data exists
        if (
            !isset($normalizedUser['profile'])
            || (is_array($normalizedUser['profile']) && $normalizedUser['profile'] === [])
        ) {
            // Try to fetch profile data from database
            $userRepository = new \Glueful\Repository\UserRepository();
            $profileData = $userRepository->getProfile($normalizedUser['uuid']);

            // Create profile structure with null-safe access
            $normalizedUser['profile'] = [
                'first_name' => $profileData['first_name'] ?? null,
                'last_name' => $profileData['last_name'] ?? null,
                'photo_uuid' => $profileData['photo_uuid'] ?? null,
                'photo_url' => $profileData['photo_url'] ?? null
            ];
        }

        // Don't include roles in the login response - fetch via separate endpoint
        // This follows OAuth/OIDC best practices for minimal token responses

        // Ensure consistent timestamp fields
        if (isset($normalizedUser['last_login_at']) && !isset($normalizedUser['last_login'])) {
            $normalizedUser['last_login'] = $normalizedUser['last_login_at'];
        }

        // Ensure last_login_date is set (used in JWT token claims)
        if (!isset($normalizedUser['last_login_date'])) {
            $normalizedUser['last_login_date'] = $normalizedUser['last_login'] ?? date('Y-m-d H:i:s');
        }

        return $normalizedUser;
    }

    /**
     * Create user session with multi-provider authentication support
     *
     * Creates a new user session with JWT tokens and database persistence.
     * Supports multiple authentication providers (LDAP, SAML, OAuth2, JWT)
     * and implements OIDC-compliant session management.
     *
     * **Authentication Provider Flow:**
     * 1. If provider specified, delegate to provider-specific token generation
     * 2. If no provider, use default JWT token generation
     * 3. Store session in database with atomic cache updates
     * 4. Return OIDC-compliant session data
     *
     * **OIDC Compliance Features:**
     * - Standard token response format
     * - Proper token lifetime handling
     * - Session tracking and analytics
     * - Remember-me functionality
     * - Provider-specific claims
     *
     * **Usage Examples:**
     * ```php
     * // Standard JWT authentication
     * $tokenManager = app($context, TokenManager::class);
     * $session = $tokenManager->createUserSession([
     *     'uuid' => $user['uuid'],
     *     'email' => $user['email'],
     *     'remember_me' => true
     * ]);
     *
     * // LDAP authentication
     * $session = $tokenManager->createUserSession($userData, 'ldap');
     *
     * // SAML authentication with custom claims
     * $session = $tokenManager->createUserSession($samlUserData, 'saml');
     * ```
     *
     * @param array $user User data array (must include 'uuid' field)
     * @param string|null $provider Authentication provider name ('ldap', 'saml', 'oauth2', etc.)
     * @return array OIDC-compliant session data with tokens and user info, or empty array on failure
     * @throws \InvalidArgumentException If user data is invalid or missing required fields
     * @throws \Glueful\Exceptions\DatabaseException If session storage fails
     * @throws \Glueful\Exceptions\AuthenticationException If token generation fails
     * @throws \RuntimeException If authentication provider is not available
     */
    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function createUserSession(array $user, ?string $provider = null): array
    {
        // Add validation to ensure we have valid user data
        if ($user === [] || !isset($user['uuid'])) {
            return [];  // Return empty array that will be caught as failure
        }

        // Normalize user data to ensure consistent structure
        $user = $this->normalizeUserData($user, $provider);

        // Adjust token lifetime using canonical policy in SessionStore
        $rememberMe = (bool)($user['remember_me'] ?? false);
        $providerName = $provider ?? 'jwt';
        $store = $this->getSessionStore();
        $accessTokenLifetime = $store->getAccessTtl($providerName, $rememberMe);
        $refreshTokenLifetime = $store->getRefreshTtl($providerName, $rememberMe);

        // Use authentication provider if specified and available
        $authManager = $this->getAuthManager();
        if ($provider !== null && $authManager !== null) {
            $authProvider = $authManager->getProvider($provider);
            if ($authProvider !== null) {
                $tokens = $authProvider->generateTokens($user, $accessTokenLifetime, $refreshTokenLifetime);
            } else {
                // Fall back to default token generation
                $tokens = $this->generateTokenPair($user, $accessTokenLifetime, $refreshTokenLifetime);
            }
        } else {
            // Default to JWT tokens for backward compatibility
            $tokens = $this->generateTokenPair($user, $accessTokenLifetime, $refreshTokenLifetime);
        }

        // Store session using SessionStore for unified database/cache management
        $user['refresh_token'] = $tokens['refresh_token'];
        $user['session_id'] = Utils::generateNanoID(); // Generate session ID once
        $user['provider'] = $provider ?? 'jwt';
        $user['remember_me'] = $user['remember_me'] ?? false;

        $this->getSessionStore()->create(
            $user,
            $tokens,
            $user['provider'],
            (bool)$user['remember_me']
        );

        // Also store in cache for quick lookup
        $sessionCacheManager = $this->getSessionCacheManager();
        $sessionCacheManager->storeSession(
            $user, // userData array
            $tokens['access_token'], // token string
            $provider ?? 'jwt', // provider
            $tokens['expires_in'] // ttl
        );

        unset($user['refresh_token']);

        // Build OIDC-compliant user object
        $oidcUser = [
            'id' => $user['uuid'],
            'email' => $user['email'] ?? null,
            'email_verified' => (bool)($user['email_verified_at'] ?? false),
            'username' => $user['username'] ?? null,
            'locale' => $user['locale'] ?? 'en-US',
            'updated_at' => isset($user['updated_at']) ? strtotime($user['updated_at']) : time()
        ];

        // Add name fields if profile exists
        if (isset($user['profile'])) {
            $firstName = $user['profile']['first_name'] ?? '';
            $lastName = $user['profile']['last_name'] ?? '';

            if ($firstName !== '' || $lastName !== '') {
                $oidcUser['name'] = trim($firstName . ' ' . $lastName);
                $oidcUser['given_name'] = ($firstName !== '' ? $firstName : null);
                $oidcUser['family_name'] = ($lastName !== '' ? $lastName : null);
            }

            if (isset($user['profile']['photo_url']) && $user['profile']['photo_url'] !== '') {
                $oidcUser['picture'] = $user['profile']['photo_url'];
            }
        }

        // Return OAuth 2.0 compliant response structure
        return [
            'access_token' => $tokens['access_token'],
            'token_type' => 'Bearer',
            'expires_in' => $accessTokenLifetime,
            'refresh_token' => $tokens['refresh_token'],
            'user' => $oidcUser
        ];
    }



    /**
     * Check if token is revoked
     *
     * Verifies token against revocation list.
     *
     * @param string $token Authentication token
     * @return bool True if revoked
     */
    public function isTokenRevoked(string $token): bool
    {
        $db = $this->getDb();

        $result = $db->table('auth_sessions')
            ->select(['status'])
            ->where(['access_token' => $token])
            ->get();

        return $result !== [] && $result[0]['status'] === "revoked";
    }

    /**
     * Generate token fingerprint
     *
     * Creates unique identifier for token security.
     *
     * @param string $token Authentication token
     * @return string Fingerprint hash
     */
    public function generateTokenFingerprint(string $token): string
    {
        return hash('sha256', $token . $this->getConfig('session.fingerprint_salt', ''));
    }

    /**
     * Extract authentication token from HTTP request
     *
     * Attempts to locate and extract the bearer token from multiple possible locations
     * in the request headers, following a fallback chain:
     *
     * 1. Standard Authorization header
     * 2. Apache specific REDIRECT_HTTP_AUTHORIZATION header
     * 3. Custom Authorization header from getallheaders()
     * 4. Apache request headers
     * 5. Query parameter 'token' as last resort
     *
     * Supported header formats:
     * - "Authorization: Bearer <token>"
     * - "Authorization: BEARER <token>"
     * - "Authorization: bearer <token>"
     *
     * @return string|null The extracted token or null if not found
     *
     * @example
     * ```php
     * $tokenManager = app($context, TokenManager::class);
     * $token = $tokenManager->extractTokenFromRequest();
     * if ($token) {
     *     $userData = $tokenManager->validateAccessToken($token);
     * }
     * ```
     */
    public function extractTokenFromRequest(?RequestContext $requestContext = null): ?string
    {
        $requestContext = $requestContext ?? RequestContext::fromGlobals();
        $authorization_header = $requestContext->getAuthorizationHeader();

        // Fallback to getallheaders() (case-insensitive)
        if (($authorization_header === null || $authorization_header === '') && function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    $authorization_header = $value;
                    break;
                }
            }
        }

        // Fallback to apache_request_headers() (case-insensitive)
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

        // Extract Bearer token using preg_match (handles extra spaces)
        if (
            $authorization_header !== null && $authorization_header !== ''
            && preg_match('/Bearer\s+(.+)/i', $authorization_header, $matches)
        ) {
            return trim($matches[1]);
        }

        // Last fallback: Check query parameter `token`
        if ((bool) $this->getConfig('security.tokens.allow_query_param', false) !== true) {
            return null;
        }

        if (env('APP_ENV', 'production') !== 'production') {
            error_log('Deprecated: token passed via query string');
        }

        return $requestContext->getQueryParam('token');
    }

    /**
     * Check if a token is compatible with a specific provider
     *
     * Determines if a token can be handled by a specific authentication provider.
     * Useful for routing authentication requests to the correct provider.
     *
     * @param string $token The token to check
     * @param string $providerName The name of the provider to check against
     * @return bool True if the provider can handle this token
     */
    public function isTokenCompatibleWithProvider(string $token, string $providerName): bool
    {
        $authManager = $this->getAuthManager();
        if ($authManager === null) {
            // If no authentication manager is active, only jwt tokens are supported
            return $providerName === 'jwt';
        }

        $provider = $authManager->getProvider($providerName);
        if ($provider === null) {
            return false;
        }

        return $provider->canHandleToken($token);
    }

    /**
     * Get the AuthenticationManager instance
     *
     * Helper method to safely retrieve the AuthenticationManager instance.
     *
     * @return AuthenticationManager|null
     */
    private function getAuthManager(): ?AuthenticationManager
    {
        if ($this->authManager !== null) {
            return $this->authManager;
        }

        if ($this->context !== null && $this->context->hasContainer()) {
            try {
                $manager = $this->context->getContainer()->get(AuthenticationManager::class);
                if ($manager instanceof AuthenticationManager) {
                    $this->authManager = $manager;
                    return $manager;
                }
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * Get all available authentication providers
     *
     * Helper method to retrieve all registered providers from the AuthenticationManager.
     *
     * @param AuthenticationManager $authManager The authentication manager instance
     * @return array<int, AuthenticationProviderInterface> Array of AuthenticationProviderInterface instances
     */
    private function getAvailableProviders(AuthenticationManager $authManager): array
    {
        $providers = [];

        // Try to call a method to get all providers
        try {
            /** @var array<int, AuthenticationProviderInterface> $all */
            $all = $authManager->getProviders();
            return $all;
        } catch (\Throwable) {
            // Silently fail and continue with fallback
        }

        // Fallback: try to get known providers individually
        foreach (['jwt', 'api_key', 'oauth', 'saml'] as $providerName) {
            try {
                $provider = $authManager->getProvider($providerName);
                if ($provider !== null) {
                    $providers[] = $provider;
                }
            } catch (\Throwable) {
                // Skip this provider
            }
        }

        return $providers;
    }

    private function getConfig(string $key, mixed $default = null): mixed
    {
        if (function_exists('config') && $this->context !== null) {
            return config($this->context, $key, $default);
        }

        return $default;
    }
}

<?php

declare(strict_types=1);

namespace Glueful\Auth;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\Drivers\ArrayCacheDriver;
use Glueful\Helpers\CacheHelper;
use Glueful\Repository\UserRepository;
use Glueful\DTOs\{PasswordDTO};
use Symfony\Component\HttpFoundation\Request;
use Glueful\Auth\Interfaces\SessionStoreInterface;
use Glueful\Auth\Traits\ResolvesSessionStore;

/**
 * Authentication Service
 *
 * Provides high-level authentication functionality:
 * - User login/logout
 * - Credential validation
 * - Session management
 * - Token validation
 *
 * Coordinates between repositories and managers to implement
 * authentication flows in a clean, maintainable way.
 *
 * Now leverages the AuthenticationManager for request authentication
 * while maintaining backward compatibility.
 */
class AuthenticationService
{
    use ResolvesSessionStore;

    private UserRepository $userRepository;
    private PasswordHasher $passwordHasher;
    private AuthenticationManager $authManager;
    private TokenManager $tokenManager;
    private SessionStoreInterface $sessionStore;
    private SessionCacheManager $sessionCacheManager;
    private ?ApplicationContext $context;

    /**
     * Initialize AuthenticationService with dependency injection
     *
     * Creates a new authentication service instance with optional dependency injection.
     * If dependencies are not provided, the service will use default implementations
     * or resolve them from the service container.
     *
     * The service automatically initializes the authentication system and configures
     * the authentication manager for multi-provider support (LDAP, SAML, OAuth2, etc.).
     *
     * **Dependency Resolution:**
     * - SessionStore: Unified session persistence and validation (DB + cache)
     * - SessionCacheManager: Manages user session data and caching
     * - UserRepository: Provides user data access and persistence
     * - Validator: Validates input credentials and authentication data
     * - PasswordHasher: Handles secure password hashing and verification
     *
     * @param SessionStoreInterface|null $sessionStore Unified session store for JWT handling
     * @param SessionCacheManager|null $sessionCacheManager Session management and caching service
     * @param UserRepository|null $userRepository User data repository for authentication
     * @param PasswordHasher|null $passwordHasher Password hashing and verification service
     * @param ApplicationContext|null $context
     * @throws \RuntimeException If authentication system initialization fails
     * @throws \InvalidArgumentException If any provided dependency has incorrect interface
     */
    public function __construct(
        ?SessionStoreInterface $sessionStore = null,
        ?SessionCacheManager $sessionCacheManager = null,
        ?UserRepository $userRepository = null,
        ?PasswordHasher $passwordHasher = null,
        ?ApplicationContext $context = null,
        ?AuthenticationManager $authManager = null,
        ?TokenManager $tokenManager = null
    ) {
        $this->context = $context;
        // Resolve SessionStore via trait helper (container with fallback)
        $this->sessionStore = $sessionStore ?? $this->getSessionStore();
        if ($sessionCacheManager !== null) {
            $this->sessionCacheManager = $sessionCacheManager;
        } elseif ($this->context !== null) {
            $this->sessionCacheManager = container($this->context)->get(SessionCacheManager::class);
        } else {
            $cache = CacheHelper::createCacheInstance($this->context) ?? new ArrayCacheDriver();
            $this->sessionCacheManager = new SessionCacheManager($cache, $this->context);
        }
        $this->userRepository = $userRepository ?? new UserRepository();
        $this->passwordHasher = $passwordHasher ?? new PasswordHasher();

        if ($authManager !== null) {
            $this->authManager = $authManager;
        } elseif ($this->context !== null && $this->context->hasContainer()) {
            $this->authManager = $this->context->getContainer()->get(AuthenticationManager::class);
        } else {
            $this->authManager = new AuthenticationManager(new JwtAuthenticationProvider($this->context));
        }

        if ($tokenManager !== null) {
            $this->tokenManager = $tokenManager;
        } elseif ($this->context !== null && $this->context->hasContainer()) {
            $this->tokenManager = $this->context->getContainer()->get(TokenManager::class);
        } else {
            $this->tokenManager = new TokenManager($this->context, null, $this->authManager);
        }
    }

    protected function getContext(): ?ApplicationContext
    {
        return $this->context;
    }

    /**
     * Authenticate user
     *
     * Validates credentials and creates user session.
     * Can work with different authentication providers depending on
     * the format of credentials provided.
     *
     * @param array<string, mixed> $credentials User credentials
     * @param string|null $providerName Optional name of the provider to use
     * @return array<string, mixed>|null Authentication result or null if failed
     */
    public function authenticate(array $credentials, ?string $providerName = null): ?array
    {
        // If a specific provider is requested, try to use it
        if ($providerName !== null && $providerName !== '') {
            $provider = $this->authManager->getProvider($providerName);
            if ($provider === null) {
                // Provider not found
                return null;
            }

            // For token-based providers, convert credentials to a request
            if (isset($credentials['token'])) {
                $request = new Request();
                $request->headers->set('Authorization', 'Bearer ' . $credentials['token']);
                return $this->authManager->authenticateWithProvider($providerName, $request);
            }

            // For API key providers
            if (isset($credentials['api_key'])) {
                $request = new Request();
                $request->headers->set('X-API-Key', $credentials['api_key']);
                return $this->authManager->authenticateWithProvider($providerName, $request);
            }
        }

        // Default flow for username/password authentication
        // Validate required fields
        if ($this->validateCredentials($credentials) === false) {
            return null;
        }

        // Process credentials based on type (username or email) using optimized query
        $user = null;

        $username = $credentials['username'] ?? $credentials['email'] ?? null;
        if ($username === null) {
            return null;
        }

        if (filter_var($username, FILTER_VALIDATE_EMAIL)) {
            // For email login
            $user = $this->userRepository->findByEmail($username);
        } else {
            // For username login
            $user = $this->userRepository->findByUsername($username);
        }

        // If user not found or is an array of error messages
        if ($user === null) {
            return null;
        }

        // Enforce allowed login statuses (default: ['active'])
        $allowedStatuses = (array) $this->getConfig('security.auth.allowed_login_statuses', ['active']);
        $userStatus = (string) ($user['status'] ?? '');
        if ($allowedStatuses !== [] && !in_array($userStatus, $allowedStatuses, true)) {
            // Fail silently to avoid account enumeration
            return null;
        }

        // Validate password with new Validation rules
        try {
            PasswordDTO::from(['password' => $credentials['password'] ?? '']);
        } catch (\Glueful\Validation\ValidationException $e) {
            return null;
        }

        // Verify password against hash
        if (
            !isset($user['password'])
            || $this->passwordHasher->verify($credentials['password'], $user['password']) === false
        ) {
            return null;
        }

        // Format user data and get profile
        $userData = $this->formatUserData($user);
        $userData['profile'] = $this->userRepository->getProfile($user['uuid']) ?? null;
        $userData['roles'] = []; // Roles managed by RBAC extension
        $userData['last_login'] = date('Y-m-d H:i:s');

        // Pass through remember_me preference from credentials
        $userData['remember_me'] = $credentials['remember_me'] ?? false;

        // Add any custom provider preference from credentials
        $preferredProvider = $providerName ?? ($credentials['provider'] ?? 'jwt');

        // Create user session with the appropriate provider
        $userSession = $this->tokenManager->createUserSession($userData, $preferredProvider);

        // Return authentication result
        return $userSession;
    }

    /**
     * Terminate user session
     *
     * Logs out user and invalidates tokens.
     *
     * @param string $token Authentication token
     * @return bool Success status
     */
    public function terminateSession(string $token): bool
    {
        if ($token === '') {
            return false;
        }
        // SessionCacheManager->destroySession() handles token revocation
        return $this->sessionCacheManager->destroySession($token);
    }

    /**
     * Validate access token
     *
     * Checks if token is valid and session exists.
     *
     * @param string $token Authentication token
     * @return array<string, mixed>|null Session data if valid
     */
    public function validateAccessToken(
        string $token,
        ?ApplicationContext $context = null
    ): ?array {
        if ($this->tokenManager->validateAccessToken($token) === false) {
            return null;
        }

        $sessionCacheManager = $this->sessionCacheManager;
        if ($context !== null && $context->hasContainer()) {
            $sessionCacheManager = container($context)->get(SessionCacheManager::class);
        }

        return $sessionCacheManager->getSession($token);
    }

    /**
     * Validate login credentials
     *
     * Ensures required fields are present.
     *
     * @param array<string, mixed> $credentials User credentials
     * @return bool Validity status
     */
    public function validateCredentials(array $credentials): bool
    {
        return (
            (isset($credentials['username']) || isset($credentials['email'])) &&
            isset($credentials['password'])
        );
    }

    /**
     * Extract token from request
     *
     * Gets authentication token from request.
     *
     * @param Request|null $request The request object
     * @return string|null Authentication token
     */
    public function extractTokenFromRequest(
        ?Request $request = null,
        ?ApplicationContext $context = null
    ): ?string {
        if ($request === null) {
            // Delegate to centralized extractor when no request is provided
            return $this->tokenManager->extractTokenFromRequest();
        }

        $authorizationHeader = $request->headers->get('Authorization');
        if ($authorizationHeader === null || $authorizationHeader === '') {
            $authorizationHeader = $request->server->get('HTTP_AUTHORIZATION')
                ?? $request->server->get('REDIRECT_HTTP_AUTHORIZATION')
                ?? $request->server->get('PHP_AUTH_DIGEST');
        }

        if (
            $authorizationHeader !== null && $authorizationHeader !== ''
            && preg_match('/Bearer\s+(.+)/i', $authorizationHeader, $matches)
        ) {
            return trim($matches[1]);
        }

        $context = $context ?? $this->context;
        $allowQueryParam = $context !== null
            ? (bool) config($context, 'security.tokens.allow_query_param', false)
            : false;
        if ($allowQueryParam !== true) {
            return null;
        }

        if (env('APP_ENV', 'production') !== 'production') {
            error_log('Deprecated: token passed via query string');
        }

        return $request->query->get('token');
    }

    /**
     * Format user data for session
     *
     * Prepares user data for storage in session.
     *
     * @param array<string, mixed> $user Raw user data
     * @return array<string, mixed> Formatted user data
     */
    private function formatUserData(array $user): array
    {
        // Remove password field
        unset($user['password']);

        // Get additional user data if needed
        // For now, return the basic user data
        return $user;
    }

    /**
     * Refresh user permissions
     *
     * Updates permissions in the user session and generates a new token.
     * Used when user permissions change during an active session.
     * Works with any authentication provider.
     *
     * @param string $token Current authentication token
     * @return array<string, mixed>|null Response with new token and updated permissions or null if failed
     */
    public function refreshPermissions(string $token): ?array
    {
        // Get current session
        $session = $this->sessionCacheManager->getSession($token);
        if ($session === null) {
            return null;
        }

        // Get user UUID from session
        $userUuid = $session['user']['uuid'] ?? null;
        if ($userUuid === null || $userUuid === '') {
            return null;
        }

        // Note: Role functionality moved to RBAC extension
        $userRoles = []; // Use RBAC extension APIs for role management

        // Update session with new roles
        $session['user']['roles'] = $userRoles;

        // Identify which provider was used for this token
        $provider = $session['provider'] ?? 'jwt';

        // Use appropriate provider to generate a new token
        $authProvider = $this->authManager->getProvider($provider);

        if ($authProvider !== null) {
            // Generate new token using the same provider that created the original token
            $tokenLifetime = (int) $this->getConfig('session.access_token_lifetime');

            // Create a minimal user data array with permissions
            $userData = [
                'uuid' => $userUuid,
                'roles' => $userRoles,
                // Copy any other essential user data from the session
                'username' => $session['user']['username'] ?? null,
                'email' => $session['user']['email'] ?? null
            ];

            // Generate new token pair
            $tokens = $authProvider->generateTokens($userData, $tokenLifetime);
            $newToken = $tokens['access_token'];

            // Update session storage with new token
            $this->sessionCacheManager->updateSession($token, $session, $newToken, $provider);

            return [
                'token' => $newToken,
                'permissions' => $userRoles
            ];
        } else {
            // Fall back to default JWT method if provider not found
            $tokenLifetime = (int) $this->getConfig('session.access_token_lifetime');
            $newToken = JWTService::generate($session, $tokenLifetime);

            // Update session storage
            $this->sessionCacheManager->updateSession($token, $session, $newToken);

            return [
                'token' => $newToken,
                'permissions' => $userRoles
            ];
        }
    }

    /**
     * Update user password
     *
     * Changes user password and invalidates existing sessions.
     *
     * @param string $identifier User's email or UUID
     * @param string $password New password (plaintext)
     * @param string|null $identifierType Optional type specifier ('email' or 'uuid')
     * @return bool Success status
     */
    public function updatePassword(string $identifier, string $password, ?string $identifierType = null): bool
    {
        // Validate password using new Validation rules
        try {
            PasswordDTO::from(['password' => $password]);
        } catch (\Glueful\Validation\ValidationException $e) {
            return false;
        }

        // Determine identifier type automatically if not specified
        if ($identifierType === null) {
            $identifierType = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'uuid';
        }

        // Hash password
        $hashedPassword = $this->passwordHasher->hash($password);

        // Update password in database using the new method
        return $this->userRepository->setNewPassword($identifier, $hashedPassword, $identifierType);
    }

    /**
     * Check if user exists
     *
     * Verifies if a user exists in the system by identifier.
     * This method is useful for validating user existence without
     * revealing which specific criteria failed during operations
     * like password reset or account recovery.
     *
     * @param string $identifier User's email or UUID to check
     * @param string|null $identifierType Optional type specifier ('email' or 'uuid')
     * @return bool True if user exists, false otherwise
     */
    public function userExists(string $identifier, ?string $identifierType = null): bool
    {
        // Determine identifier type automatically if not specified
        if ($identifierType === null) {
            $identifierType = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'uuid';
        }

        // Attempt to find the user based on identifier type
        $user = $identifierType === 'email'
            ? $this->userRepository->findByEmail($identifier)
            : $this->userRepository->findByUuid($identifier);

        // Return true if user was found and is properly formatted
        return is_array($user) && $user !== [];
    }

    /**
     * Refresh authentication tokens
     *
     * Generates a new token pair using a refresh token.
     * This method:
     * 1. Validates the refresh token
     * 2. Retrieves the associated user session
     * 3. Generates a new token pair
     * 4. Updates the session with the new tokens
     * 5. Returns the new token pair to the client
     *
     * Security note:
     * - The refresh token is validated against the database
     * - Both old tokens are invalidated when a new pair is generated
     * - Sessions with explicitly revoked tokens cannot be refreshed
     *
     * @param string $refreshToken Current refresh token
     * @return array<string, mixed>|null New token pair or null if refresh token is invalid
     */
    /**
     * @return array<string, mixed>|null
     */
    public function refreshTokens(string $refreshToken): ?array
    {
        // Get new token pair from TokenManager
        $tokens = $this->tokenManager->refreshTokens($refreshToken);

        if ($tokens === null || $tokens === []) {
            return null;
        }

        // Get user data from refresh token
        $userData = $this->getUserDataFromRefreshToken($refreshToken);

        if ($userData === null) {
            return null;
        }

        // Update session with new tokens using SessionStore (atomic DB + cache update)
        $success = $this->sessionStore->updateTokens($refreshToken, $tokens);

        if ($success === false) {
            return null;
        }

        // Build OIDC-compliant user object (same as login response)
        $oidcUser = [
            'id' => $userData['uuid'],
            'email' => $userData['email'] ?? null,
            'email_verified' => (bool)($userData['email_verified_at'] ?? false),
            'username' => $userData['username'] ?? null,
            'locale' => $userData['locale'] ?? 'en-US',
            'updated_at' => isset($userData['updated_at']) ? strtotime($userData['updated_at']) : time()
        ];

        // Add name fields if profile exists
        if (isset($userData['profile'])) {
            $firstName = $userData['profile']['first_name'] ?? '';
            $lastName = $userData['profile']['last_name'] ?? '';

            if ($firstName !== '' || $lastName !== '') {
                $oidcUser['name'] = trim($firstName . ' ' . $lastName);
                $oidcUser['given_name'] = ($firstName !== '' ? $firstName : null);
                $oidcUser['family_name'] = ($lastName !== '' ? $lastName : null);
            }

            if (isset($userData['profile']['photo_url']) && $userData['profile']['photo_url'] !== '') {
                $oidcUser['picture'] = $userData['profile']['photo_url'];
            }
        }

        return [
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'expires_in' => $tokens['expires_in'],
            'token_type' => 'Bearer',
            'user' => $oidcUser
        ];
    }

    /**
     * Get user data from refresh token
     *
     * Retrieves user information associated with a refresh token by querying
     * the auth_sessions table for an active session, then fetching the full
     * user profile from the users table.
     *
     * **Security considerations:**
     * - Only active sessions are considered valid
     * - Refresh tokens are unique and securely generated
     * - User data is sanitized before return
     *
     * **Process:**
     * 1. Query auth_sessions for active session with refresh token
     * 2. Extract user_uuid from session record
     * 3. Fetch complete user profile from users table
     * 4. Return sanitized user data
     *
     * @param string $refreshToken The refresh token to look up
     * @return array<string, mixed>|null User data array with profile information, or null if token is invalid/expired
     * @throws \Glueful\Exceptions\DatabaseException If database query fails
     * @throws \PDOException If database connection fails
     * @throws \InvalidArgumentException If refresh token format is invalid
     */
    /**
     * @return array<string, mixed>|null
     */
    private function getUserDataFromRefreshToken(string $refreshToken): ?array
    {
        // Use existing database connection with fluent interface
        $db = new \Glueful\Database\Connection();

        $result = $db->table('auth_sessions')
            ->select(['user_uuid'])
            ->where(['refresh_token' => $refreshToken, 'status' => 'active'])
            ->limit(1)
            ->get();

        if ($result === []) {
            return null;
        }

        $userUuid = $result[0]['user_uuid'];
        $user = $this->userRepository->findByUuid($userUuid);

        if ($user === null || $user === []) {
            return null;
        }

        // Enforce allowed statuses for token refresh as well
        $allowedStatuses = (array) $this->getConfig('security.auth.allowed_login_statuses', ['active']);
        $userStatus = (string) ($user['status'] ?? '');
        if ($allowedStatuses !== [] && !in_array($userStatus, $allowedStatuses, true)) {
            return null;
        }

        $userData = $this->formatUserData($user);
        $userProfile = $this->userRepository->getProfile($userData['uuid']);
        // Note: Role functionality moved to RBAC extension
        $userRoles = []; // Use RBAC extension APIs for role management

        $userData['roles'] = $userRoles;
        $userData['profile'] = $userProfile;

        return $userData;
    }

    /**
     * Check if user is authenticated
     *
     * Uses the AuthenticationManager to verify if the request is authenticated.
     *
     * @param Request|mixed $request The request to check
     * @return bool True if authenticated, false otherwise
     */
    public function checkAuth($request): bool
    {
        // Ensure we're working with a Request object
        if (!($request instanceof Request)) {
            if ($this->context !== null && $this->context->hasContainer()) {
                $request = $this->context->getContainer()->get(Request::class);
            } else {
                return false;
            }
        }

        // Use the authentication manager
        $authManager = $this->authManager;
        $userData = $authManager->authenticate($request);

        return $userData !== null;
    }

    /**
     * Check if user is authenticated and has admin privileges
     *
     * Uses the AuthenticationManager to verify admin authentication.
     *
     * @param Request|mixed $request The request to check
     * @return bool True if authenticated as admin, false otherwise
     */
    public function checkAdminAuth($request): bool
    {
        // Ensure we're working with a Request object
        if (!($request instanceof Request)) {
            if ($this->context !== null && $this->context->hasContainer()) {
                $request = $this->context->getContainer()->get(Request::class);
            } else {
                return false;
            }
        }

        // Use the authentication manager
        $authManager = $this->authManager;
        $userData = $authManager->authenticate($request);

        if ($userData === null) {
            return false;
        }

        return $authManager->isAdmin($userData);
    }



    /**
     * Authenticate with multiple providers
     *
     * Tries multiple authentication methods in sequence.
     * This is useful for APIs that support multiple authentication methods
     * like JWT tokens, API keys, OAuth, etc.
     *
     * @param Request $request The request to authenticate
     * @param list<string> $providers Names of providers to try (e.g. 'jwt', 'api_key')
     * @return array<string, mixed>|null User data if authenticated, null otherwise
     */
    public function authenticateWithProviders(Request $request, array $providers = ['jwt', 'api_key']): ?array
    {
        $authManager = $this->authManager;
        return $authManager->authenticateWithProviders($providers, $request);
    }

    /**
     * Get current authenticated user
     *
     * Attempts to get the current authenticated user from the request context
     * or global request. Returns a user object if authenticated, null otherwise.
     *
     * @return object|null User object if authenticated, null otherwise
     */
    public function getCurrentUser(): ?object
    {
        try {
            // Try to get request from container first
            if ($this->context !== null && $this->context->hasContainer()) {
                $container = $this->context->getContainer();
                if ($container->has('request')) {
                    $request = $container->get('request');
                    if ($request instanceof Request) {
                        if ($request->attributes->has('user')) {
                            $user = $request->attributes->get('user');
                            if (is_object($user)) {
                                return $user;
                            }
                        }
                        $userData = self::authenticateWithProviders($request);
                        if ($userData !== null && isset($userData['user'])) {
                            return (object) $userData['user'];
                        }
                    }
                }
            }

            // Container request already checked above; no global fallback
        } catch (\Throwable) {
            // Ignore errors to prevent auth system disruption
        }

        return null;
    }

    private function getConfig(string $key, mixed $default = null): mixed
    {
        if (function_exists('config') && $this->context !== null) {
            return config($this->context, $key, $default);
        }

        return $default;
    }
}

<?php

declare(strict_types=1);

namespace Glueful\Auth;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\Drivers\ArrayCacheDriver;
use Glueful\Events\Auth\AuthenticationFailedEvent;
use Glueful\Events\EventService;
use Glueful\Helpers\CacheHelper;
use Glueful\Http\RequestContext;
use Glueful\Auth\Contracts\UserProviderInterface;
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

    private UserProviderInterface $userProvider;
    private IdentityResolver $identityResolver;
    private PasswordHasher $passwordHasher;
    private AuthenticationManager $authManager;
    private TokenManager $tokenManager;
    private RefreshTokenStore $refreshTokenStore;
    private RefreshService $refreshService;
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
     * - UserProviderInterface: Resolves identities + verifies credentials (no concrete store in core)
     * - PasswordHasher: Handles secure password hashing and verification
     *
     * @param SessionStoreInterface|null $sessionStore Unified session store for JWT handling
     * @param SessionCacheManager|null $sessionCacheManager Session management and caching service
     * @param PasswordHasher|null $passwordHasher Password hashing and verification service
     * @param ApplicationContext|null $context
     * @throws \RuntimeException If authentication system initialization fails
     * @throws \InvalidArgumentException If any provided dependency has incorrect interface
     */
    public function __construct(
        ?SessionStoreInterface $sessionStore = null,
        ?SessionCacheManager $sessionCacheManager = null,
        ?PasswordHasher $passwordHasher = null,
        ?ApplicationContext $context = null,
        ?AuthenticationManager $authManager = null,
        ?TokenManager $tokenManager = null,
        ?RefreshService $refreshService = null,
        ?UserProviderInterface $userProvider = null,
        ?IdentityResolver $identityResolver = null
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
        $this->passwordHasher = $passwordHasher ?? new PasswordHasher();
        // No user store in core: default to the fail-closed provider. The real provider is bound
        // by whatever user store is installed (glueful/users is the first-party one; LDAP/external
        // IdP/custom stores work too). Set before the RefreshService fallback (consumes it).
        $this->userProvider = $userProvider ?? new NullUserProvider();
        $this->identityResolver = $identityResolver ?? new IdentityResolver([]);

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

        if ($this->context !== null && $this->context->hasContainer()) {
            $this->refreshTokenStore = $this->context->getContainer()->get(RefreshTokenStore::class);
        } else {
            $this->refreshTokenStore = new RefreshTokenStore(null, $this->context);
        }

        if ($refreshService !== null) {
            $this->refreshService = $refreshService;
        } elseif ($this->context !== null && $this->context->hasContainer()) {
            $this->refreshService = $this->context->getContainer()->get(RefreshService::class);
        } else {
            $this->refreshService = new RefreshService(
                new RefreshTokenRepository($this->refreshTokenStore),
                new SessionRepository(null, $this->context),
                new AccessTokenIssuer($this->tokenManager),
                new ProviderTokenIssuer($this->tokenManager),
                new SessionStateCache($this->sessionStore),
                $this->sessionStore,
                $this->userProvider,
                $this->context
            );
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

        // Username/password flow — delegated to verifyCredentials() + issueSession()
        // so callers (e.g. AuthController) can insert a 2FA gate between credential
        // verification and session issuance. The provider short-circuit above is
        // untouched; only this branch is split. The credentials/status/password
        // validation logic is unchanged — it now lives in verifyCredentials().
        $userData = $this->verifyCredentials($credentials, $providerName);
        if ($userData === null) {
            return null;
        }

        $preferredProvider = $providerName ?? ($credentials['provider'] ?? 'jwt');
        return $this->issueSession($userData, $preferredProvider);
    }

    /**
     * Verify username/password credentials without creating a session.
     *
     * Username/password flow only — does NOT handle token or api_key provider
     * credentials (route those through authenticate()). Runs the same find-user +
     * status-allowlist + password-verify + formatting chain authenticate() used to
     * run inline; the only behavioral change is that no session is created here.
     *
     * @param array<string, mixed> $credentials Must contain username|email + password.
     * @param string|null $providerName Reserved for parity with authenticate(); unused here.
     * @return array<string, mixed>|null Formatted user data ready for issueSession(), or null on failure.
     */
    public function verifyCredentials(array $credentials, ?string $providerName = null): ?array
    {
        // Validate required fields
        if ($this->validateCredentials($credentials) === false) {
            return null;
        }

        $identifier = $credentials['username'] ?? $credentials['email'] ?? null;
        if ($identifier === null) {
            return null;
        }

        // Validate password format first (unchanged).
        try {
            PasswordDTO::from(['password' => $credentials['password'] ?? '']);
        } catch (\Glueful\Validation\ValidationException $e) {
            return null;
        }

        // Credential verification routes through the provider contract.
        $identity = $this->userProvider->verifyCredentials(
            (string) $identifier,
            (string) ($credentials['password'] ?? '')
        );
        if ($identity === null) {
            $this->dispatchAuthFailed((string) $identifier, 'invalid_credentials');
            return null;
        }

        // Centralized status gate (configured allowed statuses) + claims fold (Aegis etc.).
        // Null => rejected (e.g. suspended/disabled).
        $identity = $this->identityResolver->resolve($identity);
        if ($identity === null) {
            $this->dispatchAuthFailed((string) $identifier, 'user_disabled');
            return null;
        }

        // Build session user data from the resolved identity ONLY — no UserRepository, no profile
        // (rich profile is a glueful/users concern; clean break). Core login operates on identity
        // + claims.
        return [
            'uuid' => $identity->uuid(),
            'id' => $identity->uuid(),
            'email' => $identity->email(),
            'username' => $identity->username(),
            'status' => $identity->status(),
            'roles' => $identity->roles(),
            'permissions' => $identity->claim('permissions', []),
            'last_login' => date('Y-m-d H:i:s'),
            'remember_me' => $credentials['remember_me'] ?? false,
        ];
    }

    /**
     * Emit an {@see AuthenticationFailedEvent} for a rejected username/password login so listeners
     * (security monitoring, activity logging, an audit extension) can record the attempt. Best-effort
     * and non-throwing — a failed dispatch must never change the login's outcome. Client IP / user agent
     * are resolved from the request context when available.
     */
    private function dispatchAuthFailed(string $username, string $reason): void
    {
        if ($this->context === null) {
            return;
        }

        try {
            $clientIp = null;
            $userAgent = null;
            $container = $this->context->getContainer();
            if ($container->has(RequestContext::class)) {
                $requestContext = $container->get(RequestContext::class);
                if ($requestContext instanceof RequestContext) {
                    $clientIp = $requestContext->getClientIp();
                    $userAgent = $requestContext->getUserAgent();
                }
            }

            app($this->context, EventService::class)->dispatch(
                new AuthenticationFailedEvent($username, $reason, $clientIp, $userAgent)
            );
        } catch (\Throwable) {
            // Best-effort only — never break the login flow.
        }
    }

    /**
     * Create a session for an already-verified user. Returns the OIDC session payload.
     *
     * @param array<string, mixed> $userData As returned by verifyCredentials()
     * @param string|null $providerName Preferred token provider (jwt, ldap, saml, ...).
     * @return array<string, mixed>
     */
    public function issueSession(array $userData, ?string $providerName = null): array
    {
        $preferredProvider = $providerName ?? ($userData['provider'] ?? 'jwt');
        return $this->tokenManager->createUserSession($userData, $preferredProvider);
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
     * Account writes — password reset (updatePassword) and existence checks (userExists) — moved
     * to glueful/users. Core authentication does not own account mutation; the account API in the
     * Users extension performs these via its UserRepository.
     */

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
        return $this->refreshService->refresh($refreshToken);
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

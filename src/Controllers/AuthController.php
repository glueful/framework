<?php

declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Auth\DTOs\CsrfTokenData;
use Glueful\Auth\DTOs\LoginInputData;
use Glueful\Auth\DTOs\LoginResultData;
use Glueful\Auth\DTOs\RefreshedPermissionsData;
use Glueful\Auth\DTOs\ValidatedTokenData;
use Glueful\DTOs\RefreshTokenData;
use Glueful\DTOs\RefreshedTokenData;
use Glueful\Http\Response;
use Glueful\Helpers\RequestHelper;
use Glueful\Auth\AuthenticationService;
use Glueful\Http\Exceptions\Domain\AuthenticationException;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiRequestBody;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

/**
 * Authentication Controller
 *
 * Handles all authentication-related HTTP endpoints for the Glueful framework.
 * Provides secure user authentication, session management, and token operations
 * with support for multiple authentication providers.
 *
 * **Core Functionality:**
 * - User login/logout with multi-provider support
 * - Email verification and OTP management
 * - Password reset and recovery flows
 * - Token refresh and validation
 * - Permission management and session control
 *
 * **Security Features:**
 * - CSRF protection integration
 * - Rate limiting and brute force protection
 * - Secure token generation and validation
 * - Multi-provider authentication support
 * - Session analytics and audit logging
 *
 * **Supported Authentication Providers:**
 * - JWT (default)
 * - LDAP directory services
 * - SAML identity providers
 * - OAuth2/OpenID Connect
 * - API key authentication
 */
class AuthController
{
    private AuthenticationService $authService;
    private ApplicationContext $context;
    /** Optional: a 2FA impl (e.g. glueful/users); null when no 2FA service is registered. */
    private ?\Glueful\Auth\Contracts\TwoFactorServiceInterface $twoFactor = null;
    private \Glueful\Auth\LoginResponseShaper $loginResponseShaper;
    private \Glueful\Auth\Session\LoginOrchestrator $loginOrchestrator;

    public function __construct(ApplicationContext $context)
    {
        $this->context = $context;
        try {
            $this->authService = container($this->context)->get(AuthenticationService::class);
        } catch (\Throwable) {
            // Fallback to direct construction with context for proper DI resolution
            $this->authService = new AuthenticationService(context: $this->context);
        }

        // 2FA is provided by an extension (e.g. glueful/users) behind the core contract — resolve
        // it optionally (no hard core dependency on any impl). Login enforces 2FA only when a
        // service is registered against the interface.
        $twoFactorClass = \Glueful\Auth\Contracts\TwoFactorServiceInterface::class;
        $c = container($this->context);
        $this->twoFactor = $c->has($twoFactorClass) ? $c->get($twoFactorClass) : null;
        $this->loginResponseShaper = container($this->context)->get(\Glueful\Auth\LoginResponseShaper::class);

        // Password login runs through the shared orchestrator so every transport passes the
        // same 2FA gate. Falls back to direct construction when the container has no
        // definition (parity with the AuthenticationService resolution above).
        $orchestratorClass = \Glueful\Auth\Session\LoginOrchestrator::class;
        $this->loginOrchestrator = $c->has($orchestratorClass)
            ? $c->get($orchestratorClass)
            : new \Glueful\Auth\Session\LoginOrchestrator($this->authService, $this->twoFactor);

        // Initialize the authentication system
        app($this->context, \Glueful\Auth\AuthBootstrap::class)->initialize();
    }

    /**
     * Authenticate user with credentials and establish session
     *
     * Performs user authentication using provided credentials and returns
     * JWT access tokens with session information. Supports multiple authentication
     * providers and implements comprehensive security measures.
     *
     * **Authentication Process:**
     * 1. Extract credentials and client information from request
     * 2. Determine authentication provider (JWT, LDAP, SAML, OAuth2)
     * 3. Validate credentials using appropriate provider
     * 4. Generate access and refresh tokens
     * 5. Create user session with analytics tracking
     * 6. Return OIDC-compliant authentication response
     *
     * **Security Features:**
     * - CSRF token generation for session protection
     * - Client IP and User-Agent tracking
     * - Remember-me functionality with extended sessions
     * - Provider-specific authentication flows
     * - Session analytics and audit logging
     *
     * **Request Format:**
     * ```json
     * {
     *   "username": "user@example.com",
     *   "password": "secure_password",
     *   "remember": true,
     *   "provider": "ldap"
     * }
     * ```
     *
     * **Response Format:**
     * ```json
     * {
     *   "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
     *   "refresh_token": "abc123...",
     *   "token_type": "Bearer",
     *   "expires_in": 3600,
     *   "user": {...},
     *   "csrf_token": {...}
     * }
     * ```
     *
     * @return mixed HTTP response with authentication tokens and user data
     * @throws \Glueful\Http\Exceptions\Domain\AuthenticationException If credentials are invalid
     * @throws \Glueful\Validation\ValidationException If request data is malformed
     * @throws \RuntimeException If authentication system initialization fails
     */
    #[ApiOperation(
        summary: 'User Login',
        description: 'Authenticates a user with username/email and password',
        tags: ['Authentication'],
    )]
    #[ApiRequestBody(schema: LoginInputData::class)]
    #[ApiResponse(200, LoginResultData::class, description: 'Login successful')]
    #[ApiResponse(401, description: 'Invalid credentials')]
    #[ApiResponse(400, description: 'Missing required fields')]
    public function login(SymfonyRequest $request)
    {
        // Get credentials using the getPostData method from our Helper Request class
        $credentials = RequestHelper::getRequestData($request);

        // Check if a specific provider was requested
        $providerName = null;
        if (isset($credentials['provider'])) {
            $providerName = $credentials['provider'];
        }

        // Route 1 — token / API-key provider login, UNCHANGED. These providers return an
        // identity array with NO tokens, so the result is shaped directly and never modelled
        // as a session. Deliberately outside the orchestrator for that reason; it also has no
        // "verified user, no session yet" state for a second factor to gate.
        if (isset($credentials['token']) || isset($credentials['api_key'])) {
            $result = $this->authService->authenticate($credentials, $providerName);
            if ($result === null) {
                throw new AuthenticationException('Invalid credentials');
            }
            return $this->loginResponseShaper->shape($request, $result);
        }

        // Route 2 — username/password login, through the shared orchestrator and its 2FA gate.
        $outcome = $this->loginOrchestrator->login($credentials, $providerName);

        if (!$outcome->isAuthenticated()) {
            $challenge = $outcome->challenge();

            // Challenge responses deliberately skip CSRF + login events — login is
            // not yet complete and there is no session to bind a CSRF token to.
            return Response::success([
                'two_factor_required' => true,
                'challenge_token'     => $challenge->token,
                'expires_in'          => $challenge->expiresIn,
                'delivered_to'        => $challenge->deliveredTo,
            ], 'Two-factor verification required');
        }

        return $this->loginResponseShaper->shape($request, $outcome->session()->toSessionArray());
    }

    /**
     * Terminate user session and invalidate authentication tokens
     *
     * Securely logs out the user by invalidating their access token and
     * terminating their active session. Clears session data from cache
     * and database for complete logout.
     *
     * **Logout Process:**
     * 1. Extract access token from Authorization header
     * 2. Validate token format and presence
     * 3. Invalidate token in session cache
     * 4. Remove session from database
     * 5. Clear any associated refresh tokens
     * 6. Log logout event for security audit
     *
     * **Security Features:**
     * - Complete token invalidation
     * - Session cleanup across all storage layers
     * - Audit logging for security monitoring
     * - Prevention of token reuse after logout
     *
     * @return mixed HTTP response confirming successful logout
     * @throws \Glueful\Validation\ValidationException If no token provided in request
     * @throws \Glueful\Http\Exceptions\Domain\AuthenticationException If logout operation fails
     */
    #[ApiOperation(
        summary: 'User Logout',
        description: 'Invalidates the current authentication token',
        tags: ['Authentication'],
    )]
    #[ApiResponse(200, description: 'Logout successful')]
    #[ApiResponse(401, description: 'Unauthorized - not logged in')]
    public function logout(SymfonyRequest $request)
    {
        $token = $this->authService->extractTokenFromRequest($request, $this->context);

        if ($token === null) {
            throw ValidationException::forField('token', 'No token provided');
        }


        $success = $this->authService->terminateSession($token);

        if ($success) {
            return Response::success(null, 'Logged out successfully');
        }

        throw new AuthenticationException('Logout failed');
    }

    /**
     * Get CSRF token for form/AJAX protection
     *
     * Generates a CSRF token that should be included in subsequent requests
     * to protect against cross-site request forgery attacks.
     *
     * @param SymfonyRequest $request The HTTP request
     * @return mixed HTTP response with CSRF token data
     */
    #[ApiOperation(
        summary: 'Get CSRF Token',
        description: 'Retrieves a CSRF token for form and AJAX request protection',
        tags: ['Security'],
    )]
    #[ApiResponse(200, CsrfTokenData::class, description: 'CSRF token retrieved successfully')]
    #[ApiResponse(500, description: 'Failed to generate CSRF token')]
    public function csrfToken(SymfonyRequest $request)
    {
        try {
            $tokenData = \Glueful\Helpers\Utils::csrfTokenData($request);
            return Response::success($tokenData, 'CSRF token retrieved successfully');
        } catch (\Exception $e) {
            return Response::error('Failed to generate CSRF token: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Refresh user permissions
     *
     * Updates the session with fresh user permissions and returns a new token.
     * This endpoint is useful after role/permission changes for a user.
     *
     * @return mixed HTTP response
     */
    #[ApiOperation(
        summary: 'Refresh User Permissions',
        description: 'Updates the session with fresh user permissions and returns a new token',
        tags: ['Authentication'],
    )]
    #[ApiResponse(200, RefreshedPermissionsData::class, description: 'Permissions refreshed successfully')]
    #[ApiResponse(401, description: 'Unauthorized - invalid token')]
    #[ApiResponse(400, description: 'Missing or invalid token')]
    public function refreshPermissions(SymfonyRequest $request)
    {
        $token = $this->authService->extractTokenFromRequest($request, $this->context);

        if ($token === null) {
            throw ValidationException::forField('token', 'No token provided');
        }

        // Get session to extract user UUID via SessionStore
        try {
            /** @var \Glueful\Auth\Interfaces\SessionStoreInterface $store */
            $store = container($this->context)->get(\Glueful\Auth\Interfaces\SessionStoreInterface::class);
            $session = $store->getByAccessToken($token);
        } catch (\Throwable) {
            $session = null;
        }
        if ($session === null || !isset($session['user_uuid'])) {
            throw new AuthenticationException('Invalid session');
        }

        // Refresh permissions in the session
        $result = $this->authService->refreshPermissions($token);

        if ($result === null) {
            throw new AuthenticationException('Failed to refresh permissions');
        }

        return Response::success($result, 'Permissions refreshed successfully');
    }

    /**
     * Validate if a token is valid and active
     *
     * Uses the authentication abstraction to verify token validity.
     *
     * @return mixed HTTP response
     */
    #[ApiOperation(
        summary: 'Validate Token',
        description: 'Validates the current authentication token',
        tags: ['Authentication'],
    )]
    #[ApiResponse(200, ValidatedTokenData::class, description: 'Token is valid')]
    #[ApiResponse(401, description: 'Invalid or expired token')]
    public function validateToken(SymfonyRequest $request)
    {
        // Get token from request
        $token = $this->authService->extractTokenFromRequest($request, $this->context);

        if ($token === null) {
            throw ValidationException::forField('token', 'No token provided');
        }

        // Use our new authentication system to validate the token
        $authManager = app($this->context, \Glueful\Auth\AuthenticationManager::class);
        $userData = $authManager->authenticate($request);

        if ($userData === null) {
            throw new AuthenticationException('Invalid token');
        }

        return Response::success([
            'user' => $userData,
            'is_valid' => true
        ], 'Token is valid');
    }

    /**
     * Generate new access token using refresh token
     *
     * Exchanges a valid refresh token for a new access token, maintaining
     * user session continuity without requiring re-authentication.
     *
     * **Token Refresh Process:**
     * 1. Extract refresh token from request
     * 2. Validate refresh token and associated session
     * 3. Generate new access and refresh token pair
     * 4. Update session with new tokens
     * 5. Invalidate old tokens for security
     * 6. Return new token pair with user data
     *
     * **Security Features:**
     * - Refresh token rotation for enhanced security
     * - Session validation and consistency checks
     * - Request context update for seamless operation
     * - Audit logging for token operations
     *
     * **Request Format:**
     * ```json
     * {
     *   "refresh_token": "abc123..."
     * }
     * ```
     *
     * **Response Format:**
     * ```json
     * {
     *   "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
     *   "refresh_token": "def456...",
     *   "token_type": "Bearer",
     *   "expires_in": 3600,
     *   "user": {...}
     * }
     * ```
     *
     * **Reference adoption:** the request body is bound + validated into a typed
     * {@see RefreshTokenData} (the router hydrates it; a missing/blank
     * `refresh_token` yields a {@see ValidationException} → 422, matching the
     * previous manual guard) and the success payload is returned as a typed
     * {@see RefreshedTokenData} the router envelopes (it supplies its own
     * 'Token refreshed successfully' message via HasResponseMessage). Behaviour
     * is preserved byte-for-byte.
     *
     * @return RefreshedTokenData Enveloped response with new authentication tokens
     * @throws \Glueful\Validation\ValidationException If refresh token missing from request
     * @throws \Glueful\Http\Exceptions\Domain\AuthenticationException If refresh token invalid or expired
     */
    #[ApiOperation(
        summary: 'Refresh Token',
        description: 'Generates new access token using a valid refresh token',
        tags: ['Authentication'],
    )]
    #[ApiResponse(401, description: 'Invalid refresh token')]
    #[ApiResponse(400, description: 'Missing refresh token')]
    public function refreshToken(RefreshTokenData $input): RefreshedTokenData
    {
        $result = $this->authService->refreshTokens($input->refresh_token);

        if ($result === null) {
            throw new AuthenticationException('Invalid or expired refresh token');
        }

        // Update RequestUserContext with the new token to maintain consistency
        // within the current request
        $requestContext = \Glueful\Http\RequestUserContext::getInstance();
        if ($requestContext->isAuthenticated()) {
            $requestContext->updateToken($result['access_token']);
        }

        return new RefreshedTokenData(
            access_token: (string) $result['access_token'],
            refresh_token: (string) $result['refresh_token'],
            expires_in: (int) $result['expires_in'],
            token_type: (string) $result['token_type'],
            user: (array) $result['user'],
        );
    }
}

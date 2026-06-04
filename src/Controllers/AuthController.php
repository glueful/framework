<?php

declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Response;
use Glueful\Helpers\RequestHelper;
use Glueful\Auth\AuthenticationService;
use Glueful\Http\Exceptions\Domain\AuthenticationException;
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
    private \Glueful\Auth\TwoFactor\TwoFactorService $twoFactor;
    private \Glueful\Auth\LoginResponseShaper $loginResponseShaper;

    public function __construct(ApplicationContext $context)
    {
        $this->context = $context;
        try {
            $this->authService = container($this->context)->get(AuthenticationService::class);
        } catch (\Throwable) {
            // Fallback to direct construction with context for proper DI resolution
            $this->authService = new AuthenticationService(context: $this->context);
        }

        // 2FA dependencies — always registered in CoreProvider. Resolved internally
        // (not via constructor params) to keep the controller's instantiation contract.
        $this->twoFactor = container($this->context)->get(\Glueful\Auth\TwoFactor\TwoFactorService::class);
        $this->loginResponseShaper = container($this->context)->get(\Glueful\Auth\LoginResponseShaper::class);

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
    public function login(SymfonyRequest $request)
    {
        // Get credentials using the getPostData method from our Helper Request class
        $credentials = RequestHelper::getRequestData($request);

        // Extract remember me preference from credentials
        $rememberMe = isset($credentials['remember']) && (bool)$credentials['remember'];

        // Add remember_me to credentials for authentication service
        $credentials['remember_me'] = $rememberMe;

        // Check if a specific provider was requested
        $providerName = null;
        if (isset($credentials['provider'])) {
            $providerName = $credentials['provider'];
        }
        $preferredProvider = $providerName ?? ($credentials['provider'] ?? 'jwt');

        // Route 1 — token / API-key provider login. Bypasses the 2FA gate entirely
        // (these credentials have no "verified user, no session yet" intermediate
        // state). Delegates to the unchanged AuthenticationService::authenticate()
        // provider short-circuit, then shapes the response like every other login.
        if (isset($credentials['token']) || isset($credentials['api_key'])) {
            $result = $this->authService->authenticate($credentials, $providerName);
            if ($result === null) {
                throw new AuthenticationException('Invalid credentials');
            }
            return $this->loginResponseShaper->shape($request, $result);
        }

        // Route 2 — username/password login. Goes through the split + 2FA gate.

        // Step 1: credentials & status validation only. NO session is created here.
        $userData = $this->authService->verifyCredentials($credentials, $providerName);
        if ($userData === null) {
            throw new AuthenticationException('Invalid credentials');
        }

        // Step 2: 2FA branch. isEnabled() short-circuits (no DB read) when the
        // master switch is off, so this is a no-op for installs without 2FA.
        if ($this->twoFactor->isEnabled((string) $userData['uuid'])) {
            $challenge = $this->twoFactor->beginLogin(
                [
                    'uuid'              => (string) $userData['uuid'],
                    'email'             => (string) ($userData['email'] ?? ''),
                    'email_verified_at' => $userData['email_verified_at'] ?? null,
                    'username'          => $userData['username'] ?? null,
                    'profile'           => $userData['profile'] ?? null,
                    'remember_me'       => $rememberMe,
                    'status'            => $userData['status'] ?? null,
                ],
                $preferredProvider
            );

            // Challenge responses deliberately skip CSRF + login events — login is
            // not yet complete and there is no session to bind a CSRF token to.
            return Response::success([
                'two_factor_required' => true,
                'challenge_token'     => $challenge['token'],
                'expires_in'          => $challenge['expires_in'],
                'delivered_to'        => $challenge['delivered_to'],
            ], 'Two-factor verification required');
        }

        // Step 3: no 2FA. Issue the session and shape the response (CSRF + events).
        $session = $this->authService->issueSession($userData, $preferredProvider);
        return $this->loginResponseShaper->shape($request, $session);
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
     * @return mixed HTTP response with new authentication tokens
     * @throws \Glueful\Validation\ValidationException If refresh token missing from request
     * @throws \Glueful\Http\Exceptions\Domain\AuthenticationException If refresh token invalid or expired
     */
    public function refreshToken(SymfonyRequest $request)
    {
        $postData = RequestHelper::getRequestData($request);

        if (!isset($postData['refresh_token'])) {
            throw ValidationException::forField('refresh_token', 'Refresh token is required');
        }

        $refreshToken = $postData['refresh_token'];
        $result = $this->authService->refreshTokens($refreshToken);

        if ($result === null) {
            throw new AuthenticationException('Invalid or expired refresh token');
        }

        // Update RequestUserContext with the new token to maintain consistency
        // within the current request
        $requestContext = \Glueful\Http\RequestUserContext::getInstance();
        if ($requestContext->isAuthenticated()) {
            $requestContext->updateToken($result['access_token']);
        }

        return Response::success($result, 'Token refreshed successfully');
    }
}

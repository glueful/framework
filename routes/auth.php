<?php

/**
 * Auth routes - loaded via RouteManifest::requireRouteFile()
 *
 * @var \Glueful\Routing\Router $router
 */

use Glueful\Routing\Router;
use Glueful\Controllers\AuthController;

// Auth routes
$router->group(['prefix' => '/auth'], function (Router $router) {
    /**
     * @route POST /auth/login
     * @summary User Login
     * @description Authenticates a user with username/email and password
     * @tag Authentication
     * @requestBody username:string="Username or email address" password:string="User password"
     * {required=username,password}
     * @response 200 application/json "Login successful" {
     *   success:boolean="true",
     *   message:string="Success message",
     *   data:{
     *     access_token:string="JWT access token",
     *     token_type:string="Bearer",
     *     expires_in:integer="Token expiration in seconds",
     *     refresh_token:string="JWT refresh token",
     *     user:{
     *       id:string="User unique identifier",
     *       email:string="Email address",
     *       email_verified:boolean="Email verification status",
     *       username:string="Username",
     *       name:string="Full name",
     *       given_name:string="First name",
     *       family_name:string="Last name",
     *       picture:string="Profile image URL",
     *       locale:string="User locale (e.g., en-US)",
     *       updated_at:integer="Last update timestamp (Unix epoch)"
     *     }
     *   },
     * }
     * @response 401 "Invalid credentials"
     * @response 400 "Missing required fields"
     */
    $router->post('/login', [AuthController::class, 'login'])
        ->middleware('rate_limit:5,60'); // 5 attempts per minute

    // Account-lifecycle routes (verify-email, verify-otp, resend-otp, forgot-password,
    // reset-password) moved to the glueful/users extension (AccountController).

    /**
     * @route POST /auth/validate-token
     * @summary Validate Token
     * @description Validates the current authentication token
     * @tag Authentication
     * @requiresAuth true
     * @response 200 application/json "Token is valid" {
     *   success:boolean="true",
     *   message:string="Success message",
     *   data:{
     *     access_token:string="JWT access token",
     *     refresh_token:string="JWT refresh token"
     *   },
     * }
     * @response 401 "Invalid or expired token"
     */
    $router->post('/validate-token', [AuthController::class, 'validateToken'])
        ->middleware(['auth']);

    /**
     * @route POST /auth/refresh-token
     * @summary Refresh Token
     * @description Generates new access token using a valid refresh token
     * @tag Authentication
     * @requestBody refresh_token:string="JWT refresh token" {required=refresh_token}
     * @response 200 application/json "Token refreshed successfully" {
     *   success:boolean="true",
     *   message:string="Success message",
     *   data:{
     *     access_token:string="New JWT access token",
     *     token_type:string="Bearer",
     *     expires_in:integer="Token expiration in seconds",
     *     refresh_token:string="New JWT refresh token"
     *   },
     * }
     * @response 401 "Invalid refresh token"
     * @response 400 "Missing refresh token"
     */
    $router->post('/refresh-token', [AuthController::class, 'refreshToken']);

    /**
     * @route POST /auth/logout
     * @summary User Logout
     * @description Invalidates the current authentication token
     * @tag Authentication
     * @requiresAuth true
     * @response 200 application/json "Logout successful" {
     *   success:boolean="true",
     *   message:string="Success message",
     * }
     * @response 401 "Unauthorized - not logged in"
     */
    $router->post('/logout', [AuthController::class, 'logout'])
        ->middleware(['auth']);

    /**
     * @route POST /auth/refresh-permissions
     * @summary Refresh User Permissions
     * @description Updates the session with fresh user permissions and returns a new token
     * @tag Authentication
     * @requiresAuth true
     * @response 200 application/json "Permissions refreshed successfully" {
     *   success:boolean="true",
     *   message:string="Success message",
     *   data:{
     *     access_token:string="Updated JWT access token",
     *     refresh_token:string="Updated JWT refresh token",
     *     permissions:array="Updated user permissions",
     *     updated_at:string="Timestamp of permission update"
     *   },
     * }
     * @response 401 "Unauthorized - invalid token"
     * @response 400 "Missing or invalid token"
     */
    $router->post('/refresh-permissions', [AuthController::class, 'refreshPermissions'])
        ->middleware(['auth']);
});

/**
 * @route GET /csrf-token
 * @summary Get CSRF Token
 * @description Retrieves a CSRF token for form and AJAX request protection
 * @tag Security
 * @response 200 application/json "CSRF token retrieved successfully" {
 *   success:boolean="true",
 *   message:string="Success message",
 *   data:{
 *     token:string="CSRF token value",
 *     header:string="Header name for CSRF token (X-CSRF-Token)",
 *     field:string="Form field name for CSRF token (_token)",
 *     expires_at:integer="Token expiration timestamp"
 *   },
 *   code:integer="HTTP status code"
 * }
 * @response 500 "Failed to generate CSRF token"
 */
$router->get('/csrf-token', [AuthController::class, 'csrfToken']);

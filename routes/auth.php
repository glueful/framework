<?php

use Glueful\Routing\Router;
use Glueful\Controllers\AuthController;
use Glueful\Http\Response;
use Glueful\Helpers\Utils;
use Symfony\Component\HttpFoundation\Request;

/** @var Router $router Router instance injected by RouteManifest::load() */

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
    $router->post('/login', function (Request $request) {
        $authController = container()->get(AuthController::class);
        return $authController->login();
    })->middleware('rate_limit:5,60'); // 5 attempts per minute

    /**
     * @route POST /auth/verify-email
     * @summary Verify Email
     * @description Sends a verification code to the provided email
     * @tag Authentication
     * @requestBody email:string="Email address to verify" {required=email}
     * @response 200 application/json "Verification code has been sent to your email" {
     *   success:boolean="true",
     *   message:string="Success message",
     *   data:{
     *     email:string="Email address",
     *     expires_in:integer="OTP expiration time in seconds"
     *   },
     * }
     * @response 400 "Invalid email address"
     * @response 404 "Email not found"
     */
    $router->post('/verify-email', function () {
        $authController = container()->get(AuthController::class);
        return $authController->verifyEmail();
    });

    /**
     * @route POST /auth/verify-otp
     * @summary Verify OTP
     * @description Verifies the one-time password (OTP) sent to a user's email
     * @tag Authentication
     * @requestBody email:string="Email address" otp:string="One-time password code" {required=email,otp}
     * @response 200 application/json "OTP verified successfully" {
     *   success:boolean="true",
     *   message:string="Success message",
     *   data:{
     *     email:string="Email address",
     *     verified:boolean="true",
     *     verified_at:string="Verification timestamp"
     *   },
     * }
     * @response 400 "Invalid OTP"
     * @response 401 "OTP expired"
     */
    $router->post('/verify-otp', function () {
        $authController = container()->get(AuthController::class);
        return $authController->verifyOtp();
    })->middleware('rate_limit:3,60'); // 3 attempts per minute

    /**
     * @route POST /auth/resend-otp
     * @summary Resend OTP
     * @description Resends the one-time password (OTP) to the user's email
     * @tag Authentication
     * @requestBody email:string="Email address to resend OTP to" {required=email}
     * @response 200 application/json "OTP resent successfully" {
     *   success:boolean="true",
     *   message:string="Success message",
     *   data:{
     *     email:string="Email address",
     *     expires_in:integer="OTP expiration time in seconds"
     *   },
     * }
     * @response 400 "Invalid email address"
     * @response 404 "Email not found"
     */
    $router->post('/resend-otp', function () {
        $authController = container()->get(AuthController::class);
        return $authController->resendOtp();
    })->middleware('rate_limit:2,120'); // 2 attempts per 2 minutes (stricter for resend)

    /**
     * @route POST /auth/forgot-password
     * @summary Forgot Password
     * @description Initiates the password reset process by sending a reset code
     * @tag Authentication
     * @requestBody email:string="Email address associated with account" {required=email}
     * @response 200 application/json "Password reset instructions sent to email" {
     *   success:boolean="true",
     *   message:string="Success message",
     *   data:{
     *     email:string="Email address",
     *     expires_in:integer="Reset code expiration time in seconds"
     *   },
     * }
     * @response 404 "Email not found"
     * @response 400 "Invalid email format"
     */
    $router->post('/forgot-password', function () {
        $authController = container()->get(AuthController::class);
        return $authController->forgotPassword();
    });

    /**
     * @route POST /auth/reset-password
     * @summary Reset Password
     * @description Resets the user's password using the verification code
     * @tag Authentication
     * @requestBody email:string="Email address" password:string="New password" {required=email,password}
     * @response 200 application/json "Password has been reset successfully" {
     *   success:boolean="true",
     *   message:string="Success message",
     *   data:{
     *     email:string="Email address",
     *     updated_at:string="Password reset timestamp"
     *   },
     * }
     * @response 400 "Invalid password format"
     * @response 404 "Email not found"
     */
    $router->post('/reset-password', function () {
        $authController = container()->get(AuthController::class);
        return $authController->resetPassword();
    });

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
    $router->post('/validate-token', function () {
        $authController = container()->get(AuthController::class);
        return $authController->validateToken();
    })->middleware(['auth']);

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
    $router->post('/refresh-token', function () {
        $authController = container()->get(AuthController::class);
        return $authController->refreshToken();
    });

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
    $router->post('/logout', function () {
        $authController = container()->get(AuthController::class);
        return $authController->logout();
    })->middleware(['auth']);

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
    $router->post('/refresh-permissions', function () {
        $authController = container()->get(AuthController::class);
        return $authController->refreshPermissions();
    })->middleware(['auth']);
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
$router->get('/csrf-token', function (Request $request) {
    try {
        $tokenData = Utils::csrfTokenData($request);
        return Response::success($tokenData, 'CSRF token retrieved successfully');
    } catch (\Exception $e) {
        return Response::error('Failed to generate CSRF token: ' . $e->getMessage(), 500);
    }
});

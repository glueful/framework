<?php

/**
 * Core email-PIN 2FA routes - loaded via RouteManifest::requireRouteFile()
 *
 * Registered only when TWO_FACTOR_ENABLED=true. When the master switch is off
 * (default) this file early-returns, so /2fa/* routes do not exist (404) and the
 * framework behaves exactly as it did before 2FA shipped.
 *
 * @var \Glueful\Routing\Router $router
 */

use Glueful\Controllers\TwoFactorController;

// Master switch — env() casts 'true'/'false' to real booleans.
if (env('TWO_FACTOR_ENABLED', false) !== true) {
    return;
}

/**
 * @route POST /2fa/enable
 * @summary Enable Two-Factor Authentication
 * @description Begins 2FA enrollment for the authenticated user: emails a 6-digit
 *   PIN and returns a short-lived challenge_token. Submit both to POST /2fa/verify
 *   to complete enrollment.
 * @tag Authentication
 * @requiresAuth true
 * @response 200 application/json "Two-factor code sent" {
 *   success:boolean="true",
 *   message:string="Success message",
 *   data:{
 *     challenge_token:string="Short-lived token to submit with the PIN",
 *     expires_in:integer="Seconds until the challenge_token expires",
 *     delivered_to:string="Masked email the PIN was sent to"
 *   },
 * }
 * @response 401 "Authentication required"
 * @response 429 "Too many requests"
 */
$router->post('/2fa/enable', [TwoFactorController::class, 'enable'])
    ->rateLimit(3, 1)                      // 3 attempts / minute (builder form — actually enforced)
    ->middleware(['auth', 'rate_limit'])
    ->name('2fa.enable');

/**
 * @route POST /2fa/verify
 * @summary Verify Two-Factor Code
 * @description Verifies the emailed PIN against a challenge_token. No auth header is
 *   required — the challenge_token authenticates the request. For a login challenge it
 *   completes login and returns the full session below — byte-for-byte identical to a
 *   direct POST /auth/login response (both flow through the same LoginResponseShaper).
 *   For an enrollment challenge it instead returns just {success, message} with an empty
 *   data payload (no tokens).
 * @tag Authentication
 * @requestBody challenge_token:string="Token returned by /auth/login or /2fa/enable" code:string="6-digit PIN from the email" {required=challenge_token,code}
 * @response 200 application/json "Verification successful" {
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
 * @response 401 "Invalid or expired verification"
 * @response 429 "Too many requests"
 */
$router->post('/2fa/verify', [TwoFactorController::class, 'verify'])
    ->rateLimit(5, 1)                      // 5 attempts / minute
    ->middleware('rate_limit')
    ->name('2fa.verify');

/**
 * @route POST /2fa/disable
 * @summary Disable Two-Factor Authentication
 * @description Disables 2FA for the authenticated user. Requires a recent 2FA
 *   verification on the current session (within the configured freshness window);
 *   otherwise re-elevation is required.
 * @tag Authentication
 * @requiresAuth true
 * @response 200 application/json "Two-factor authentication disabled" {
 *   success:boolean="true",
 *   message:string="Success message",
 *   data:array="Empty payload"
 * }
 * @response 401 "Authentication required"
 * @response 403 "Recent two-factor verification is required to perform this action"
 * @response 429 "Too many requests"
 */
$router->post('/2fa/disable', [TwoFactorController::class, 'disable'])
    ->rateLimit(3, 1)                      // 3 attempts / minute
    ->middleware(['auth', 'rate_limit'])
    ->name('2fa.disable');

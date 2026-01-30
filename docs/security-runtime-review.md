# Security & Runtime Correctness Review (Glueful Framework)

Date: 2026-01-29
Scope: Security-focused deep dive + API/runtime correctness (spot review)

## Findings (Resolved)

- High: Refresh token expiry is enforced when refreshing tokens via `refresh_expires_at` checks in token lookup and update flows. `src/Auth/TokenManager.php`, `src/Auth/SessionStore.php`.
- High: Session cache invalidation now uses the same key prefix as cache insertion. `src/Auth/SessionStore.php`.
- Medium: Password reset flows return generic responses when `security.auth.generic_error_responses` is enabled to prevent account enumeration. `src/Controllers/AuthController.php`, `config/security.php`.
- Medium: Query-string token support is gated behind `security.tokens.allow_query_param` (default false) with non-prod warnings. `src/Auth/TokenManager.php`, `src/Auth/AuthenticationService.php`, `config/security.php`.
- Medium: Email-only login now resolves username/email consistently during authentication. `src/Auth/AuthenticationService.php`.
- Medium: Auth flows now use the current request object instead of `Request::createFromGlobals()`. `src/Controllers/AuthController.php`, `routes/auth.php`, `src/Auth/AuthenticationService.php`.
- Medium: CORS config is centralized in `config/cors.php` so Router reads a single source of truth. `config/cors.php`, `src/Routing/Router.php`.
- Medium: CSRF Origin/Referer behavior is configurable for non-browser clients and bearer auth. `src/Routing/Middleware/CSRFMiddleware.php`, `config/security.php`.
- Medium: SSRF IPv6 filtering now uses CIDR checks and blocks additional special ranges (with zone-id handling). `src/Api/Webhooks/Jobs/DeliverWebhookJob.php`.
- Low: CSP defaults no longer include `'unsafe-inline'`; it is opt-in via `CSP_SCRIPT_UNSAFE_INLINE` / `CSP_STYLE_UNSAFE_INLINE`. `src/Routing/Middleware/SecurityHeadersMiddleware.php`.

## Minor observations

- SSRF IPv6: `ipInCidr()` bitmask handling looks correct with `intdiv()` and the `$remainingBits > 0` check.
- CSP duplication: resolved by extracting unsafe-inline opt-in handling to a helper (kept DRY).
- Config structure: new keys are organized under existing sections (`security.auth`, `security.tokens`, `security.csrf`).

## Still outstanding (from original findings)

- None. All original findings have been addressed in code.

## Open questions / assumptions

- None. All listed items have been addressed in code and wired to configuration where appropriate.

## Testing gaps

- Refresh token expiry enforcement (expired refresh should fail even if status is active).
- Session cache invalidation on refresh/revoke (verify cache keys).
- Email-only login payloads.
- Query-param token disabled/optional path.
- CSRF with missing Origin/Referer under both bearer and non-bearer requests.
- Webhook SSRF IPv6 CIDR checks (blocked ranges + zone-id).

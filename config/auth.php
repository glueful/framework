<?php

/**
 * Authentication Configuration
 *
 * Core email-PIN two-factor authentication (2FA). The feature is opt-in:
 * `enabled` defaults to false, so a fresh install behaves exactly like a
 * pre-2FA framework until TWO_FACTOR_ENABLED=true and the migration is run.
 */

return [
    'api_keys' => [
        // Brand segment of generated API keys: <prefix>_live_<random> in production,
        // <prefix>_test_<random> elsewhere. Defaults to 'gf' (Glueful); set API_KEY_PREFIX to
        // rebrand (e.g. 'lm' → lm_live_… / lm_test_…). Keep it short — only the first 16 chars of a
        // key are stored as the indexed lookup prefix.
        'prefix' => env('API_KEY_PREFIX', 'gf'),
    ],

    'two_factor' => [
        // Master switch. When false, TwoFactorService::isEnabled() short-circuits
        // before any DB read and the /2fa/* routes are not registered.
        'enabled' => env('TWO_FACTOR_ENABLED', false),

        // Number of digits in the emailed PIN.
        'pin_length' => (int) env('TWO_FACTOR_PIN_LENGTH', 6),

        // How long an emailed PIN remains valid (seconds).
        'pin_ttl' => (int) env('TWO_FACTOR_PIN_TTL', 300),

        // How long a challenge_token remains valid (seconds).
        'challenge_ttl' => (int) env('TWO_FACTOR_CHALLENGE_TTL', 300),

        // Notification template name (rendered by glueful/email-notification).
        'template_name' => env('TWO_FACTOR_TEMPLATE', 'two-factor-pin'),

        // How long after a 2FA login a session may call /2fa/disable without
        // re-verifying (seconds). Session-scoped marker, not user-scoped.
        'disable_freshness' => (int) env('TWO_FACTOR_DISABLE_FRESHNESS', 300),
    ],

    /**
     * Opt-in browser-session transport. Off by default: a fresh install behaves exactly like
     * a pre-cookie framework until SESSION_COOKIE_ENABLED=true, and while disabled the session
     * routes are not registered at all. Bearer authentication is unaffected either way.
     */
    'session_cookie' => [
        'enabled' => env('SESSION_COOKIE_ENABLED', false),

        // Host-configurable so several Glueful apps under one parent domain do not collide.
        'access_name' => env('SESSION_COOKIE_ACCESS_NAME', 'gf_session'),
        'refresh_name' => env('SESSION_COOKIE_REFRESH_NAME', 'gf_refresh'),

        // How long the refresh cookie itself survives (seconds). The access cookie follows
        // the issued session's own expires_in.
        'refresh_ttl' => (int) env('SESSION_COOKIE_REFRESH_TTL', 2592000),

        'path' => env('SESSION_COOKIE_PATH', '/'),

        // The refresh credential is sent ONLY to the session routes, never on ordinary requests.
        'refresh_path' => env('SESSION_COOKIE_REFRESH_PATH', '/auth/session'),

        // Null means a host-only cookie, which is the safe default.
        'domain' => env('SESSION_COOKIE_DOMAIN', null),

        'secure' => env('SESSION_COOKIE_SECURE', true),
        'same_site' => env('SESSION_COOKIE_SAMESITE', 'lax'),
    ],
];

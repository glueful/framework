# Browser Sessions

An opt-in cookie transport for browser clients, sitting alongside bearer authentication rather
than replacing it. A browser holds its credentials in `HttpOnly` cookies it cannot read from
JavaScript; an API client keeps sending `Authorization: Bearer` exactly as before. Both arrive
at the same `auth` middleware, the same providers and the same sessions.

Off by default. With `SESSION_COOKIE_ENABLED=false` the session routes are not registered at
all and nothing in the request path changes.

## Enabling it

```env
SESSION_COOKIE_ENABLED=true
```

Then add `session_cookie` **before** `auth` on the routes that should accept cookies:

```php
$router->get('/account', [AccountController::class, 'show'])
    ->middleware(['session_cookie', 'auth']);

// Pages that must survive a lapsed session: an invalid cookie degrades to anonymous
// instead of returning 401.
$router->get('/', [HomeController::class, 'index'])
    ->middleware(['session_cookie:optional', 'auth:optional']);
```

The middleware reads the access cookie, injects the `Authorization` header for downstream
`auth`, and records `auth_transport` (`cookie` or `bearer`) on the request. Identity attributes
(`user`, `auth.user`) remain `auth`'s alone.

## Issuing a session

Your login controller calls `LoginOrchestrator`, which runs credential verification, the
two-factor gate and session issuance. It returns a closed result:

```php
$outcome = $orchestrator->login($credentials);

if (!$outcome->isAuthenticated()) {
    // Render your second-factor step. There is no session yet, and no cookie to set.
    return $this->twoFactorPrompt($outcome->challenge());
}

return $issuer->issue(new RedirectResponse('/'), $outcome->session());
```

`SessionCookieIssuer::issue()` accepts only an `AuthenticatedSession`. A login awaiting
second-factor verification cannot produce one, so issuing cookies for an unfinished login is
not a mistake to avoid — it is unrepresentable.

Cookie attributes are set in exactly one place: `HttpOnly`, `Secure`, `SameSite=Lax`, `Path=/`
for the access cookie, no domain by default, and the refresh cookie path-scoped to
`/auth/session` so it is never attached to an ordinary request.

## Refresh and logout

- `POST /auth/session/refresh` — rotates both cookies using the path-scoped refresh cookie.
  Returns **no tokens**. Same-origin only.
- `POST /auth/session/logout` — revokes the server session and expires both cookies. Cookie-only;
  bearer clients use `POST /auth/logout`. If revocation fails the cookies are still cleared, but
  the response is a `500`, because a live server session after a logout is not a success.

The middleware never refreshes transparently: the refresh cookie is path-scoped, so it is not
sent on ordinary requests and a transparent refresh is impossible by construction. JavaScript may
call refresh and retry a **safe read**; unsafe requests must never be replayed automatically.

## CSRF

Cookie-authenticated unsafe requests are CSRF-exposed. Bearer requests are not — a bearer header
must be attached deliberately, which a cross-site page cannot do. `auth_transport` tells them
apart, so a route can require a CSRF token for cookie requests without imposing one on API
clients. When both credentials are present and resolve to the same identity, the bearer is the
effective transport; when they disagree, the request is rejected.

CSRF tokens bind to the session uuid. `SameSite=Lax` is defence in depth, not the primary
control: it blocks cross-site cookie transmission on POST, but an exact-origin check or a
session-bound token is what actually authorizes the write.

## What is unchanged

Bearer extraction, `POST /auth/login` (including its JSON response shape), `/auth/refresh-token`,
and `/auth/logout` all behave exactly as before. Token and API-key credential exchange is
untouched and does not pass through the orchestrator — those providers return an identity
payload with no tokens, which is not a session.

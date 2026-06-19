# `serveFrontend()` — Framework SPA-serving seam

**Date:** 2026-06-17
**Status:** Design approved — ready for implementation plan
**Repo:** `glueful/framework`

## Problem

A Glueful app that wants to ship a first-party SPA (an admin console, an editor UI) has
no clean way to serve it at a **literal path** like `/admin`. The framework has three
overlapping, half-finished pieces and none of them does the job:

| Piece | State | Gap |
|-------|-------|-----|
| `ServiceProvider::mountStatic($mount, $dir)` | Live, hardened | Hardcoded to `/extensions/{mount}` only; **no SPA deep-link fallback** (a non-file path returns 404 instead of `index.html`); `index.html` has no cache policy (stale shell after deploy). |
| `Extensions\SpaManager` (`registerSpaApp` / `handleSpaRouting`) | **Dead code** | `handleSpaRouting()` has zero callers; a comment in it admits "SPA registration is now handled automatically by `ServiceProvider::mountStatic()`." |
| `Helpers\StaticFileDetector` | Live registration, but only consumed by the dead `SpaManager` | Its `isStaticFile()` bundles a `$_SERVER['DOCUMENT_ROOT']` filesystem probe and hardcoded path patterns (`/assets/`, `/_next/`) — both wrong for a *mounted* build dir. |

The result: deep links and hard refreshes inside an SPA 404, the app shell can go stale
after a deploy, and the mount path is locked to `/extensions/…`.

There is **no backward-compatibility constraint** — the framework is pre-release and these
APIs have no external consumers. So this is a consolidation, not an additive change.

## Goal

One `ServiceProvider` method — `serveFrontend()` — that serves a built SPA at any literal path
with: secure asset serving, an `index.html` deep-link fallback for client-side routing, and
correct caching (immutable hashed assets, always-revalidated shell). It replaces
`mountStatic()` and retires the dead `SpaManager` / `StaticFileDetector` / `SpaProvider`.

## The seam

```php
/**
 * Serve a prebuilt SPA (or static bundle) at a literal path, with safe asset
 * serving and an optional index.html deep-link fallback for client-side routing.
 *
 * @param string $path  Literal mount path, e.g. '/admin' or '/app/console'.
 * @param string $dir   Filesystem directory of the built bundle (must contain index.html
 *                      when $spaFallback is true).
 * @param array{spaFallback?: bool, name?: string} $options
 *        spaFallback (default true): when a request under $path matches no real file and
 *          is not an asset request, serve index.html (200) so the SPA router can handle it.
 *          Set false for a plain static bundle: a miss is a 404, never index.html.
 *        name: human label used only in boot-time log messages.
 */
protected function serveFrontend(string $path, string $dir, array $options = []): void
```

Example callers:

```php
// SPA at a custom literal path (the new capability)
$this->serveFrontend('/admin', base_path('public/admin'), ['name' => 'Lemma Admin']);

// Plain static bundle, no SPA fallback (404 on miss) — replaces mountStatic's old role
$this->serveFrontend('/extensions/docs', $dir, ['spaFallback' => false]);
```

## Architecture

`serveFrontend()` registers two GET routes and shares one hardened file-serving engine between
them. There is no new security surface: the engine is lifted from the proven `mountStatic`
`$serveFile` closure (traversal guard, dotfile/`.php` denial, `headers_sent` guard, Symfony
mime detection, `SecurityHeaders`, ETag/Last-Modified/304). The only new logic is the
**asset-vs-route classification** that decides 404 vs `index.html` on a filesystem miss, and
the **cache policy split**.

### Boot-time validation (in this order)

1. **Path format (strict — no normalization).** `$path` must match
   `^/[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*$` — a leading `/`, one or more
   `[a-z0-9-]` segments, no traversal, no trailing slash, no dots. Invalid →
   `\InvalidArgumentException`. The **mount argument** is strict, not normalized: `serveFrontend('/admin/')`
   **throws**. (Multi-segment like `/app/console` is allowed.)
   *This is a contract on the developer-supplied mount string, not on request URLs.* A request to
   the URL `/admin/` still works at runtime: `Router::match()`/`dispatch()` `rtrim` the request
   path before matching (`src/Routing/Router.php:299` and `:602`), so `/admin/` normalizes to
   `/admin` and hits the root route. `serveFrontend` itself does **no** path normalization.
2. **Router present + dir exists.** If the router service is unavailable or `!is_dir($dir)`,
   **no-op return** (mirrors `mountStatic` today — don't crash boot for an absent bundle).
3. **`realpath($dir)`.** If `false`, no-op return.
4. **Index presence (when `spaFallback` is true).** If `index.html` is missing from the
   resolved dir, **log a warning and no-op return** — an SPA mount with no shell is broken,
   and silently registering routes that all 404 would hide the misconfiguration. (When
   `spaFallback` is false, no index is required.)

### Routes registered

```php
$router->get($path, $rootHandler);                       // exact mount, serves index (or 404)
$router->get($path . '/{rest}', $serveHandler)->where('rest', '.+');
```

- **GET only.** HEAD is handled by the framework automatically — `Router` auto-maps HEAD to
  GET (`src/Routing/Router.php:379-381`) and strips the body while keeping headers
  (`:672-673`). So registering GET covers HEAD with correct headers and no body. No explicit
  HEAD route is needed.
- **POST/PUT/etc. never fall back.** Because only GET is registered, a non-GET request to a
  mounted path gets the router's normal 405/404 — `index.html` is never returned for a
  mutating method.

### `$serveHandler($request, $rest)` — the classification

(`$rest` is always non-empty: the router `rtrim`s the request path before matching, so
`/admin/` resolves to the **root route**, never to `/admin/{rest}` with an empty `$rest`.)

1. `headers_sent()` → `Response('', 404)`.
2. Basename guard: empty, leading-dot (dotfile), or `*.php` → `404`.
3. **Traversal guard:** a `..` path segment in `$rest` → `404`, **before** any fallback. (The
   realpath check below already rejects an escaped *file*, but an extension-less traversal path
   like `../../../etc/passwd` would otherwise be classified route-like and reach the `index.html`
   fallback — so traversal is rejected outright here.)
4. Resolve `realpath($realDir . '/' . $rest)`. **Serve the file** when it resolves inside
   `$realDir . DIRECTORY_SEPARATOR` *and* `is_file()` — with the cache policy below.
5. **Miss** (not a real file):
   - `spaFallback === false` → `404`.
   - **Asset request** (basename has a file extension, i.e.
     `pathinfo($rest, PATHINFO_EXTENSION) !== ''`) → `404`. A missing `.js`/`.css`/`.png` is
     a 404, never the HTML shell. **Documented rule: "a dot means an asset."** A route-like
     path that contains a dot — e.g. `/admin/docs.v1` — is therefore treated as an asset and
     404s if no such file exists. This is the conservative, self-contained classification (no
     `DOCUMENT_ROOT` probing, no path-pattern guessing); apps should keep client routes
     dot-free.
   - Otherwise (route-like, no extension) → **serve `index.html` (200)** via the shared index
     helper.

### `$rootHandler()` — exact mount path

- `spaFallback === true` → serve `index.html` (200).
- `spaFallback === false` → `404` (no directory index/listing).

### Cache policy split

- **Content-hashed assets** → `Cache-Control: public, max-age=31536000, immutable`.
  "Hashed" is detected conservatively from the basename: a hash-looking segment of 8+
  alphanumerics immediately before the final extension, optionally delimited by `-`, `.`, or
  `_`. Pinned regex: `/[.\-_][A-Za-z0-9]{8,}\.[A-Za-z0-9]+$/`. This matches Vite/webpack
  output (`index-C5kJ8nQ2.js`, `app.4e1f9c2a.css`) and **not** stable names
  (`favicon.ico`, `logo.svg`, `manifest.webmanifest`).
- **`index.html` and any non-hashed asset** → `Cache-Control: no-cache` (always revalidated
  via ETag/Last-Modified; a 304 is cheap, and this guarantees a freshly deployed shell —
  pointing at new hashed asset filenames — is always fetched). This is the concrete fix for
  `mountStatic`'s stale-shell gap.

All served responses (assets and index) carry `SecurityHeaders::defaultStaticAssetHeaders()`,
which already includes `X-Content-Type-Options: nosniff`, a strict CSP, CORP, Referrer-Policy,
X-Frame-Options, and X-XSS-Protection. The index helper applies the **same** header set
(today's `mountStatic` re-lists those headers inline on the index branch — the consolidation
collapses that to one source of truth).

### Route precedence (documented constraint)

The router matches **static routes before dynamic ones**, so a real co-located route — e.g. a
static `GET /admin/config.json` registered by the app — always wins over the SPA catch-all
`{rest:.+}`. **Dynamic** API routes, however, must not be nested directly under the SPA's
mount prefix, because they share the catch-all's first-segment bucket and resolution then
depends on registration order. The rule: keep dynamic APIs under a different prefix
(e.g. `/v1/admin`), and reserve the SPA's prefix for the SPA + its static sibling routes. The
catch-all is the last-resort fallback for otherwise-unmatched paths under its prefix.

## Consolidation / cleanup

Because the classification is self-contained, `StaticFileDetector` is no longer used by any
live code. Full removal:

- **Replace** `ServiceProvider::mountStatic()` with `serveFrontend()` in
  `src/Extensions/ServiceProvider.php` (the `$serveFile` engine is factored into private
  helpers shared by both handlers).
- **Delete** `src/Extensions/SpaManager.php` (dead `registerSpaApp` / `handleSpaRouting`).
- **Delete** `src/Helpers/StaticFileDetector.php` (only consumer was `SpaManager`).
- **Delete** `src/Container/Providers/SpaProvider.php` (only registered the two deleted
  classes).
- **Remove** `SpaProvider::class` from the provider list at
  `src/Container/Bootstrap/ContainerFactory.php:177`.

Out of scope (left untouched): `Http\FileResponseWrapper` (separate concern — Laravel-style
header chaining for controller file responses).

## Testing

Port and extend the existing `MountStaticSecurityTest` (rename → `ServeFrontendTest`) and the
`mountStatic` reflection assertions in `ServiceProviderTest`. Required cases:

**Security (preserved from mountStatic):**
- Path traversal (`../`, encoded variants) → 404.
- Dotfile (`.env`) and `*.php` → 404.
- A real asset is served with `SecurityHeaders` defaults applied (incl. `nosniff`).

**New SPA behavior:**
- Hashed asset (`app-C5kJ8nQ2.js`) → 200 + `Cache-Control: public, max-age=31536000, immutable`.
- Non-hashed asset (`favicon.ico`) → 200 + `Cache-Control: no-cache`.
- Exact mount path (`/admin`) → `index.html` 200 + `no-cache`.
- Request to the trailing-slash URL `/admin/` behaves identically to `/admin` — assert via full
  dispatch (`Router::match()`/`dispatch()` `rtrim` the request to `/admin` before matching, so it
  hits the root route). This is router normalization, **not** `serveFrontend` logic.
- Route-like deep link (`/admin/posts/123`, no extension) → `index.html` 200 + `no-cache`.
- Missing **asset** (`/admin/missing.js`) → 404 (not index.html).
- Dot-rule edge (`/admin/docs.v1` with no such file) → 404 (treated as asset; documented).
- `spaFallback: false` → a miss (file and route-like) → 404; exact mount → 404.

**Precedence:**
- A real static route registered under the mount prefix (`GET /admin/config.json`) is **not**
  shadowed by the SPA catch-all.

**Boot-time guards:**
- Invalid **mount argument** `$path` (`admin`, `/Admin`, `/admin/`, `/admin/../x`) →
  `InvalidArgumentException`. Note `/admin/` is rejected as a *mount argument* (strict API) even
  though a *request* to `/admin/` is accepted at runtime (router-normalized) — see the
  trailing-slash case above.
- `spaFallback: true` + dir present but `index.html` missing → no-op + warning logged
  (no routes registered).
- Missing dir → no-op (no exception).

## Out of scope / follow-ups

- A `glueful spa:*` CLI or asset-manifest awareness — not needed; the dot/hash heuristics are
  filename-based and framework-agnostic.
- Serving from outside the app filesystem (S3/CDN origin) — `serveFrontend` is for local bundles;
  CDN delivery remains the production recommendation for public assets.
- Per-mount custom CSP — apps can post-process via middleware; the default static CSP stays.

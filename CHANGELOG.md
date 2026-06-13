# CHANGELOG

All notable changes to the Glueful framework will be documented in this file.

The format is based on Keep a Changelog, and this project adheres to Semantic Versioning.

## [Unreleased]

### Added
- **`docs/SECURITY_NOTES.md` — accepted security trade-offs and known limitations.** Records the residual limitations reviewed and accepted during the June 2026 hardening pass (log-redaction scope, SSRF filter-flag fringe ranges, JWT clock strictness, signing/scan boundaries, etc.) with rationale and the work item that would close each, plus the operator checklist of config-dependent protections (`APP_KEY`, `TRUSTED_PROXIES`, CORS origins).

### Fixed
- **Security: shared authentication guards are reset at request end.**
  `AuthenticationGuard` cached the first resolved user for the lifetime of the
  shared container instance, which is unsafe in long-running runtimes. The guard
  now exposes `reset()`, and the request lifecycle clears it alongside the
  token/session request caches so a reused worker resolves the next request's
  identity instead of reusing the previous user.
- **Security: ORM terminal query-builder methods now apply global scopes before proxying.**
  `Database\ORM\Builder::__call()` previously delegated terminal methods such as
  `paginate()` and `max()` straight to the underlying query builder without
  `applyScopes()`, bypassing model global scopes including tenancy's fail-closed tenant
  scope. Scoped terminal methods now apply global scopes before delegation.
- **Security: extension provider register/boot failures fail loud outside production.**
  `ExtensionManager` still logs provider `register()` / `boot()` failures, but now
  rethrows them when the active environment is not `production`; production keeps the
  previous log-and-continue posture. This prevents load-bearing providers from silently
  missing enforcement hooks during development and test runs.
- **SchemaBuilder alter-index operations now work on existing tables.** `alterTable(...)->index()` no longer validates new indexes against only the columns declared inside the alteration callback, so adding an index to an existing column works; `dropIndex()` now flushes the generated drop statement instead of returning success while leaving the index in place, and a tolerated missing-index drop no longer leaves failed SQL queued for the next schema operation.
- **Security: the `rate_limit:attempts,window` middleware string form now enforces its parameters.** `EnhancedRateLimiterMiddleware::handle()` accepted `...$params` but ignored them — limits came only from `Route::getRateLimitConfig()` (the `->rateLimit()` builder / `#[RateLimit]` attribute), so every route registered as `'rate_limit:5,60'` (including the framework's own auth, blobs, and health routes) silently fell back to tier/global defaults instead of the stated per-route limit. The params are now parsed as `attempts,windowSeconds` (window defaults to 60) and applied when the route carries no rate limit config; builder/attribute config still takes precedence, and non-numeric or non-positive params keep the previous default-limits behavior.
- **Security: `ImageSecurityValidator` defaults are fail-closed.** With no `image.security` config present (e.g. the `glueful/media` extension not installed, or its config not yet merged), the validator defaulted to `allowed_domains: ['*']` and `disable_external_urls: false` — an empty config meant "fetch images from anywhere". The defaults are now an empty allow-list with external URLs disabled, matching the media extension's fail-closed posture; remote image fetching is opt-in everywhere.
- **Safe HTTP fetches can opt into private-network targets, and webhook batches are concurrent again.** `Client::safeRequest()`/`safeFetch()` accept an `allow_private_hosts` option that skips the private/reserved-range rejection while keeping the scheme allowlist, DNS resolution, and IP pinning; `HealthCheckService` uses it by default (health endpoints are operator-configured and commonly internal — pass `allow_private_hosts => false` per service to restore the strict posture). New `Client::safeRequestAsync()` validates and IP-pins a URL up front, never follows redirects, and returns the async response; `WebhookDeliveryService::deliverBatchWebhooks()` uses it so batch deliveries run concurrently again (lost when the batch path moved to sequential `safeRequest()` calls) while an unsafe URL still fails only its own entry. Webhook redirect responses now count as failed deliveries instead of being followed.
- **Security: sensitive-parameter redaction is unified in `Glueful\Support\SensitiveParamRedactor`.** Request/response logging, exception reporting (`Http\Exceptions\Handler`), authentication access logs, and the admin security-violation listener (which previously logged raw `request_uri`) now share one redactor, so the pattern lists can no longer drift apart. The unified list is the union of the prior copies plus broader coverage: exact names now include `code`, `auth`, `authorization`, and `authorization_code`, and `signature`/`password` match as substrings (catching `x_signature`, `new_password`, `authorization_code`-style names everywhere). The exception handler thereby gains the `password`/`otp`/`pin`-class coverage it was missing.
- **Security: scheduled-job envelopes now bind the row name and cron schedule.** `QueuePayloadSigner::encodeScheduledParameters()` signs `name` and `schedule` alongside the handler class and parameters, and `JobScheduler` verifies them against the row at decode time — so a tampered `scheduled_jobs` row can no longer clone a signed (handler, parameters) pair into another row or reschedule a job (e.g. to every minute) without breaking the HMAC. Envelopes created before the binding existed decode unchanged (the keys can't be stripped from a bound envelope without invalidating the signature); toggling `is_enabled` directly in the table remains outside the signature's scope.
- **Security: legacy rate-limit cache keys no longer expose raw IP identifiers.** CSRF token-generation rate limiting, the older `SecurityManager` limiter, and `CacheHelper::rateLimitKey()` now hash IP/identifier material before building backend cache keys, matching the main API `RateLimitManager` posture.
- **Security: SSRF-safe HTTP fetches now pin validated DNS results and are used for webhooks and health checks.** `Client::safeRequest()` now passes Symfony's `resolve` option for the host/IP pair it validated, reducing DNS-rebinding exposure between validation and connect. Webhook delivery and external health checks now use the safe request path, and `ImageSecurityValidator` now treats uppercase `HTTP://` / `HTTPS://` schemes as external URLs.
- **Security: image variants now validate source bytes before media decoding and preserve `nosniff` on 304s.** `UploadController` now runs the core `ImageSecurityValidator::validateImageFile()` check on the stored source file before handing it to a media processor for resize/variant rendering, rejecting MIME/dimension mismatches before decode. Variant `304 Not Modified` responses now also send `X-Content-Type-Options: nosniff`.
- **Security: authentication access logs now redact sensitive request URI parameters.** `AuthenticationManager::logAccess()` now sanitizes `request_uri` before logging, redacting query values whose names contain `token`, `key`, or `secret`, plus `signature` and OAuth-style `code` parameters.
- **Redis queue retries preserve signed payload validity.** `RedisQueue` now re-signs job hashes whenever it mutates signed fields such as `attempts`, `reservedAt`, or `availableAt` during pop, release, and expired-reservation recovery. Legitimately retried Redis jobs no longer fail payload verification on their second delivery when queue payload signing is enabled.
- **Security: Symfony requests now honor configured trusted proxies.** `RequestProvider` now applies `security.trusted_proxies` / `TRUSTED_PROXIES` before creating the global Symfony request, so `Request::getClientIp()` can resolve the real client behind trusted load balancers instead of collapsing all traffic into the proxy IP.
- **Security: rate-limit cache keys no longer expose raw request dimensions.** IP, user, endpoint path, and custom-pattern rate-limit keys are now hashed before storage so cache backends do not receive raw client IPs, reset-token paths, invite codes, or user identifiers as key material.
- **Security: `serve --open` now shell-quotes the browser URL.** The development server already starts PHP via Symfony Process argv, but the optional browser opener interpolated a host-derived URL into an OS shell command. The generated `open` / `xdg-open` / `start` command now quotes the URL before execution.
- **Security: migration rollback now rejects non-basename history filenames.** Rollback no longer resolves `migrations.migration` values containing path traversal or null bytes against a source directory, preventing a tampered migration-history row from including a PHP file outside the registered migration path.
- **Security: AuthMiddleware fallback extraction now respects the JWT query-token gate.** The direct fallback path no longer accepts `?token=` unless `security.tokens.allow_query_param` is explicitly enabled, matching `TokenManager` and the JWT provider behavior.
- **Security: JWT decoding now requires bounded temporal claims.** `JWTService::decode()` now rejects tokens without an `exp` claim, tokens with expired/non-numeric `exp`, future `nbf`, and future/non-numeric `iat` values. Missing `exp` no longer creates a signature-valid token that never expires.
- **Security: file encryption now uses chunked authenticated streaming and rejects all-zero keys.** `EncryptionService::encryptFile()` / `decryptFile()` no longer buffer whole files into memory for new payloads; file encryption now writes a sodium secretstream envelope and decryption keeps a legacy single-payload fallback for older encrypted files. Key validation now rejects the all-zero 32-byte key instead of accepting any byte string with the right length.
- **Security: permission and role attributes are enforced consistently for inherited and manual routes.** `#[RequiresPermission]` / `#[RequiresRole]` detection now walks parent controller classes, so inherited class-level gates are not dropped. Manually registered handlers carrying these attributes now receive `gate_permissions` during route finalization, matching attribute-loaded routes and avoiding a fail-open route-registration footgun.
- **Security: HTTP client user-supplied fetches can now opt into SSRF-safe URL validation.** Added `Client::safeFetch()` / `safeRequest()` for untrusted URLs: each hop must use `http`/`https`, resolve successfully, and resolve only to public IP space; redirects are followed manually with Symfony auto-redirects disabled so every `Location` is revalidated. `max_redirects` is now passed through for normal requests and defaults to `http.safe_fetch.max_redirects` (3) for safe fetches.
- **Security: image URL validation, upload scanning, and blob serving are hardened.** `ImageSecurityValidator` now resolves external image URL hosts and rejects private/reserved/unresolvable targets instead of relying on incomplete substring checks; it also exposes `validateImageFile()` so callers can enforce finfo-detected MIME and `getimagesize()` dimensions before image decoding. The live `FileUploader::uploadMedia()` path now runs the same extension/MIME/content hazard scan as the legacy upload path before storage. Blob responses from `UploadController` now always send `X-Content-Type-Options: nosniff`, and MIME types outside the safe inline image allowlist (`jpeg/png/gif/webp`) are served with `Content-Disposition: attachment`.
- **Security: exception reporting now redacts sensitive URL parameters in request context.** `Http\Exceptions\Handler` previously logged raw `request.uri` and verbose `request.query_string` values when reporting exceptions, so tokens, API keys, OAuth `code`, signatures, and signed-URL material in the current URL could be persisted by the exception logger. Report contexts now sanitize URI and query-string fields with the same sensitive-name pattern used by request logging (`*token*`, `*key*`, `*secret*`, `signature`, `code`).
- **Security: request/response logging now redacts sensitive URL and form parameters before writing logs.** `RequestResponseLoggingMiddleware` previously logged `uri`, `query_string`, and `Referer` directly, and `log_bodies=true` only scrubbed JSON bodies, so signed URLs, OAuth `code`, `token`/`api_key` query values, and urlencoded form secrets could be persisted verbatim. The middleware now sanitizes request/response/slow/failure URI fields, redacts query and referer parameters by sensitive name pattern (`*token*`, `*key*`, `*secret*`, `signature`, `code`), and parses `application/x-www-form-urlencoded` bodies through the same sanitizer before logging.
- **Security: persisted queue and scheduler payloads are HMAC-signed when an app key is configured.** Database and Redis queue payloads are signed on enqueue and verified before handler resolution/execution; scheduled-job parameters are stored in a signed envelope that also covers the handler class. Tampered payloads now fail before job code runs, and invalid queue rows are treated as terminal failures instead of being allowed to mutate their own retry budget. New config keys under `queue.security.payload_signing` control this behavior (`QUEUE_PAYLOAD_SIGNING`, default on; `QUEUE_REQUIRE_SIGNED_PAYLOADS`, default on). Signing is inert when no `app.key` / `APP_KEY` is configured; deployments with legacy unsigned rows can temporarily set `QUEUE_REQUIRE_SIGNED_PAYLOADS=false` while draining old payloads.
- **Security: queued and scheduled stored handler classes must now opt into the queue contract before instantiation.** `DatabaseJob`, `RedisJob`, and `JobScheduler` previously read a class name from persisted queue / scheduler payloads and instantiated it with only a `class_exists()` check, letting anyone with write access to those backends trigger arbitrary constructors. Handler resolution now goes through `JobHandlerResolver`: the class must implement `JobInterface`, base `Job` subclasses receive their payload/context directly, and other job implementations are resolved through the container when available. `Job::fromArray()` / `unserialize()` also refuse non-queue classes. Plain `handle()` fixtures were updated to real `Job` subclasses so the tests exercise the new gate.
- **Security: remaining native `unserialize()` call sites on cache blobs now route through `SecureSerializer`.** The 1.55.0 gadget gate only covered data passed through `SecureSerializer`; three components read the same attacker-influenceable cache payloads with native `unserialize()` and no allowed-class list, each an object-injection sink: (1) `MemcachedCacheDriver` stored raw values, letting the Memcached extension's internal (ungated) serializer materialize arbitrary objects on read — it now holds a `SecureSerializer::forCache()` and stores only encoded strings (values, multi-get/set, and the emulated sorted sets), mirroring `RedisCacheDriver`; corrupt or disallowed sorted-set payloads read as empty instead of fataling. (2) `Cache\Nodes\FileNode` round-trips values and tag sets through the serializer; a disallowed-class payload now degrades to `null`/`[]` (logged) instead of instantiating. (3) `CacheMaintenanceTask` inspected `storage/cache` files with native `unserialize()` just to read `expires_at` — it now deserializes through the gate, treating unreadable or disallowed payloads as malformed files (eligible for age-based cleanup) rather than executing them. **Behavioral note:** Memcached entries written by older versions that the extension stored in its native serialized form are passed through unchanged when non-string; raw legacy *string* values that aren't valid serialized data now throw on read instead of being returned verbatim — flush the cache when upgrading a Memcached-backed deployment.
- **Security: `SessionStore::getByAccessToken()` no longer resolves a session from unverified token claims.** Its DB fallback read the `sid`/`ver` claims via `JWTService::getPayloadWithoutValidation()` (a plain base64 decode -- no signature check, despite that method's own "never use for authentication" warning), so a token with a forged signature but a known/guessed session id resolved a real active session. Consumers that treat the resolved session as identity -- `AdminPermissionMiddleware::getUserUuidFromToken()` and `PermissionManager::getUserUuidFromToken()` only fall through to the verifying path when the lookup returns null -- would then trust an attacker-chosen `user_uuid`. The lookup now resolves claims via `JWTService::decode()` (full signature **and** expiry verification) and fails closed on a bad signature, expired token, or unavailable JWT key; `matchesSessionClaims()` now receives the verified claims instead of re-decoding unvalidated ones. Reachability was limited (`AuthMiddleware` verifies the token before these run), but identity resolution must never trust an unverified claim. (`TokenManager`'s remaining `getPayloadWithoutValidation()` uses are deny-only revocation checks, not identity resolution.)
- **Security: the standalone CORS handler (`Glueful\Http\Cors`) no longer defaults open.** Four related fixes: (1) when no origins were configured the constructor forced `['*']`, making the no-config production default allow every cross-origin caller -- it now fails closed to `[]` (deny all; wildcard requires an explicit `'*'` / `allow_all_origins`, and development stays permissive via config/cors.php's env-driven `'*'`). (2) It read `supports_credentials` from the cors config, but config/cors.php defines `allow_credentials` -- so the configured `false` was ignored and the fallback (`security.cors.supports_credentials`, which was `true`) enabled credentials anyway; both keys are now honored (`allow_credentials` first) and the unconfigured default is **disabled**. (3) `Access-Control-Allow-Credentials` is never emitted when the allowed origins contain `'*'` -- the handler reflects the request Origin, so wildcard + credentials would let any website make credentialed requests; enforced at emit time so no config or fluent-mutation order can re-enable the combination. (4) Responses that reflect the Origin now send `Vary: Origin`, so a shared cache cannot serve one origin's CORS response to another. config/security.php's `cors.supports_credentials` default also changed from hardcoded `true` to `(bool) env('CORS_SUPPORTS_CREDENTIALS', false)`, matching config/cors.php. **Behavioral note:** apps using `new Cors()` with no origin config now deny cross-origin requests instead of allowing all -- set `CORS_ALLOWED_ORIGINS` (or use the factories); apps relying on the accidental credentials default must set `CORS_SUPPORTS_CREDENTIALS=true`. (The Router's built-in CORS path already had the guard and `Vary: Origin`; this fixes the standalone handler.)
- **Pooled-connection session reset no longer drops per-connection init (regression in 1.55.0's pool cleanup).** The `DISCARD ALL` (PostgreSQL) / `RESET CONNECTION` (MySQL) issued on `ConnectionPool::release()` also wipes the pool's own per-connection setup -- the PostgreSQL `search_path` and MySQL's `MYSQL_ATTR_INIT_COMMAND` (`sql_mode='STRICT_ALL_TABLES'`) -- which were only applied at PDO creation. A reused connection therefore ran against the default schema (PostgreSQL with a custom `DB_PGSQL_SCHEMA`) or without strict mode (MySQL default sql_mode -> silent data truncation). `resetSession()` now reapplies the session init (re-`SET search_path` / replay of the init command) after a successful reset; if the reapply fails the connection is marked unhealthy and retired instead of being pooled in a wrong-schema/non-strict state. Connection creation routes through the same init helper so the two paths cannot drift.

## [1.55.0] - 2026-06-11 — Peacock

> **Theme: Security & correctness hardening.** A focused pass over the framework's security-sensitive surfaces (routing/permissions, auth, storage paths, the query write-path, deserialization, and the container/extension boundary). Most items are bug fixes, but several change behavior or defaults -- see **Upgrade Notes** -- and one (range UPDATE/DELETE predicates) is a new capability, so this ships as a minor. Staying in 1.x per the pre-public policy.

### Added
- **Range predicates on UPDATE/DELETE.** Two predicates on the same column now both apply on writes -- `->where('id','>',1)->where('id','<',3)->update([...])` / `->delete()` updates/deletes only the in-range rows. Previously the write-path condition reparser keyed conditions by column name, so the second predicate silently overwrote the first (the range collapsed to a single bound, affecting more rows than intended). Repeated columns are now folded into a `__multi` list that the update/delete builders emit AND-joined, preserving binding order. (SELECT was always correct; this only affected UPDATE/DELETE.)

### Fixed
- **Security: route permission attributes are now actually enforced (`handler_meta` was never populated; the gate was never auto-attached).** Two defects made `#[RequiresPermission]` / `#[RequiresRole]` fail open: (1) `GateAttributeMiddleware` (the `gate_permissions` alias) reads the matched handler's attributes from the `handler_meta` request attribute, but nothing ever set it -- so even when the middleware ran it passed every request through; and (2) unlike `#[RequireScope]` (which auto-attaches `require_scope`), nothing auto-attached `gate_permissions` for the permission/role attributes, so the middleware never ran at all unless the developer added it by hand. An authenticated user therefore cleared permission-gated routes regardless of grants (`PermissionManager::can()` itself was always fail-closed; it was simply never invoked). The Router now derives `handler_meta` after route match (supports `[Class::class, 'method']`, `Class::method` string, and invokable-class handlers) and sets it before the middleware pipeline runs; `AttributeRouteLoader` now auto-attaches `gate_permissions` whenever `#[RequiresPermission]`/`#[RequiresRole]` is present on the method **or the handler class** (so class-level annotations guard every route in the controller). Regression tests prove deny -> 403 end-to-end and the auto-attach for method- and class-level attributes. **Behavioral note:** routes annotated with permission attributes now genuinely enforce -- apps using such routes (including `glueful/users`' admin surfaces) without a permission provider bound will now receive 403s instead of silent allows. Bind a provider (e.g. `glueful/aegis`) or grant the required permissions.
- **Security: signed URLs fail closed when no signing secret is configured.** `SignedUrl::resolveSecretKey()` fell back to a hardcoded constant (`'glueful-default-signing-key'`) when neither `uploads.signed_urls.secret`/`app.key` (config) nor `SIGNED_URL_SECRET`/`APP_KEY` (env) was set. Since signed URLs gate private blob access, a misconfigured deployment used a publicly known HMAC key -- letting anyone forge `expires`+`signature` for any blob. Construction now throws instead of signing or validating with an insecure default.
- **Security: `FlysystemStorage::storeContent()` now routes writes through PathGuard.** The string-content write path called `disk()->write()` directly, bypassing the traversal / absolute-path / null-byte validation that `store()` (and the rest of `StorageManager`) applies -- so a user-influenced destination could escape the configured prefix. Flysystem's own normalizer rejects `..` for all adapters, but **absolute paths** (e.g. `/etc/evil`) it silently relativizes; on cloud drivers that is a real prefix-escape. Added a PathGuard-validated `StorageManager::put(path, contents, disk)` (the missing string-content primitive, alongside `putStream`/`putJson`) and routed `storeContent()` through it, restoring the "every write routes through PathGuard" invariant regardless of adapter behavior.
- **Security: `#[RequireScope]` no longer passes for non-scope-bearing (e.g. JWT) requests.** The middleware read granted scopes from the `api_key_scopes` request attribute, which only the API-key provider sets. A request without that attribute (e.g. JWT-authenticated) produced an empty scope set, and the "empty scopes = unrestricted key" rule then satisfied any `#[RequireScope]` -- so any authenticated user cleared a scoped route. It now distinguishes the attribute being **absent** (deny -- not scope-bearing auth) from **present-but-empty** (an unrestricted API key, still allowed).
- **Security: removed the unverified-JWT claims fallback.** `AuthToRequestAttributesMiddleware::extractJwtClaims()` had a fallback that base64-decoded the JWT payload with no signature verification; those claims feed the gate's `ScopeVoter`. It was latent (guarded by an always-true `class_exists`) but a forge-the-scope primitive one edit away -- removed entirely; claims now come only from the signature-verifying `JWTService::decode()`.
- **Security: API key via `?api_key=` query string is now off by default.** Query strings leak into access logs, proxies, browser history, and `Referer`. The query-param key source is gated behind `security.api_keys.allow_query_param` (default false), mirroring the JWT `allow_query_param` gate; the `X-API-Key` header is unaffected.
- **Security: operator/identifier allow-listing on raw-interpolated query surfaces.** Operators that are interpolated directly into SQL (not bound) are now validated against a fixed allow-list (`Glueful\Database\Query\SqlOperators`): JOIN operator + join type (`JoinClause`), `HAVING` operator (`QueryModifiers`), and the ORM `has()`/`whereHas()` count comparison (`ORM\Builder`). JSON paths in `whereJsonContains()` are validated against a conservative path grammar before interpolation, and `wrapIdentifier()` now doubles embedded quote characters (MySQL backtick / PostgreSQL double-quote) so an identifier cannot terminate its own wrapping. These are developer-facing methods (safe under documented use); the guards harden against an app forwarding request input into them.
- **Security: `SecureSerializer` namespace auto-allow no longer covers gadget-shaped classes.** The `unserialize` allow-list auto-trusted any class under `Glueful\Models\*` / `\DTOs\*` / `\Entities\*` / `\Extensions\*\Models|DTOs\*`. There is no live gadget today, but any such class that later gained a `__wakeup`/`__destruct`/`__unserialize` would silently become a cache/queue deserialization gadget. The auto-allow now excludes any class declaring one of those magic methods (it must be explicitly allow-listed instead); plain data classes are unaffected.
- **Security: optional fail-closed CSRF token-generation rate limiter.** The limiter fell open (allowed) whenever its cache was absent or errored. That default is preserved (a cache outage should not lock out all token generation), but a stricter posture can now opt into fail-closed (deny) via `security.csrf.rate_limit_fail_closed`.
- **Container hardening: extension load failures no longer fail silently.** A provider whose `services()`/`defs()`/`tags()` threw was caught and `error_log`'d, then the **entire provider** was dropped and boot continued -- so a misconfigured extension vanished with no signal (e.g. an entitlement checker silently replaced by core's allow-all default). Now, outside production a load failure is **rethrown** (wrapped, naming the provider + phase) so it surfaces at boot; in production it is recorded (`ContainerFactory::failedProviders()`, for `extensions:diagnose` / health checks) and logged at WARNING, but boot continues so one broken extension cannot take the app down. Mirrors the existing `ServiceProvider::loadRoutesFrom()` posture.
- **Container hardening: non-instantiable service bindings are rejected at load time.** A DSL `services()` entry whose id is an interface or abstract class with no `class`/`factory`/`autowire` was accepted and only fataled with "Cannot instantiate interface" at first resolution -- possibly in production, on a cold path (this bug class bit several first-party extensions during development). `DefaultServicesLoader` now rejects it at load, naming the id, with guidance to bind a concrete class or factory. (Also surfaces the inverted-`alias` footgun -- `Interface => ['alias' => Concrete]` -- which left the interface bound to `new Interface()`.)
- **Container hardening (minor): clearer container internals.** The `'alias'` DSL direction is now documented inline on `DefaultServicesLoader::collectAliases()` (`Id => ['alias' => X]` makes `X` resolve to `Id`, not the reverse -- the footgun behind the bricked extensions); the framework-reserved service ids are consolidated into a single `ContainerFactory::RESERVED_KEYS` (exposed via `reservedKeys()`) with a `pinReservedDefinitions()` helper instead of three scattered statements; a production container-compilation failure now logs at WARNING before falling back to the runtime container (previously a silent degrade to uncompiled); and the empty-tag behavior (`$c->get('some.tag')` throws `NotFoundException` when no service contributed to a tag) is documented so consumers of optional extension tags `has()`-check.
- **`FlysystemStorage::exists()` / `delete()` route through PathGuard.** Like the `storeContent()` fix, these passed raw paths straight to the disk adapter; they now go through new PathGuard-validated `StorageManager::fileExists()` / `delete()`, so a traversal/absolute path resolves to "does not exist" / "nothing deleted" rather than probing or deleting an unvalidated path.
- **Router reflection cache no longer breaks on object handlers.** `Router::getReflection()` built its cache key by string-concatenating the handler, which threw "Object could not be converted to string" for an `[object, method]` handler. The key now derives the class name from the object (matching the `handler_meta` fix). Edge case (such handlers don't survive route caching), but no longer a latent fatal.

### Security notes
- **Signed URLs are host-agnostic -- use a per-environment signing secret.** `SignedUrl` HMACs the path + query only (not the host), so a signature is valid on any host sharing the secret. Documented (in `SignedUrl` and `config/uploads.php`) that deployments must use a distinct `uploads.signed_urls.secret` / `SIGNED_URL_SECRET` / `APP_KEY` per environment; reusing one `APP_KEY` across staging/prod enables cross-environment replay.
- **Data integrity: wrong-row UPDATE/DELETE from a duplicate-column WHERE.** See the range-predicate item under Added -- the silent predicate collapse could delete/update more rows than the query specified; it now applies both predicates.
- **Data integrity: soft-delete column cache is no longer shared across connections.** The "does this table have a `deleted_at` column?" cache was process-static and keyed by table name alone, so two connections to different databases (e.g. multiple tenants) that share a table name poisoned each other's soft-vs-hard-delete decision -- causing irreversible hard-deletes of would-be-soft rows, soft-deleted rows leaking into reads, or a "no such column" error. The cache key is now namespaced per `Connection` instance (and includes the configured `deleted_at` column), so connections cannot poison each other while a single connection still amortizes the schema lookup across its queries.
- **Data integrity: pooled connections are cleaned before reuse.** `ConnectionPool::release()` now rolls back any transaction left open on the underlying PDO (checking the raw PDO, since direct use bypasses the wrapper's tracked flag) and clears per-session state (`DISCARD ALL` on PostgreSQL, `RESET CONNECTION` on MySQL; no-op on SQLite/unknown, disabled gracefully if unsupported) before returning the connection to the pool. Previously a borrower that left a transaction open could have its uncommitted write committed by the next borrower, or leak uncommitted/cross-tenant rows. A connection that cannot be cleaned is retired rather than reused. (Connection pooling is opt-in via `DB_POOLING_ENABLED`, default off.)

### Upgrade Notes

Most changes are backward-compatible bug fixes. The following can change behavior for an existing app -- review them before upgrading:

- **`#[RequiresPermission]` / `#[RequiresRole]` now actually enforce.** These attributes were silently unenforced; routes annotated with them now require the permission/role. **A route annotated with a permission attribute but running without a permission provider bound will now return 403 instead of allowing the request.** Bind a provider (e.g. `glueful/aegis`), grant the required permissions, or remove the attribute from routes that should be open. This includes `glueful/users`' admin surfaces. (`#[RequireScope]` was already auto-attached and is unaffected by this change, but see the next item.)
- **`#[RequireScope]` now denies requests that carry no API-key scopes.** A scoped route reached by a non-API-key request (e.g. JWT) previously passed (absent scopes were treated as an unrestricted key); it now denies. If you intend JWT users to clear scoped routes, gate those routes differently (scopes are an API-key concept).
- **API key via `?api_key=` query string is now OFF by default.** If your clients authenticate with the key in the query string, either move them to the `X-API-Key` header (recommended) or set `security.api_keys.allow_query_param = true`. The header path is unchanged.
- **Signed URLs fail closed without a secret.** If neither `uploads.signed_urls.secret` / `SIGNED_URL_SECRET` nor `app.key` / `APP_KEY` is configured, generating or validating a signed URL now throws instead of using a hardcoded default key. Configure a signing secret (use a **distinct** value per environment -- signatures are host-agnostic).
- **Extensions fail loud at boot outside production.** A provider whose `services()`/`defs()`/`tags()` throws is now rethrown (naming the provider) outside production, and a DSL service bound to a bare interface/abstract is rejected at load. In production these are logged at WARNING and recorded in `ContainerFactory::failedProviders()` and boot continues. If you have an extension that was silently failing to register, it will now surface -- fix the binding (a non-instantiable id needs `['class' => Concrete::class]` or a factory).
- **New config keys (both optional, secure defaults):** `security.api_keys.allow_query_param` (default `false`) and `security.csrf.rate_limit_fail_closed` (default `false`). No new env vars; no migrations.

## [1.54.0] - 2026-06-10 — Okab

> **Theme: Real extension seams — overridable container defaults, the entitlement contract, and a storage provider registry.** A coordinated release in three movements: (1) a **container-precedence fix** that makes every "core default + extension override" seam actually overridable (extension definitions previously lost silently to core bindings); (2) the **`Glueful\Entitlements` core seam** — a contract-only extension point for commercial capability gates, consumed by the forthcoming `glueful/subscriptions`; and (3) a **storage driver registry** with the `s3`/`gcs`/`azure` factories **extracted to first-party provider packs** (breaking — lean core, same playbook as 1.52). Staying in 1.x per the pre-public breaking-changes policy — see **Upgrade Notes**.

### Breaking Changes
- **Core ships only the `local`/`memory` storage drivers.** The `s3`, `gcs`, and `azure` driver factories (and the embedded S3 presign logic) have been removed from core and extracted to first-party packs. `glueful/storage-s3` ships alongside this release (it also covers **R2 / MinIO / Spaces / Wasabi** via presets); `glueful/storage-gcs` and `glueful/storage-azure` follow shortly. A disk using one of those drivers now fails fast with `UnsupportedStorageDriverException` naming the package to install (e.g. `composer require glueful/storage-s3`). The `s3` stub disk in `config/storage.php` is now commented out (core's default config only declares disks core can create). **Upgrade:** after updating the framework, `composer require glueful/storage-s3` (or the gcs/azure pack once published) for whichever driver your app uses. Apps on `local`/`memory` only are unaffected.

### Added
- **Entitlement seam (`Glueful\Entitlements`).** New core extension point: `EntitlementCheckerInterface` (`allows()` / `limit()`, explicit tenant uuid) with an absent-allow `NullEntitlementChecker` default bound in `CoreProvider`. Lets extensions and app code gate commercial capabilities without depending on any specific subscriptions package. Core ships the contract only -- no consumer, no tenant/plan awareness. `glueful/subscriptions` provides the real checker. (Override of the default relies on the container precedence fix in the same release.)
- **Storage driver registry + provider seam.** New `Glueful\Storage\Contracts\StorageDriverFactoryInterface` (driver identity, construction, `available()`, `features()`), `StorageDriverRegistryInterface` (+ `StorageDriverRegistry::withBuiltIns()`), and optional capability contracts `NativeSignedUrlProviderInterface` / `StorageHealthCheckInterface`. `StorageManager` now resolves every disk through the registry (last-registered-wins per driver), accepts a nullable registry (defaults to built-ins -- `new StorageManager($config, $pathGuard)` is unchanged), drives `putStream()` through `features()['supports_atomic_move']` (default true), and exposes the registry via `drivers()`. Extensions register factories tagged `storage.driver_factory`; `StorageProvider` collects them after the `local`/`memory` built-ins and reverses the tagged iterator so higher tag priority wins same-driver collisions. `FlysystemStorage` delegates writes to `StorageManager::putStream()` and resolves native signed URLs via `NativeSignedUrlProviderInterface` (falling back to the app URL on `null` or provider errors).
- **`storage:test [disk]` command.** Read-only by default (reports driver registration, adapter `available()`, and a non-mutating liveness probe via the health-check capability); `--write` opts into a write/read/delete smoke test. Never prints secrets.
- **Optional `native_url` in the blob API.** Additive, default-off, per-disk, visibility-scoped (`config('uploads.native_urls')`): `public` blobs may serve a direct provider URL; `private` blobs only with a bounded TTL. The app-signed `/blobs/{uuid}` URL stays the always-available, access-controlled path.

### Fixed
- **Container precedence: extension definitions now override core defaults.** `ContainerFactory` merged extension service definitions with `+=`, which kept the core binding on key collision and silently dropped extension overrides -- making core default bindings (`UserProviderInterface -> NullUserProvider`, and every "core default + extension override" seam) un-overridable through the normal provider path. Now merged with `array_replace` (extension-over-core); `ApplicationContext` is re-pinned post-merge so a framework-managed key cannot be clobbered. Within-extension precedence is unchanged.
- **Blob uploads now persist the actual effective storage disk.** `FileUploader` now prefers its explicit `storageDriver` when recording `blobs.storage_type`, so per-request or manually constructed uploaders no longer store the configured `uploads.disk` when the file was written to a different disk.

### Upgrade Notes
- **Cloud storage drivers need their provider pack.** If any disk uses `driver: s3` (including R2/MinIO/Spaces/Wasabi setups), `composer require glueful/storage-s3` after updating — its presets cover the S3-compatible providers. `gcs`/`azure` users should hold this upgrade until `glueful/storage-gcs` / `glueful/storage-azure` publish (following shortly). Apps on `local`/`memory` need nothing.
- **Refresh the command manifest on deploy.** This release adds `storage:test`; a `storage/cache/glueful_commands_manifest.php` generated before the upgrade will not know it. Run `php glueful commands:cache` as part of the deploy (`cache:clear` does **not** refresh the command manifest).
- **Regenerate the precompiled container on deploy.** The extension-over-core precedence fix only takes effect once the compiled container artifact is rebuilt — a container compiled before 1.54.0 still encodes the old (core-wins) merge. Run `php glueful di:container:compile --force` (or delete the cached `glueful_compiled_container.php` artifact) as part of the deploy.
- **Extension authors: your container definitions now actually override core defaults.** Binding the same service id as a core provider previously lost silently; it now wins (last-layer-wins). This is what makes `UserProviderInterface`, `EntitlementCheckerInterface`, the storage factories, and every other "core default + extension override" seam real. Audit any extension that unintentionally reuses a core service id.
- **Optional env:** `UPLOADS_NATIVE_MAX_PRIVATE_TTL` (default `900`) caps the TTL of native provider URLs for `private` blobs. The whole `native_url` feature is **default-off** per disk — no action unless you opt a disk in via `uploads.native_urls.disks`.
- No core migrations; no required env changes.

## [1.53.0] - 2026-06-08 — Nunki

### Added
- **Pre-execution query interceptors (generic DB seam).** `Glueful\Database\Execution\QueryExecutor::addQueryInterceptor()` / `addQueryInterceptorCallback()` / `clearQueryInterceptors()` register **chainable** hooks (implementing `Glueful\Database\Execution\QueryInterceptorInterface`) that run in `executeStatement()` **before** a statement is prepared/executed; throwing from a hook **prevents** the query. All registered interceptors run in registration order (no last-writer-wins). Unlike the existing post-execution query log/event (observation only), this can *veto* a query — a general extension point for query-level enforcement: access/scope guards, read-only modes, SQL allow/deny policies, audit vetoes, and row-level multi-tenancy. No-op when none are registered (zero behavior change on a plain install).
- **`Connection::table()` decorator hooks (generic DB seam).** `Glueful\Database\Connection::addTableHook()` / `clearTableHooks()` register **chainable** decorators applied to the `QueryBuilder` returned by `Connection::table()`, each receiving `(QueryBuilder $qb, string $table, Connection $conn)` and running in registration order. A general seam for auto-applying scopes/conditions/columns to *raw* query-builder access keyed by table (e.g. raw-level soft-deletes, org/tenant scoping, environment filtering). No-op when none are registered. *(Both seams land first as the prerequisite for the forthcoming `glueful/tenancy` extension, but are intentionally generic core extension points.)*

### Fixed
- **`SecureSerializer` namespace-wildcard allowlist is now functional (queue job deserialization).** Wildcard allowlist entries such as `'Glueful\Queue\Jobs\*'` (seeded by `SecureSerializer::forQueue()`) were previously treated as literal class-name strings, never as patterns: the application gate (`validateSerializedClasses()`/`isClassAllowed()`) only did exact matching, so a real `Glueful\Queue\Jobs\Foo` was **wrongly rejected**, and the native gate passed the literal `'…\*'` string straight into `unserialize(..., ['allowed_classes' => …])`, which PHP cannot satisfy (yielding a `ValueError` — or, with the older code path, an `__PHP_Incomplete_Class`). This broke worker-side `Job::unserialize()` for the very job classes `forQueue()` was meant to permit. `isClassAllowed()` now honors prefix-wildcard entries (a trailing `\*`/`*` is a namespace prefix matched via `str_starts_with`) while keeping exact-match and the safe framework/extension prefixes; the native `unserialize` call now receives a **concrete, validated** allow list resolved from the actual class tokens in the blob (wildcards are never passed to `allowed_classes`, and it never falls back to `true`), so native protection is unchanged and disallowed classes still throw `InvalidArgumentException`.
- **`SecureSerializer` now validates `C:` (Serializable) classes.** Serialized-class extraction previously matched only `O:NN:"Class"` tokens; `C:NN:"Class"` (PHP `Serializable`) tokens were neither extracted nor allowlist-checked. Both forms are now extracted and subjected to the same `isClassAllowed()` enforcement (and included when resolving the concrete native allow list).
- **Table-qualified WHERE columns now work on `UPDATE`/`DELETE` (not just `SELECT`).** `WhereClause::getConditionsArray()` parses the built WHERE SQL back into `column => value` pairs that `QueryValidator::validateUpdate()`/`validateDelete()` check and the update/delete builders rebuild from. For a driver-wrapped **qualified** identifier (e.g. `` `t`.`col` `` / `"t"."col"`), a single outer `trim()` left the wrap characters around the table separator intact, so after splitting on `.` the bare column still carried a stray quote (e.g. `"col`). The column validator's SQL-injection check then rejected that quote, throwing `InvalidArgumentException` **before the write ran** — so `->where('t.col', $v)->update([...])` / `->delete()` always failed (an unqualified `->where('col', $v)` worked, since it has no separator). The last dotted part is now re-stripped of wrap characters in all four parse branches (basic / null / raw-null / raw-comparison), yielding a clean unqualified column for both validation and the rebuilt WHERE. SELECT was unaffected (it validates the column when added, before wrapping). *(Surfaced while building `glueful/tenancy`: a global scope that ANDed a qualified `tenant_uuid` predicate made every scoped bulk update/delete — including the owning tenant's own — throw.)*
- **`Connection::class` now resolves from the container (the `db()` helper works out of the box).** `CoreProvider` registered the connection only under the string id `'database'` and aliased `QueryBuilder::class` / `SchemaBuilderInterface::class` to it, but **not** `Glueful\Database\Connection::class` — so the documented accessors `db($ctx)` and `app($ctx, Connection::class)` (both of which resolve the concrete class id) threw `Service 'Glueful\Database\Connection' not found` in a real app. Added the missing alias: `Connection::class` now resolves to the same shared `'database'` instance (mirroring the sibling `QueryBuilder`/`SchemaBuilder` aliases), no re-instantiation. *(Surfaced when a `glueful/tenancy` console command called `db($ctx)` in a real api-skeleton; in-memory test harnesses had bound `Connection::class` themselves, masking the gap.)*

---

## [1.52.0] - 2026-06-07 — Mizar

> **Theme: Lean core — four subsystems extracted to optional extensions.** A coordinated breaking release that moves **Archive**, **CDN / edge-cache**, **queue operations** (supervision / autoscaling / worker-metrics), and **rich media** (image processing / thumbnails / metadata) out of framework core into standalone `glueful/*` extensions, each behind a narrow core seam core consumes only if bound. A plain core install boots, serves uploads, runs a lean single-worker `queue:work`, and caches responses with **zero** of these subsystems' heavy dependencies present (no `intervention/image`, `james-heinrich/getid3`, and no GD/Imagick required). Every subsystem is restored with a single `composer require`. Staying in 1.x per the pre-public breaking-changes policy — see **Upgrade Notes** and `UPGRADE.md` for full per-subsystem migration guidance.

### Breaking Changes
- **CDN / edge-cache subsystem extracted to the `glueful/cdn` extension.** Edge purging and edge cache-control headers now require `composer require glueful/cdn` (auto-discovered via `extra.glueful`). The duplicated edge/CDN code was removed from core: `Glueful\Cache\EdgeCacheService` (→ `Glueful\Extensions\Cdn\EdgeCachePurger`, which now implements the retained core contract `Glueful\Cache\Contracts\EdgeCacheInterface`); `Glueful\Cache\CDN\CDNAdapterInterface` and `Glueful\Cache\CDN\AbstractCDNAdapter` (→ `Glueful\Extensions\Cdn\Adapters\*`); the `cache:purge` console command (`Glueful\Console\Commands\Cache\PurgeCommand`); and the dead `Glueful\Helpers\CDNAdapterManager` trait. The `EdgeCacheService` container binding was removed — core still binds `EdgeCacheInterface` to the no-op `Glueful\Cache\NullEdgeCache`, so response caching keeps working (it still emits surrogate keys) and resolving the interface always succeeds. The `cache.edge` config block was removed; its settings move to the extension's `cdn` config key (the `EDGE_CACHE_*` env vars are now read only by the extension). Without the extension, `php glueful cache:purge` is absent and edge purges/headers are silent no-ops. See `UPGRADE.md`.
- **Archive subsystem extracted to the `glueful/archive` extension.** All archive code and schema were removed from core: `src/Services/Archive` (service, `ArchiveServiceInterface`, `ArchiveHealthChecker`, DTOs, and `ServiceProvider/ArchiveProvider`), the `archive:manage` console command (`src/Console/Commands/Archive`), `migrations/archive`, `config/archive.php`, and the `archive` capability (the `capabilities.archive` / `ARCHIVE_DATABASE_SCHEMA` gate). The `ArchiveProvider` is no longer registered in the container. Apps that use archiving must now `composer require glueful/archive` (auto-discovered via `extra.glueful`) and migrate imports `Glueful\Services\Archive\*` → `Glueful\Extensions\Archive\*`. See `UPGRADE.md`. Intentional fix carried into the extension: a configured `archive.storage.path` now actually takes effect — under core, the dead `archive.config` / `storage_path` config keys plus a missing `ApplicationContext` meant the configured storage path was silently never applied.
- **Queue ops (supervised fleets / autoscaling / worker metrics) command surface extracted to the `glueful/queue-ops` extension** *(part 1 of a staged breaking series — config relocates in a follow-up commit)*. Plain `php glueful queue:work` is now a **single lean worker**: the old `queue:work` sub-actions (`work` (multi/manager mode), `spawn`, `scale`, `status`, `stop`, `restart`, `health`) and the `queue:autoscale` command are **removed/absent** — invoking them is a generic command-not-found / unknown-argument error, with **no stub** printing an actionable message. The deleted core classes moved to the extension: `Glueful\Queue\Monitoring\WorkerMonitor` → `Glueful\Extensions\QueueOps\Monitoring\WorkerMonitor`; `Glueful\Queue\Process\*` (`ProcessManager`, `ProcessFactory`, `WorkerProcess`, `AutoScaler`, `ScheduledScaler`, `ResourceMonitor`, `StreamingMonitor`) → `Glueful\Extensions\QueueOps\Process\*`; `Glueful\Console\Commands\Queue\AutoScaleCommand` → the extension. Core retains the lean worker plus the `Glueful\Queue\Contracts\WorkerMonitorInterface` seam bound by default to the no-op `Glueful\Queue\Monitoring\NullWorkerMonitor`, so `queue:work` and `QueueMaintenance` keep working on a plain checkout. Supervised fleets, autoscaling, and worker/job metrics now require `composer require glueful/queue-ops` (auto-discovered via `extra.glueful`), which restores `queue:supervise` (supervisor + leaf workers) and `queue:autoscale`. Additive (already shipped this cycle, no action needed): new `queue:work --once` / `--connection=` flags, and `WorkerOptions` `max-jobs` / `max-runtime` now treat `0` as unlimited; `ServeCommand` still shells `queue:work --sleep=3` (now one lean worker). **Note:** this is **part 1 of a staged breaking series** — the ops **config keys** (`queue.workers.{process,auto_scaling,resource_limits,resource_thresholds,supervisor}` and per-queue `workers` / `max_workers` / `auto_scale`) relocate to the extension's `queue_ops.*` in a follow-up commit. See `UPGRADE.md`.
- **Queue ops config relocated from core `config/queue.php` to the `glueful/queue-ops` extension (`queue_ops.*`)** *(part 2, final, of the staged breaking series — commands removed in part 1)*. The worker-management blocks `queue.workers.{process,auto_scaling,resource_limits,resource_thresholds,supervisor}` and the per-queue ops keys `queue.workers.queues.<name>.{workers,max_workers,auto_scale}` were **removed from core** and now live under `queue_ops.*` in the extension (provided via the extension's `config/queue_ops.php` + `mergeConfig`). Read remappings: `config('queue.workers.process.*')` → `config('queue_ops.process.*')`, `…auto_scaling` → `queue_ops.auto_scaling`, `…resource_limits` → `queue_ops.resource_limits`, `…resource_thresholds` → `queue_ops.resource_thresholds`, `…supervisor` → `queue_ops.supervisor`, and `config('queue.workers.queues.<name>.workers|max_workers|auto_scale')` → `config('queue_ops.queues.<name>.*')`. **Stays in core:** per-queue `priority` / `memory_limit` / `timeout` / `max_jobs`, `queue.workers.performance.*` (read by the lean `QueueWorker`), and `queue.monitoring.*`. The feeding env vars are **unchanged** (e.g. `QUEUE_PROCESS_ENABLED`, `QUEUE_AUTO_SCALING`, `CRITICAL_QUEUE_WORKERS`, `QUEUE_MEMORY_WARNING`) — they are simply read by `glueful/queue-ops` now, so no `.env` changes are required. See `UPGRADE.md`.
- **Rich media (image processing, thumbnail generation, media metadata) extracted to the `glueful/media` extension.** The two heavy deps `intervention/image` and `james-heinrich/getid3` were **removed from core** and now ship with the extension. The moved classes (namespace map): `Glueful\Services\ImageProcessor` → `Glueful\Extensions\Media\ImageProcessor`; `Glueful\Services\ImageProcessorInterface` → `Glueful\Extensions\Media\Contracts\ImageProcessorInterface`; `Glueful\Uploader\ThumbnailGenerator` → `Glueful\Extensions\Media\ThumbnailGenerator`; `Glueful\Uploader\MediaMetadataExtractor` → `Glueful\Extensions\Media\MediaMetadataExtractor`. **`Glueful\Uploader\MediaMetadata` is unchanged and stays in core.** The global `image()` helper is now **extension-provided** — on a plain core install it is **undefined** (function-not-found, not a stub). `FileUploader::getThumbnailGenerator()` / `getMetadataExtractor()` were removed. `IMAGE_*` env vars and `config/image.php` are now **extension-owned**; the `UPLOADS_THUMBNAILS` / `UPLOADS_IMAGE_PROCESSING` / `THUMBNAIL_*` config keys **stay in core** (`config/uploads.php` / `config/filesystem.php`) but are **inert** (media-gated no-ops) until `glueful/media` is installed. Without the extension: `uploadMedia()` returns `thumb_url: null` plus a type-only `MediaMetadata`, and the blob-resize endpoint serves the **original** image (returning `415` only when an explicit format conversion is requested). Restore full rich-media support with `composer require glueful/media` (auto-discovered via `extra.glueful`). See `UPGRADE.md`.

### Upgrade Notes
- **Restore any extracted subsystem with one `composer require`** (each auto-discovers via `extra.glueful`); enable it in the app's `config/extensions.php` if your app gates extensions there:
  - `composer require glueful/archive` — archiving + the `archive:manage` command + the `ARCHIVE_DATABASE_SCHEMA` gate (run `php glueful migrate:run`).
  - `composer require glueful/cdn` — edge purging + cache-control headers + `cache:purge`; move any `cache.edge` overrides to the extension's `cdn` config.
  - `composer require glueful/queue-ops` — `queue:supervise` (supervisor + leaf workers) + `queue:autoscale` + worker/job metrics; the `queue.workers.*` ops blocks move to `queue_ops.*` (same env vars, no `.env` changes).
  - `composer require glueful/media` — image processing / thumbnails / metadata + the `image()` helper; republishes `config/image.php`.
- **Refresh the production command manifest on deploy.** This release removes the core `archive:manage`, `cache:purge`, and `queue:autoscale` commands; a `storage/cache/glueful_commands_manifest.php` generated before the upgrade still references them and breaks CLI boot. Run `php glueful commands:cache --clear` as part of the deploy — `php glueful cache:clear` does **not** clear the command manifest. (See `UPGRADE.md` → *Command cache*.)
- **No-extension behavior is graceful, not fatal** (the seams are bound to no-op/defaults): response caching still emits surrogate keys (`NullEdgeCache`); `queue:work` runs as one lean worker; `uploadMedia()` returns `thumb_url: null` + type-only `MediaMetadata` and the blob-resize endpoint serves the original (415 only on explicit format conversion); archiving is simply unavailable. The removed `image()` helper and `queue:autoscale`/`queue:work` sub-actions are *absent* (function/command-not-found), not error-printing stubs.
- **Queue ops is a two-stage breaking move** (within this release): the commands/classes and the `queue.workers.*` ops config both relocate to `glueful/queue-ops`. Core keeps per-queue `priority`/`memory_limit`/`timeout`/`max_jobs`, `queue.workers.performance.*`, and `queue.monitoring.*`. The additive `queue:work --once` / `--connection=` flags and `WorkerOptions` `0 = unlimited` ship in core regardless.
- No new framework env vars; no core migrations. The four extensions own their own schema/config. Full namespace maps and per-subsystem steps are in `UPGRADE.md`.

---

## [1.51.0] - 2026-06-06 — Larawag

> **Theme: Notification subsystem refinement.** A five-part overhaul of core notifications — a real in-app `database` channel with dispatch-time channel validation; optional, safe persistence (`NOTIFICATIONS_DATABASE_STORE=false`); an injectable async-queue seam; structured channel results (`NotificationResult`/`RichNotificationChannel`); and extension-driven channel registration. Mostly additive, with two deliberate breaking changes in channel registration/dispatch — see **Upgrade Notes**.

### Added
- **Core `database` notification channel.** `Glueful\Notifications\Channels\DatabaseChannel` is registered by default in `NotificationsProvider`, so the framework's default `['database']` channel resolves end-to-end instead of failing as `channel_not_found`. It is an in-app *acknowledge* channel — it performs no writes of its own (the notification and its delivery records are owned by `NotificationService`); its availability tracks the `notifications` persistence capability, so it reports unavailable (and never success) when persistence is off. Part 1 of the notification-subsystem refinement (see `docs/superpowers/plans/2026-06-06-notification-subsystem-refinement.md`).
- **Extension-driven notification channel registration.** Channels and `NotificationExtension` hooks now register through an extension's `ServiceProvider::boot()` via new `registerNotificationChannel()` / `registerNotificationExtension()` helpers, into the shared container `ChannelManager`/dispatcher — one registration path, no per-job glue. `ChannelManager::registerChannel()` is idempotent for the same class and throws `ChannelAlreadyRegisteredException` when a *different* class claims a registered name; `replaceChannel()` overrides intentionally. Part 5 (final) of the notification-subsystem refinement.
- **Structured notification channel results (`NotificationResult` + `RichNotificationChannel`).** Channels may now opt into a richer `sendNotification(): NotificationResult` (provider message id, error code/message, retryability, latency) via the new `RichNotificationChannel` interface; the dispatcher prefers it and falls back to adapting legacy `send(): bool` results, so `NotificationChannel::send()` is unchanged. Success/failure now surface `provider_message_id` / `reason` + `message` per channel. Also fixes `SendNotification` parsing a failed non-email send against the wrong channel (`parseEmailResult` → channel-correct `parse()`). Additive; no API breaks. Part 4 of the notification-subsystem refinement.
- **Injectable notification queue dispatch.** New `NotificationQueueDispatcherInterface` (default `QueueManagerNotificationDispatcher`) abstracts async dispatch; `NotificationService` accepts an optional dispatcher and delegates to it instead of constructing a `QueueManager` inline (the inline construction is kept as a fallback when none is injected). Keeps queueing optional and unit-testable — `send()` never requires a queue. Additive; no API breaks. Part 3 of the notification-subsystem refinement.
- **Notification persistence is now optional and safe (`NOTIFICATIONS_DATABASE_STORE=false`).** New `NotificationStoreInterface` abstracts the store; `NotificationRepository` implements it (so existing callers are unaffected), and a `NullNotificationStore` is bound instead when the `notifications` capability is off. The null store degrades explicitly: reads return empty/null/zero; transient writes (`save`, delivery records) no-op; durability-implying operations (`savePreference`, `markAllAsRead`, `deleteOldNotifications`, scheduling, `dispatchStoredNotification`, retries) throw `NotificationPersistenceDisabledException` instead of hitting a gated table or silently losing state. `NotificationService` gains `getStore()`; `getRepository()` is retained (throws when persistence is disabled). Part 2 of the notification-subsystem refinement.

### Changed
- **(Breaking) Notification channel registry rename + context-required dispatch.** `ChannelManager::getAvailableChannels()` → `getRegisteredChannelNames()` (and new `getActiveChannelNames()`), with all framework call sites updated and **no aliases**. Notification jobs/commands/tasks (`DispatchNotificationChannels`, `SendNotification`, `ProcessRetriesCommand`, `NotificationRetryTask`) now resolve the shared container dispatcher and **require an `ApplicationContext`** — throwing `NotificationContextRequiredException` instead of building ad-hoc managers or hardcoding the `EmailNotification` provider. The retry config key moved from the `emailnotification` namespace to channel-agnostic `notifications.retry`. (Extension packages register their own channels via `boot()`; async email auto-wires once the extension migrates to that path.) See **Upgrade Notes**.
- **Notification channel validation moved from construction to dispatch.** `NotificationService` no longer rejects `default_channels` against a hardcoded `['email','sms','database','slack','webhook','push']` list at construction; channel names are now only normalized structurally (trimmed, de-duplicated, non-empty — **case preserved**). Unknown channels are surfaced at dispatch via the dispatcher's existing `channel_not_found` / `channel_unavailable`, giving a single source of truth (the `ChannelManager` registry) and letting custom channels work without core changes. No env vars, no migrations, no API breaks.
- **Notification services depend on the store seam, not a concrete repository.** `NotificationService` and `NotificationRetryService` now type-hint `NotificationStoreInterface` (widened from `NotificationRepository` — compat-preserving since the repo implements it). The background dispatch/retry entry points (`NotificationRetryTask`, `NotificationRetryJob`, `DispatchNotificationChannels`, `ProcessRetriesCommand`) resolve the capability-aware store from the container instead of hardcoding `new NotificationRepository()`, so they honor `NOTIFICATIONS_DATABASE_STORE`. The shipped capability default stays `true`; no API breaks.

### Upgrade Notes
- **`ChannelManager` channel-name methods were renamed (no aliases).** Replace `getAvailableChannels()` with `getRegisteredChannelNames()`; for only the currently-available channels' names, use the new `getActiveChannelNames()`. `getActiveChannels()` (returning channel objects) is unchanged.
- **Notification jobs/commands now require an `ApplicationContext`.** `DispatchNotificationChannels`, `SendNotification`, `ProcessRetriesCommand`, and `NotificationRetryTask` resolve the shared container dispatcher and throw `NotificationContextRequiredException` if constructed without a context — they no longer build ad-hoc managers. Ensure these run with a booted application context (the queue worker and console kernel already provide one).
- **The framework no longer hardcodes the `EmailNotification` provider.** Channel packages must register their channel/hooks from their `ServiceProvider::boot()` via the new `registerNotificationChannel()` / `registerNotificationExtension()` helpers. Until a given channel extension adopts this, its channel won't auto-wire into the shared dispatcher used by the async jobs. (Most apps need no change; this only affects custom or not-yet-migrated channel packages.)
- **Retry config key:** if you set notification retry options under the `emailnotification` config namespace, move them to the channel-agnostic `notifications.retry` key (the service falls back to its built-in defaults otherwise).
- No new env vars, no migrations. The `notifications` capability default stays `true`.

---

## [1.50.2] - 2026-06-05 — Kochab

> **Theme: `@queryParam` route-doc tag.** Route docblocks can now document query parameters with an editor-clean `@queryParam name:type="…"` tag that the OpenAPI generator actually parses — avoiding the IDE/Intelephense false positives (P1133) caused by overloading the reserved `@param` tag — and a latent doc-gen bug that dropped path params from any route which *also* declared a parameter is fixed. Framework-only — no env vars, no migrations, no API breaks. The api-skeleton `^1.50.1` constraint already permits 1.50.2.

### Added
- **`@queryParam` route doc tag.** `CommentsDocGenerator` now parses `@queryParam <name>:<type>="description" [{required}]` in route docblocks, emitting an `in: query` OpenAPI parameter. It's an editor-clean alternative to the positional `@param <name> query <type> <bool> "desc"` form — the reserved `@param` tag makes IDEs/Intelephense mis-read the location/type tokens as undefined PHPDoc types (P1133). The legacy `@param` form still parses unchanged.

### Fixed
- **Route path parameters are no longer dropped when a query parameter is also documented.** `CommentsDocGenerator` previously auto-derived `{name}` path params from the URL *only* when no parameters were documented at all, so a route declaring a query param plus a `{id}` in its path silently lost the path param from its OpenAPI spec. Path params are now always derived from the URL and merged with documented params (de-duplicated by name; an explicit path-param docblock still wins). Pinned by `tests/Unit/Support/Documentation/CommentsDocGeneratorParamTest.php`.

### Changed
- **`routes/resource.php` migrated to `@queryParam`.** The `/data/{table}` list endpoint's `page`/`limit`/`sort`/`order` query parameters now use the `@queryParam` tag (so they actually appear in the generated OpenAPI — the previous `@parameter` tag was never parsed), and the redundant `@parameter` path-param docblocks were removed (path params auto-derive from the route URL). No runtime change.

---

## [1.50.1] - 2026-06-05 — Kochab

> **Theme: Two silent no-op extension points, fixed.** `ServiceProvider::mergeConfig()` now actually applies an extension's config defaults (it delegated to a service that was never registered), and `LoginResponseBuildingEvent` listeners can now actually modify the login response (the shaper discarded their changes). Framework-only — no new env vars, no migrations, no API breaks. The existing api-skeleton `^1.50.0` constraint already permits 1.50.1.

### Fixed
- **`ServiceProvider::mergeConfig()` now actually merges extension config defaults.** It delegated to a `config.manager` container service that was never registered, so the call was a **silent no-op** for every extension — an extension's `config/*.php` defaults never reached `config()` unless the *app* shipped its own copy (affected `glueful/aegis`, `conversa`, `email-notification`, `entrada`, `meilisearch`, `notiva`, `payvia`, `runiva`). `mergeConfig()` now delegates to the new `ApplicationContext::mergeConfigDefaults()`, which merges defaults **under** framework/app/env config files (file/app values still win) and persists across `clearConfigCache()`. Extension config defaults now apply as authored. Pinned by `tests/Unit/ConfigDefaultsMergeTest.php`. **Behavior note:** enabled extensions that previously ran on empty/hardcoded fallbacks will now receive their declared config defaults.
- **`LoginResponseBuildingEvent` listeners now actually affect the login response.** `LoginResponseShaper::shape()` dispatched the event (whose documented purpose is to let listeners add fields to the response `user`/body via `setResponse()`/`mergeResponse()`) but then returned the original `$session`, discarding any mutations — the extension point was a silent no-op. The shaper now reads `$event->getResponse()` back before returning, so a registered listener can add fields (e.g. organization/department context) to the login response. Pinned by `tests/Unit/Auth/LoginResponseShaperTest.php`.

### Upgrade Notes
- **`composer update glueful/framework` is sufficient** — no env vars, no migrations, no API changes; the api-skeleton `^1.50.0` constraint already permits 1.50.1.
- **Behavioral note (config defaults).** If your app enables first-party extensions (`glueful/aegis`, `conversa`, `email-notification`, `entrada`, `meilisearch`, `notiva`, `payvia`, `runiva`), their shipped `config/*.php` defaults now **actually apply** — previously `mergeConfig()` was a silent no-op, so those subsystems ran on empty/hardcoded fallbacks. Your own `config/*.php` still overrides them (app values win). Review those extensions' defaults if you relied on the prior behavior.

---

## [1.50.0] - 2026-06-04 — Kochab

> **Theme: Provider-agnostic identity & core-owned schema.** The concrete user store is extracted to the first-party `glueful/users` extension behind `UserProviderInterface`/`UserIdentity`, leaving a lean, swappable core that's safe-by-default. The framework now **owns the schema for its own subsystems** — the auth security spine plus DB-backed platform capabilities (queue, scheduler, notifications, metrics, locks, uploads, archive) — as first-class, config-gated, source-tracked migrations, replacing lazy runtime DDL. Also: a declarative permission catalog with drift/sync tooling, ordered package-scoped migrations, and column-aware soft-delete. **Breaking (pre-release):** apps must enable a user store — see the Removed section and `docs/IDENTITY.md`.

### Added
- **Declarative permission catalog.** Service providers (framework core, app, extensions) declare permissions/roles via `Permission`/`Role` builder DTOs and the optional `ServiceProvider::permissions()`/`roles()` hooks. A fail-fast `ExtensionManager::aggregatePermissionCatalog()` pass aggregates them into a shared `PermissionRegistry` (collision + dangling-grant validation), runnable idempotently via `PermissionRegistry::reset()`.
- `RegistryRoleVoter` so declared roles enforce in the no-provider fallback path; shared `RoleKey` canonicalization used by both enforcement and (future) drift tooling.
- `PermissionCatalogSyncInterface` + `SyncResult` so a provider can persist the catalog; `permissions:sync` CLI command (self-aggregating, deterministic, idempotent).

- **Permission catalog visibility.** `permissions:list` (declared catalog grouped by category) and `permissions:diff` (drift between declared, enforced via route-attribute scanning, and persisted/managed — for permissions *and* roles, with unmanaged/hand-created rows reported informationally and never pruned). `permissions:sync --prune` removes stale managed permissions and roles, capability-guarded and failing loudly if the provider can't prune.
- `PermissionRegistry` introspection (`sourceOf`, `permissionSlugs`, `roleSlugs`, `permissionsByCategory`), `PermissionAttributeScanner`, and two opt-in capability interfaces — `CatalogPruneInterface`, `RoleCatalogSyncInterface` — so prune/role support is additive and never breaks existing providers.
- **Permission ergonomics.** Service providers can declare Gate voters and resource policies via `ServiceProvider::voters()`/`policies()` (registered onto the shared `Gate`/`PolicyRegistry` at boot). Testing helpers `actingWithPermissions()`/`actingWithRoles()` on `Glueful\Testing\TestCase` plus a reusable `Glueful\Testing\InMemoryPermissionProvider` (user-scoped) make authorization trivially testable.

- **Ordered, package-scoped migrations.** `MigrationPriority` tiers (`FOUNDATION`/`IDENTITY`/`DEFAULT`/`DEPENDENT`) and `ServiceProvider::loadMigrationsFrom($dir, $priority, $source)` let a foundational extension's migrations run before app/dependent ones. Pending migrations sort by `(priority, basename)`, and the `migrations` version table gains a `source` column so applied-state is tracked per package — two packages can ship the same filename without conflating, and rollback resolves/deletes by `(source, migration)`.
- **Identity seam (provider-agnostic).** A canonical, `final`, immutable `UserIdentity` (identity facts + open claims bag with typed `roles()`/`scopes()` + immutable `with*()`), a `UserProviderInterface` (lookup + credential verification) and `IdentityClaimsProviderInterface` (post-auth enrichment), a fail-closed `NullUserProvider` default binding, and an `IdentityResolver` that applies the account-status gate and folds registered claims providers (collected via the `identity.claims_provider` container tag) additively while re-pinning identity facts (a claims provider can change what a user can do, never who they are).
- **Core-owned security-spine schema.** The framework now ships its own foundation migrations for `auth_sessions`, `auth_refresh_tokens`, and `api_keys` — the tables its session/token/API-key code (`SessionStore`, `TokenManager`, `RefreshTokenStore`, `ApiKeyService`) reads/writes. They are auto-registered via a shared `MigrationManager` container factory at `FOUNDATION` priority (source `glueful/framework`), applied through the runner (not lazy runtime DDL). Principal references (`*.user_uuid`) are indexed UUIDs with no cross-package FK; the only retained FK is intra-core `auth_refresh_tokens.session_uuid → auth_sessions`.
- **`TwoFactorServiceInterface`** so an extension (e.g. `glueful/users`) can provide 2FA behind a core contract; `AuthController` resolves it optionally and skips 2FA entirely when no implementation is registered.
- **Core-owned platform-capability migrations.** The DB schema for core subsystems whose code lives in the framework — `blobs` (uploads), `queue_*`, `scheduled_jobs`/`job_executions`, the notification tables (incl. the previously runtime-only `notification_retry_queue`), `archive_*`, `locks`, and the API-metrics tables (`api_metrics`, `api_metrics_daily`, `api_rate_limits`) — now ships as first-class **core** migrations under `framework/migrations/<capability>/`, registered **conditionally on config** under per-capability sources (`glueful/framework:uploads|queue|scheduler|notifications|archive|locks|metrics`). Auth stays unconditional under `glueful/framework`. The factory registers explicit leaf subdirs only (the migration finder recurses), and the pending-migration sort gained a `source` tiebreaker (`priority, basename, source`) for deterministic ordering across same-named files. Explicit gates live in a single new `config/capabilities.php` (`scheduler`, `notifications`, `metrics` default on; `archive` opt-in/off); `locks`/`queue`/`uploads` derive from their own existing config (`lock.default`/`queue.default` driver, `uploads.enabled`) to avoid a second source of truth.

### Changed
- Attribute enforcement (`#[RequiresPermission]`/`#[RequiresRole]`) now routes through `PermissionManager::can()` (single enforcement entry point) instead of `Gate::decide()` directly; `GateAttributeMiddleware` is a thin adapter with a `'system'` resource default. `PermissionManager` gains `clearProvider()`.
- **Soft-delete is column-aware on writes.** `QueryBuilder::delete()` now soft-deletes only when soft-delete is enabled *and* the table has a `deleted_at` column (via `SoftDeleteHandler::appliesTo()`), mirroring the existing column-aware read path; tables without `deleted_at` get a real `DELETE` instead of an erroring/no-op `UPDATE`.
- **`AuthenticatedUser` removed; `UserIdentity` is the one runtime identity.** `RequestUserContext` and the controller user accessors now expose `UserIdentity` (method accessors `uuid()`/`email()`/`roles()`…), replacing the retired `AuthenticatedUser` (property accessors).
- **`api_keys.user_id` → `user_uuid`.** The column (and `ApiKey` model field, `ApiKeyService` create/rotate input, provider lookup) is renamed to match the identity-schema convention; it stays an indexed UUID with no FK (external principal id). The generic `user_id` *request attribute* (read by `SessionContext`) is unchanged. `RevokeTokensCommand` was rewritten onto `SessionStore::revoke()` (it referenced session token columns and `TokenManager` methods that no longer exist).

- **No runtime DDL for queue/scheduler/notifications/metrics.** `DatabaseQueue::ensureQueueTables()`, `JobScheduler::ensureTablesExist()`, `NotificationRetryService::ensureRetryQueueTableExists()`, and `ApiMetricsService::ensureTablesExist()` are removed — these subsystems no longer create tables lazily at request time; their schema is owned by the core capability migrations above (run `php glueful migrate:run`). `ApiMetricsService`'s now-unused `SchemaBuilderInterface` constructor dependency was dropped.

### Removed
- **The concrete user store is no longer in framework core.** `Glueful\Models\User`, `Glueful\Repository\UserRepository`, the in-core `UserProvider`, `Security\EmailVerification`, `TwoFactor\TwoFactorService`, `TwoFactorController`, and the account/2FA/password-reset CLI commands were extracted to the first-party **`glueful/users`** extension. Core keeps the security spine (session/token mechanics, providers, `ChallengeTokenIssuer`/`JtiBlocklist`, auth middleware) and depends only on the `UserProviderInterface`/`TwoFactorServiceInterface` contracts. No core file references `UserRepository` or `Glueful\Models\User`.

> **BREAKING (pre-release).** Applications must enable a user store — install + enable **`glueful/users`** (the api-skeleton does so by default). Without one, core binds the fail-closed `NullUserProvider` and authentication is disabled by design. The skeleton no longer ships `users`/`profiles`/`auth_sessions`/`auth_refresh_tokens`/`api_keys` migrations: `users`/`profiles` come from `glueful/users`, the rest are core foundation migrations.

---

## [1.49.1] - 2026-06-01 — Jishui

> **Theme: Reserved-word column names.** A focused bug fix: column names that are SQL reserved words (`from`, `order`, `group`, `key`, `values`, …) are now accepted by `QueryValidator` — they were always quoted by the query builders anyway, so the rejection blocked valid SQL. Framework-only; no API breaks, no env vars, no migrations.

### Fixed
- **`QueryValidator` no longer rejects reserved SQL words used as column names.** In strict mode, `validateColumnName()` previously threw `Column name '<x>' is a reserved SQL keyword` for columns like `from`, `order`, `group`, `key`, or `values` — even though the query builders always emit column identifiers through the driver's `wrapIdentifier()` (`` `from` `` / `"from"`), which is valid SQL. The reserved-word check is removed for *column* names (the SQL-injection character check remains the guard); reserved-word checks for unquoted table/schema/alias names are unchanged. This also resolves a latent inconsistency where `to` was accepted only because `TO` happened to be absent from the keyword list.

---

## [1.49.0] - 2026-06-01 — Jishui

> **Theme: HTTP Auth, WhatsApp Plumbing & Dependency Hardening.** `Http\Client` now forwards per-request `auth_basic`; the notification queue learns the `whatsapp` type; image processing moves to Intervention Image v4; and all known dependency advisories are patched. No framework API breaks, no new env vars, no migrations; PHP 8.3 floor preserved.

### Added
- **`Http\Client` now passes `auth_basic` through to Symfony HttpClient.** Per-request HTTP Basic auth (`$client->post($url, ['auth_basic' => [$user, $pass], 'form_params' => [...]])`) previously had no effect — `Client::transformOptions()` mapped `headers/query/json/form_params/body` but silently dropped `auth_basic`. It is now forwarded natively, so callers no longer need to hand-build an `Authorization: Basic …` header.
- **`whatsapp` is now a supported `SendNotification` queue type.** Added to `SendNotification::SUPPORTED_TYPES` (with a matching timeout arm) so phone-messaging extensions registering a `whatsapp` notification channel can be delivered asynchronously through the framework's notification job. Additive; existing types are unchanged.

### Changed
- **Upgraded to Intervention Image v4** (`intervention/image: ^4.1`, was `^3.11`). `ImageProcessor` was ported to the v4 API — `read()`→`decode()`, `create()`→`createImage()->fill()`, `place()`→`insert()`, `flop()`/`flip()`→`flip(Direction::HORIZONTAL|VERTICAL)`, and `save()` now passes `quality` as a named option. Public `ImageProcessor`/`image()` API is unchanged.
- **Refreshed in-range dependencies** to their latest patch/minor: `league/flysystem` (+ `-local`, dev `-memory`), `james-heinrich/getid3`, `phpdocumentor/reflection-docblock`.
- **Pinned `symfony/event-dispatcher` and `symfony/string` to `^7.4`** so a transitive `composer update` can't pull the Symfony 8.x (PHP 8.4-only) lines and silently raise the PHP floor.

### Security
- **Patched all known dependency advisories** (within the existing `^7.4` / `^10.5` constraints): updated the Symfony components to `7.4.x` patch releases — fixing HIGH-severity email header / SMTP-command injection in `symfony/mailer`/`symfony/mime` (CVE-2026-45067/45070), `symfony/http-foundation` (CVE-2026-48736), and `symfony/polyfill-intl-idn` — and `phpunit/phpunit` to `10.5.63` (unsafe deserialization in code coverage, dev-only). `composer audit` is now clean. Symfony stays on the 7.x line (PHP 8.3 floor preserved).

### Upgrade Notes
- **`composer update` is sufficient** for the framework itself — no API changes, env vars, or migrations.
- **Intervention Image is now `^4`.** If your application code depends on `intervention/image` directly (not just via the framework's `ImageProcessor`/`image()` helper, which is unchanged), move it to the v4 API — see the [v4 upgrade guide](https://image.intervention.io/v4). Apps that only use Glueful's image facade need no changes.

## [1.48.0] - 2026-05-31 — Imai

> **Theme: Router Verb Completeness.** `PATCH` and `OPTIONS` become first-class routing verbs (they were previously unreachable through the public API), explicit `OPTIONS` routes now win over the automatic CORS preflight responder, and the route-precedence model is documented and pinned with tests. Purely additive — no breaking changes, no new env vars, no migrations.

### Added
- **`PATCH` and `OPTIONS` are now first-class HTTP verbs in the router.** New `$router->patch()` / `$router->options()` shortcuts and `#[Patch]` / `#[Options]` attributes, and the `#[Route(methods: [...])]` array form now accepts `PATCH`, `OPTIONS` and `HEAD` (previously it threw `InvalidArgumentException` for anything but GET/POST/PUT/DELETE). `PATCH` and `OPTIONS` were previously unreachable through the public routing API.
- **Explicit `OPTIONS` routes take precedence over automatic CORS preflight.** Dispatch still answers `OPTIONS` automatically (204 + `Allow`) when no `OPTIONS` route is registered for the path, but a route registered via `$router->options(...)` / `#[Options]` now runs its own handler instead of being shadowed.

### Documentation
- Documented the route-cache **closure limitation** (closure handlers are never cached; the router skips/discards the cache for them and resolves them live) and the expanded HTTP-method surface in `docs/content/2.essentials/1.routing.md`.
- Documented the **route-precedence model** (static beats dynamic; literal first segment beats a parameter first segment; within a first-segment group, registration order wins — register the more specific overlapping pattern first) in `docs/content/6.cookbook/1.routing.md`.

### Tests
- Added `tests/Unit/Routing/RoutePrecedenceTest.php` pinning all three precedence tiers, constraint-based fall-through, single-segment parameter matching, trailing-slash normalization, and method isolation (405 vs match). Corrected a misleading in-code comment in `Router::match()` that claimed a specificity sort the router does not perform.

---

## [1.47.0] - 2026-05-30 — Hadar

> **Theme: Extension System Re-Architecture.** Extension loading is rebuilt around a single mental model — **Composer discovers, one `enabled` list activates, a pure resolver orders and validates.** The four overlapping discovery sources, the multi-key config files, and the live-resolve/lazy-cache dev↔prod parity hazard are gone. Breaking change to `config/extensions.php` and `config/serviceproviders.php`; see `docs/EXTENSIONS_UPGRADE.md`.

### Added
- **Pure `ExtensionResolver`** (`src/Extensions/ExtensionResolver.php`): given Composer candidates + the `enabled` allow-list, it selects, validates (missing provider, missing dependency, framework-version mismatch via `composer/semver`, dependency cycle), and topologically orders providers, returning a `ResolverResult { providers, errors }`. It reads no environment and never throws, so dev and prod resolve identically.
- **`ExtensionCandidate`** value object and **`PackageManifest::getCandidates()`** capturing each installed `glueful-extension`'s provider FQCN and `extra.glueful.requires` (`glueful` constraint + `extensions` dependency list).
- **`ProviderClassResolver`** — the single resolution path (`[app providers] ++ [resolved extensions]`) shared by both `ExtensionManager` and `ContainerFactory`, so there is one implementation, not two that drift.
- **`AppProviderLoader`** — loads the application's own providers from the new single-key `serviceproviders.enabled` (always loaded; not gated by `extensions.enabled`).
- **`ExtensionStateWriter`** — the one safe writer for the `enabled` string list (idempotent add/remove, `--dry-run`/`--backup`); refuses to edit a non-trivial array (conditionals/function calls/`::class`/non-string entries) after stripping comments.
- **`EnabledProviders`** — the single place that reads + normalizes a config `enabled` list (filters non-strings, trims a leading backslash), used by the resolver, `AppProviderLoader`, and every extension CLI command so the list is interpreted identically everywhere.
- **`composer/semver`** added as an explicit framework dependency (used by the resolver for `requires.glueful` matching).
- **`docs/EXTENSIONS_UPGRADE.md`** — old→new config key mapping, install/enable/cache workflow, path-repository instructions, and the behavioral changes.

### Changed
- **`config/extensions.php` and `config/serviceproviders.php` are now single-key** (`enabled` = a flat list of plain string FQCNs, no `::class`). The `only` / `dev_only` / `disabled` / `local_path` / `scan_composer` keys are removed.
- **`extensions:enable` / `extensions:disable` validate before writing** — they dry-resolve the *proposed* list and refuse to write if it would introduce an error (e.g. enabling an extension whose dependency is not enabled, or disabling one another enabled extension depends on), so the config is never left in a broken state. Both edit the `enabled` list via `ExtensionStateWriter` and recompile the cache.
- **`extensions:cache` is strict** — it refuses to write the manifest if resolution reports any error.
- **`extensions:list` shows state** (`enabled ✓` / `available ○` / `enabled-but-missing ⚠`) and **folds in the old `why` command** (the state column is the reason). `extensions:info` shows package, provider, requirements, and state; `extensions:diagnose` surfaces resolver errors, the resolved load order, and (in production) whether the compiled cache is present.
- **Production must run `php glueful extensions:cache`** — boot now fails fast if the compiled manifest is missing in production instead of silently resolving live. Development still resolves live.
- **`create:extension` scaffolds a real Composer package** under `extensions/<slug>/` (type `glueful-extension`, `extra.glueful.provider`, PSR-4 autoload) and registers a Composer **path repository** in the app's `composer.json`. It prints the `composer require` + `extensions:enable` commands rather than running Composer itself.

### Fixed
- **Composer discovery reads `installed.json`, not `installed.php`.** Composer's optimized `installed.php` omits the `extra` field, so reading it for extension discovery found zero candidates (every enabled extension showed as "enabled-but-missing"). `PackageManifest` now prefers `installed.json` (which carries `extra.glueful`), falling back to `installed.php` only when the json is absent.

### Removed
- **`ProviderLocator`** (the 4-source discovery unifier), the local-folder extension scan, and the runtime PSR-4 registration path. **`extensions:why`** (folded into `extensions:list`). Installing an extension no longer auto-loads it — it must be enabled.

### Upgrade Notes
- **Breaking config change (called out per the pre-public minor policy).** Convert `config/extensions.php` and `config/serviceproviders.php` to the single `enabled` string-FQCN list; map old keys per `docs/EXTENSIONS_UPGRADE.md` (`only`→`enabled`, `disabled`→omit, `dev_only`→`enabled` + `require-dev`, `local_path`→Composer path repo, `scan_composer`→removed). Enable extensions explicitly with `php glueful extensions:enable <name>`, and add `php glueful extensions:cache` to your production deploy step.
- **New dependency — run `composer update`.** This release adds `composer/semver` to the framework's `require`; consumers must `composer update glueful/framework` so it lands in their `vendor/` (the resolver fatals at boot without it). Entries in `enabled` must be **plain string FQCNs** (no `::class`); a stray `::class` literal shows as `enabled-but-missing` in `extensions:list`.
- **Version constraint.** `glueful/api-skeleton` is bumped to `^1.47.0`. Because this breaking change ships as a minor, apps pinned to `^1.46.0` will resolve 1.47.0 — review `docs/EXTENSIONS_UPGRADE.md` before updating.

```bash
composer update glueful/framework
php glueful extensions:cache   # required in production
```

---

## [1.46.0] - 2026-05-28 — Gienah

> **Theme: Fluent Query Caching.** `QueryBuilder::cache(ttl, tags)` is now wired through to `QueryCacheService` — the method was previously a silent no-op. Also marks the start of the framework-wide PHPStan level-8 hardening initiative, beginning with the query-binding path.

### Added
- **Fluent query result caching — `QueryBuilder::cache(?int $ttl = null, array $tags = [])`**: The fluent cache method is now wired through `QueryExecutor` to `QueryCacheService` for read queries (`get`/`first`/`count`/`max`). Results are tagged automatically by the tables involved plus any caller-supplied `$tags`, so they can be invalidated targetedly (e.g. `$cache->invalidateTags(['users'])`) in addition to the automatic per-table invalidation. Caching activates per-query when `->cache()` is called (no global toggle required); the executor lazily resolves a cache backend and degrades to uncached execution if none is configured.

### Changed
- **Began framework-wide PHPStan level-8 hardening.** Internal typing fixes in the query-binding path (`ParameterBinder`/`QueryExecutor`) — behavior-preserving. The full level-8 gap (~914 errors across `src/`) is catalogued in `docs/LEVEL8_TYPING_DEBT.md`; the CI gate remains level 6. No user-facing change.

### Fixed
- **`QueryBuilder::cache()` was a no-op**: it set builder-local flags that `get()` never propagated to the executor, so per-query caching and the TTL were silently ignored, and there was no `tags` parameter. The method now actually caches and accepts invalidation tags.

### Upgrade Notes
- **No action required.** `selectRaw`/query-builder behavior is unchanged unless you opt into `->cache(...)`. Framework-only release — no migrations, no env vars, no api-skeleton changes (the existing `^1.45` constraint already permits 1.46).

```bash
composer update glueful/framework
```

---

## [1.45.0] - 2026-05-27 — Fomalhaut

> **Theme: The Second Factor.** Baseline email-PIN two-factor authentication ships in framework core — opt-in, off by default, and byte-for-byte identical on the wire to a normal login once completed. Alongside it: parameterized `selectRaw()` bindings close the last unsafe-by-design gap in the query builder, a new `docs/SECURITY.md` documents the SQL-injection and XSS model, and the admin permission middleware drops a dead MFA-token placeholder.

### Added

- **`QueryBuilder::selectRaw()` parameter bindings**: `selectRaw()` now accepts an optional second argument — `selectRaw(string $expression, array $bindings = []): static` — so dynamic values in a raw SELECT expression can be passed as positional `?` placeholders instead of being string-interpolated. This closes the one unsafe-by-design gap in the SELECT clause (previously `selectRaw()` took only a raw string with no way to bind a value). Bindings are stored on `QueryState` alongside the `RawExpression` they pair with, and `getBindings()` now returns them in true SQL clause order — `SELECT → JOIN → WHERE → HAVING` — so positional placeholders line up. The method is also declared on `QueryBuilderInterface`. Fully backward compatible: existing `selectRaw($expr)` calls pass `[]` and behave exactly as before. Bindings protect dynamic *values* only — not identifiers, operators, directions, function names, or SQL fragments.
- **`docs/SECURITY.md` — SQL injection & XSS guide**: New documentation covering the framework's actual defenses: parameterized queries and identifier validation/quoting (with the raw-method breakdown of which `*Raw()` methods accept bindings), `QueryValidator` strict mode, and the XSS model for a JSON API (responses served as `application/json`, `SecurityHeadersMiddleware`/CSP, `CSRFMiddleware`, and the limits of `strip_tags`-based sanitization). Includes a developer checklist.
- **Core email-PIN two-factor authentication (2FA)**: Baseline 2FA ships in framework core (richer TOTP/WebAuthn/recovery codes remain `glueful/mfa` scope). Opt-in and **off by default** (`TWO_FACTOR_ENABLED=false`) — a fresh install behaves exactly like a pre-2FA framework, and the `/2fa/*` routes are not even registered when off. When enabled, `POST /auth/login` for an enrolled user returns a `challenge_token` and emails a 6-digit PIN (bcrypt-hashed via `Glueful\Security\OTP`, cached under `2fa:pin:{jti}` with a strictly-projected user array — no password hash can leak); the client completes login at `POST /2fa/verify`. New `src/Auth/TwoFactor/` services (`TwoFactorService`, `ChallengeTokenIssuer`, `JtiBlocklist`), `TwoFactorController` (`/2fa/enable|verify|disable`, IP-rate-limited), `2fa:enable|disable|status` CLI commands, and a `config/auth.php` `two_factor` block. `/2fa/verify` re-validates the account (existence, status allowlist, 2FA-still-enabled) before minting a session via `TokenManager::createUserSession`, and writes a **session-scoped** freshness marker so `/2fa/disable` can't be ridden by a stolen token from another session. Requires `glueful/email-notification` (email channel) and the `010_AddTwoFactorEnabledToUsers` migration. See the implementation plan in `docs/superpowers/plans/2026-05-22-core-email-2fa.md`.

### Changed

- **`AuthenticationService::authenticate()` username/password branch split into `verifyCredentials()` + `issueSession()`**: To expose a "verified user, no session yet" state for the 2FA gate, the username/password flow is split — `verifyCredentials()` runs the unchanged find-user + status-allowlist + password-verify + formatting chain (no session), and `issueSession()` calls `TokenManager::createUserSession()`. `authenticate()` keeps its provider short-circuit (token / API-key) verbatim and now falls back to the two new methods for username/password, preserving the public contract for all four flows (JWT, LDAP, SAML, API key). `AuthController::login()` uses the split to insert the 2FA branch and now routes all login responses through the new `LoginResponseShaper` (shared CSRF + login-event shaping), so a 2FA-completed login is on-the-wire identical to a direct login.

- **`AdminPermissionMiddleware::checkMfaAuth()` consolidated on the session MFA handshake**: The `require_mfa` check now reads only the session-based handshake (`mfa_verified` + `mfa_verified_at`), valid for a named `MFA_FRESHNESS_SECONDS` (300s) window, and documents that framework core ships no MFA verifier — the TOTP/SMS/WebAuthn challenge flow belongs to an MFA extension (`glueful/mfa`) or app-level code. Stateless (sessionless) requests cannot satisfy `require_mfa`.

### Removed

- **Dead `validateMfaToken()` / `X-MFA-Token` path in `AdminPermissionMiddleware`**: Removed the `validateMfaToken()` placeholder (which always returned `false`) and the `X-MFA-Token` header branch that fed it. The header path advertised a token-validation capability the framework never implemented.

### Fixed

- **`QueryBuilder::clone()` rendered cloned columns from the original state**: `SelectBuilder::buildSelectClause()` built its column list from the builder's internal state reference rather than the passed `$state`, so a cloned `QueryBuilder` (which reuses the original `SelectBuilder` with a cloned `QueryState`) would render the original's SELECT columns while sourcing bindings from the clone — a latent placeholder/binding mismatch surfaced by the new `selectRaw()` bindings. `buildSelectClause()` now derives the column list from the passed `$state`; behavior is unchanged for non-cloned queries (the sole caller passes its own state). `select()` also now clears any prior `selectRaw()` bindings, since it replaces the column list.
- **`selectRaw()` docblock referenced a non-existent `addBinding()` method**: The previous example showed `->addBinding($ageLimit)`, which does not exist anywhere in the codebase and would not compile. The docblock now documents the real `$bindings` parameter and a stale `@throws` line was removed.

### Upgrade Notes

- **Email 2FA is opt-in and off by default.** With `TWO_FACTOR_ENABLED=false` (the default) there is no behavioral change — the `/2fa/*` routes are not registered, the `two_factor_enabled` column is never read, and `POST /auth/login` responds exactly as before. To enable: (1) run the `010_AddTwoFactorEnabledToUsers` migration (ships in api-skeleton `^1.28.0`), (2) install `glueful/email-notification`, (3) set `TWO_FACTOR_ENABLED=true`, (4) enroll users via `POST /2fa/enable` or `php glueful 2fa:enable <uuid>`.
- **New env vars (all optional).** `TWO_FACTOR_ENABLED` (default `false`) plus tunables `TWO_FACTOR_PIN_LENGTH` (6), `TWO_FACTOR_PIN_TTL` (300), `TWO_FACTOR_CHALLENGE_TTL` (300), `TWO_FACTOR_DISABLE_FRESHNESS` (300), `TWO_FACTOR_TEMPLATE` (`two-factor-pin`). Defaults preserve current behavior.
- **`AdminPermissionMiddleware` dropped the `X-MFA-Token` header path.** The `require_mfa` check now reads only the session handshake (`mfa_verified` + `mfa_verified_at`). The removed header path always returned `false`, so no working flow relied on it.
- **`selectRaw()` bindings are backward compatible.** Existing `selectRaw($expr)` calls are unaffected; the second argument is optional.

```bash
composer update glueful/framework
```

---

## [1.44.0] - 2026-05-22 — Errai

> **Theme: Closing the Trust Gaps.** A focused follow-up to Dabih that reconciles four places where the README, CLI, or public API advertised behavior the code didn't deliver. The 1.43.0 "Production Hardening" release raised the framework's credibility surface, which made the remaining gaps more damaging, not less. This release closes them.

### Added

- **Real tag-aware cache invalidation on the Redis driver**: `RedisCacheDriver::addTags()` and `invalidateTags()` are now backed by Redis SETs (`_gf_tag:{tag}` → set of cache keys), with pipelined `SADD` on association and bulk `DEL` (keys + tag sets) on invalidation. The capability flag (`getCapabilities()['features']['tags']`) is now `true`. This unblocks `QueryCacheService`, `DistributedCacheService`, `ResponseCachingTrait`, and `php glueful cache:clear --tags` — all of which previously called `addTags()`/`invalidateTags()` only to receive a silent `false`.
- **`NON_STRICT_WHITELIST` security finding in `fields:whitelist-check`**: API routes with a configured field whitelist that isn't strict now raise a low-severity finding ("disallowed fields are silently dropped"). Strict whitelists reject disallowed fields with a 4xx; non-strict ones drop them silently, which is the more dangerous default for security-sensitive routes.
- **Real `ArchiveService::restoreFromArchive()` implementation**: Replay rows from a previously-created archive into a target table inside a database transaction. Honors `ArchiveRestoreOptions`: `targetTable` (defaults to the archive's source table), `offset`/`limit` for partial restore, and `conflictResolution` (`skip` records collisions in the result; `overwrite` hard-deletes the existing row to bypass soft-delete and re-inserts). Primary key detection prefers `uuid` then falls back to `id`. The existing `loadArchive()` handles checksum verification, decryption, and decompression — only the row replay was missing.

### Changed

- **`fields:whitelist-check` iterates real routes**: `analyzeWhitelistCompliance()` now reads `Router::getStaticRoutes()` and `Router::getDynamicRoutes()` and inspects each `Route`'s `getFieldsConfig()` for the actual `#[Fields]` attribute data (`allowed` list, `strict` flag). The previous placeholder loop iterated a hardcoded three-entry list (`api.users.index`, `api.posts.show`, `api.admin.users`) regardless of the application's actual routes.
- **`security:report` stripped to sections backed by real introspection**: Removed `analyzeAuthenticationSecurity()`, `getAuditSummary()`, `runVulnerabilityAssessment()`, `gatherSecurityMetrics()` (all returned `rand()` values across 12+ fields), the `sendReportByEmail()` stub (only printed a "would be sent" message), and `assessCompliance()` (returned hardcoded `'Partial'`/`'Enabled'` strings unconnected to any real signal). Removed the `--include-vulnerabilities`, `--include-metrics`, `--email`, and `--days` options (the first two only fed the fake methods; `--email` and `--days` were inert against the remaining real sections). The PDF format was also removed — never implemented. The command now exports HTML/JSON/text reports of the production readiness score, environment configuration, system info, and derived recommendations. For dependency CVE scanning, users are directed to `security:vulnerabilities`.
- **Memcached and File cache drivers document tagging as a deliberate gap**: `'tags' => false` in both drivers now carries an explanatory comment ("Memcached lacks set primitives" / "use Redis driver for tag invalidation") instead of the misleading "Not implemented yet" TODO. `addTags()`/`invalidateTags()` docblocks point callers at `getCapabilities()['features']['tags']` for branching.
- **README cache claim narrowed to reality**: The cache line in `README.md` now reads "Multi-driver support (Redis/Memcached/File) with distributed caching; tag-based invalidation on the Redis driver" instead of the previous unqualified "with tagging" — accurate for the driver matrix that actually ships.
- **`ArchiveService::validateTable()` handles SQLite's empty-result behavior**: PRAGMA-style introspection returns an empty column list for missing tables instead of throwing, so the previous `try/catch` was a false positive. The method now treats an empty result as "table not found" — required for `restoreFromArchive()` to return a useful error when the target schema isn't present.

### Removed

- **Fabricated telemetry in `security:report`**: Per the Changed section, the four `rand()`-driven sections (authentication, audit_summary, vulnerabilities, metrics) are gone, as is the hardcoded `compliance` block. The command no longer ships fake numbers under the name "security report."
- **Placeholder route data in `fields:whitelist-check`**: The three-entry stub list and its accompanying `// placeholder` comment are gone, as is the fabricated `pattern_frequency` block (`['simple' => 65, 'moderate' => 25, 'complex' => 10]`) from `analyzeCommonPatterns()`. The helper was renamed to `getReferenceFieldPatterns()` and now documents itself as static defaults seeding `--suggest-whitelist`, not telemetry.

### Fixed

- **Loop variable shadowing in `Model.php`**: `class_uses_recursive($class)` was using `$class` both as the function parameter and the `foreach` loop variable, tripping intelephense's P1124 warning. Renamed the loop variable to `$ancestor`.

### Upgrade Notes

- **`security:report` output shape changed.** Consumers parsing the JSON output should expect `authentication`, `audit_summary`, `vulnerabilities`, `metrics`, and `compliance` keys to be absent. Scripts depending on the removed `--include-vulnerabilities`, `--include-metrics`, `--email`, or `--days` options must be updated — those flags now produce `InvalidOptionException`.
- **PDF format removed from `security:report`.** Only `html`, `json`, and `text` are accepted. PDF was never actually generated.
- **Cache tagging is Redis-only.** If your application calls `$cache->addTags($key, $tags)` or `$cache->invalidateTags($tags)` while running on the Memcached or File driver, those calls continue to return `false` (unchanged), but the capability is now documented as deliberate. Branch on `$cache->getCapabilities()['features']['tags']` if you need driver-agnostic behavior, or switch to Redis for real tag invalidation.
- **`fields:whitelist-check` will now report your real routes.** Previously it analyzed the same three placeholder entries every run; the output is now meaningful but will be different. Routes without `#[Fields]` or with non-strict whitelists may surface as findings.
- **`ArchiveService::restoreFromArchive()` no longer always fails.** Code that called this method and treated the failure as expected (e.g., catch-and-log scaffolding) should be reviewed — it will actually restore rows now. Use `ArchiveRestoreOptions::testRestore()` (limit: 10, skip on conflict) for a safe dry-run shape.

```bash
composer update glueful/framework
```

---

## [1.43.0] - 2026-05-21 — Dabih

### Added

- **ORM-aware N+1 query detection**: New `PreventsLazyLoading` trait on `Model` detects lazy-loaded relations on members of a hydrated collection and produces actionable warnings that name the model and relation (`User::posts lazy-loaded from a collection of 50, add ->with('posts')`). Four modes: `off`, `warn` (logs `[GLUEFUL-N+1] ...` via `error_log()` with per-request dedupe), `strict` (throws `LazyLoadingViolationException`, which extends `\LogicException`), and `auto` (resolves to `warn` in development, `off` otherwise). Configure via `DB_LAZY_LOADING_MODE` or `config/database.php` → `orm.lazy_loading_mode`.
- **Per-model lazy-loading opt-out**: Set `protected ?string $instanceLazyLoadingMode = 'off';` on a model to skip detection for that class regardless of the global setting. Useful for legacy models that intentionally lazy-load.
- **Custom violation handler hook**: `Model::handleLazyLoadingViolationUsing(?\Closure $callback)` registers a callback that replaces the default warn/throw behavior — e.g. to route through a PSR logger, dispatch an event, or capture to Sentry. Pass `null` to clear.
- **`Model::clearLazyLoadingWarnings()`**: Explicit per-request clearing of the warn-mode dedupe set. PHP-FPM and CLI clear automatically via PHP's request shutdown; long-running runtimes (`glueful/runiva`: Swoole, RoadRunner, FrankenPHP) need to call this at request boundaries.
- **`Framework::initializeOrmFeatures()` boot hook**: Reads the lazy-loading mode from config and wires it into `Model::preventLazyLoading()`. Runs unconditionally on every boot — not gated on the development environment — so strict mode works in CI (`APP_ENV=testing`) and any explicit non-dev configuration.
- **`Relations\Relation::noConstraints(callable)`**: Standard Eloquent-style pattern for suppressing the single-parent `WHERE` constraint during eager-load construction. The `static $constraints` flag is reset in a `finally` block so subsequent eager loads still get the correct `WHERE ... IN (...)` clause from `addEagerConstraints()`.
- **`docs/ORM/N_PLUS_ONE_DETECTION.md`**: Public documentation covering modes, configuration, per-model opt-out, custom handlers, CI enforcement patterns, coexistence with the existing SQL-pattern detectors (`DevelopmentQueryMonitor`, `QueryLogger::detectN1Patterns()`), performance characteristics, and long-running-runtime considerations.
- **`docs/FRAMEWORK_IMPROVEMENTS.md` roadmap restructure**: Replaces the old "Phase 4+" placeholder with a three-tier plan grouped by leverage (near-term core work, demand-driven extensions, deferred/dropped items) and concrete rationale for each item. Marks `glueful/meilisearch` as published; drops `glueful/elasticsearch` and `glueful/prometheus` from planned with documented overlap reasoning.
- **Driver-aware `$query->explain()` and `Builder::explain()`**: The existing `QueryBuilder::explain()` is now driver-aware — SQLite uses `EXPLAIN QUERY PLAN` (the useful form) instead of plain `EXPLAIN` (which on SQLite returns a raw opcode dump). MySQL and PostgreSQL continue to use `EXPLAIN`. A new `Builder::explain()` on the ORM applies global scopes and delegates to the underlying query builder, returning the driver's native EXPLAIN row shape as `array<int, array<string, mixed>>`. Pairs naturally with N+1 detection for debugging the queries it flags.
- **`QueryExecutorInterface::getDriverName()`**: New interface method returning the underlying PDO driver name (`mysql`, `pgsql`, `sqlite`). Used by `QueryBuilder::explain()` to vary SQL by driver; available to other call-sites that need the same kind of branching.
- **Kubernetes-conventional health probe endpoints**: Three new routes at the canonical paths orchestrators expect — `GET /health/live`, `GET /health/ready`, `GET /health/startup`. `live` is a dependency-free liveness check (200 when the process can respond); `ready` reports database, cache, and config status and returns 503 when any dependency is unhealthy; `startup` reports initialization complete. The existing `/healthz` and `/ready` endpoints continue to work — the new paths are additive, so Pod specs that reference either form keep working. New `HealthController::startup()` handler; `liveness()` and `readiness()` are reused.
- **Hardened API keys via dedicated `api_keys` table**: New `ApiKeyService` provides creation, verification, rotation with grace period, and revocation. Keys carry scopes (`['read:*', 'write:posts']`), CIDR/IP allowlists (`['192.168.1.0/24']`), expiration, and environment-prefixed plaintext format (`gf_live_...` in production, `gf_test_...` elsewhere). Plaintext keys are SHA-256 hashed before storage; the first 16 chars are stored as an indexed prefix for O(1) lookup. The `key_hash` column is `UNIQUE` and lookup is collision-tolerant — if two prefixes ever collided (statistically impossible at ~190 bits of entropy but defensively handled), the code iterates all candidates and `hash_equals` each. Rotation creates a new key and sets the old key's `expires_at` to `now + graceHours` so both work during the grace window. Schema migration ships in api-skeleton (`009_CreateApiKeysTable.php`).
- **`#[RequireScope]` route attribute**: Declares required scopes on controller methods. Repeatable — multiple scopes within one attribute are OR, multiple attributes are AND. `AttributeRouteLoader` auto-attaches the `require_scope` middleware so the declaration is self-contained.
- **`apikey:*` CLI commands**: `apikey:create`, `apikey:list`, `apikey:rotate`, `apikey:revoke`.
- **Router exposes the matched route on the request**: `Router::dispatch()` now sets `_route` and `_route_params` on `$request->attributes` before middleware runs, so middleware (ours + future) can read route-level metadata.

### Fixed

- **ORM property access now routes to relations**: `HasAttributes::getAttribute()` previously returned `null` for relation-method names (`$user->posts` came back empty). It now forwards to `getRelationValue()` when the relation is already loaded or the method declares a `Relation` return type. Detected via reflection without actually invoking the method, so non-relation methods with the same name as a key are left alone.
- **`__isset()` is now relation-aware**: PHP's null-coalescing operator (`??`) calls `__isset()` before `__get()`. The previous `__isset()` ignored relations entirely, so `$user->posts[0] ?? null` silently returned `null` even when posts existed. Now it returns true for loaded relations and for relation methods, so `??` correctly triggers lazy-load (or returns eager-loaded data) instead of swallowing the result.
- **Related-model context propagation**: `HasRelationships::newRelatedInstance()` now passes the parent model's `ApplicationContext` to the child model's constructor. Without this, child instances could not resolve their database connection via the container, causing relation queries to fail with a `RuntimeException`.
- **Eager loading no longer emits `WHERE x = NULL`**: `Builder::getRelation()` used to instantiate the relation against a template model with a `NULL` primary key, so `addConstraints()` generated `WHERE user_id = NULL` and eager-loaded collections came back empty. Builder now wraps the relation construction in `Relation::noConstraints(...)` so `addEagerConstraints()` applies the correct `WHERE user_id IN (...)` clause across all parent keys.

### Changed

- **`ApiKeyAuthenticationProvider` is now single-track**: Verifies via the new `api_keys` table only. The previous code path that queried `UserRepository::findByApiKey()` referenced a `users.api_key` column that doesn't exist in the canonical api-skeleton schema (`001_CreateInitialSchema.php`) — there was no legacy data to preserve. All four `AuthenticationProviderInterface` methods (`authenticate`, `validateToken`, `refreshTokens`, `generateTokens`) updated. Provider returns null on any failure (revoked, expired, invalid, IP-blocked, unknown). Populates `api_key_scopes` on the request for `RequireScopeMiddleware` to enforce.

### Removed

- **`UserRepository::findByApiKey()`** — zero callers after the provider switches to `ApiKeyService::verify()`. The method queried a `users.api_key` column that doesn't exist in the canonical schema, so it was dead code for any standard install. Verified no external callers (extensions, api-skeleton app code, other repos).

### Upgrade Notes

- **Run the new migration.** `glueful/api-skeleton ^1.26.0` ships `009_CreateApiKeysTable.php`. Run `php glueful migrate:run` after upgrading. The `apikey:*` CLI commands and the new auth provider both require the table.
- **`ApiKeyAuthenticationProvider` is single-track.** If you previously relied on a custom `users.api_key` column being read by `UserRepository::findByApiKey()`, that code path is gone. Migrate existing keys into the new `api_keys` table (use `ApiKeyService::create()` programmatically, or `php glueful apikey:create` per user). No data is migrated automatically — the canonical schema never had the column, so we don't ship an opt-in helper.
- **`UserRepository::findByApiKey()` removed.** Zero callers verified across the framework, all official extensions, api-skeleton, and other org repos. External consumers that subclass `UserRepository` or call the method directly must remove the reference.
- **New env var (optional): `DB_LAZY_LOADING_MODE`.** Defaults to `auto` → `warn` in development, `off` elsewhere. Set explicitly to `strict` in CI to fail tests on accidental N+1 patterns, or `off` to disable detection entirely.
- **`ApiKey` model uses `$timestamps = false`.** The migration's `created_at` / `updated_at` columns have `DEFAULT CURRENT_TIMESTAMP`, so the database fills them. The trait-driven timestamp path was unsuitable because it returns `DateTimeImmutable` instances that don't bind cleanly through the QueryBuilder. Subclasses of `ApiKey` should keep this disabled.

```bash
composer update glueful/framework
php glueful migrate:run
```

---

## [1.42.0] - 2026-05-20 — Caph

### Added

- **OpenAPI spec quality overhaul**: The generated OpenAPI spec now declares all configured security schemes (BearerAuth, ApiKeyAuth, ...) via a new `SecuritySchemeRegistry`, driven by `documentation.security_schemes` and `documentation.middleware_map` config. Per-operation `security` requirements are derived from route middleware instead of being hardcoded to BearerAuth.
- **`ErrorResponse` schema component**: New OpenAPI component describing the unified error envelope (`{success, message, error: {code, error_code, timestamp, request_id}}`) with an enum of stable `error_code` values (`BAD_REQUEST`, `NOT_FOUND`, `FORBIDDEN`, etc.). All CRUD endpoint 4xx responses now `$ref` this schema so generated SDKs can typecheck error responses.
- **Deterministic `operationId` values**: New `OperationIdGenerator` produces camelCase IDs (`getV1UsersByUuid`) with collision-numbering. Closes a hidden gap where comment-driven generation emitted operations without any `operationId` at all, forcing SDK generators into auto-derived garbage method names.
- **`PaginationSchemaBuilder`**: New `PaginationMeta`, `PaginationLinks`, and per-resource envelope schemas matching `PaginatedResourceResponse` exactly. List endpoints now reference these components so generated SDKs can type paginated responses uniformly.
- **`addRouteWithFieldsAttribute()` helper**: Surfaces `?fields=` and `?expand=` query parameters with an enum of allowed paths derived from `#[Fields(allowed: [...], strict: true)]`. Public API ready for controller-reflection wiring (auto-discovery deferred).
- **`ExampleDeriver`**: Auto-derives representative request body examples from Validator rules and from parsed schema properties (`email`, `uuid`, `url`, `date`, `datetime` formats; field-name heuristics for `name`, `slug`, `title`, etc.). `@example` annotation in docblocks overrides the derived value.
- **OpenAPI 3.1 webhooks block**: New `WebhookDocsBuilder` emits the top-level `webhooks` object from `documentation.webhooks` config. Each entry documents `X-Glueful-Signature` (Stripe-style `t=...,v1=...`) and `X-Glueful-Timestamp` headers actually sent by `WebhookDeliveryService`. New `WebhookEnvelope` schema describes the standard payload shape.
- **`generate:client` CLI wrapper**: Shells out to `openapi-typescript` for TS/JS targets and `openapi-generator-cli` for everything else. Glueful does not own codegen logic — the command builds the right shell invocation with safe-by-construction language sanitization and `escapeshellarg` for paths. Prints the command by default; `--execute` runs it.
- **`docs/WEBHOOKS.md`**: Guide explaining how to declare events under `documentation.webhooks` so they appear in the spec for SDK consumers.

### Changed

- **Error response references unified**: `DocGenerator::getErrorResponses()` and `getCommonResponses()` now reference `#/components/schemas/ErrorResponse` instead of the legacy flat `Error` schema. The `Error` schema is preserved in the components for backward compatibility but is no longer referenced by generated endpoints.
- **`SecuritySchemeRegistry` wired into `OpenApiGenerator`**: Config keys `documentation.security_schemes` and `documentation.middleware_map` now actually take effect in the production pipeline (previously they were inert despite the registry API existing).
- **Duplicate `PaginationMeta` removed**: An inline legacy definition in `getDefaultSchemas()` that silently overwrote the new builder-provided shape (with mismatched `last_page`/`has_more` fields) has been removed. The authoritative shape now matches `PaginatedResourceResponse`.

### Breaking

- **`PermissionUnauthorizedException` envelope shape changed**: Previously returned `{success, message, code, error_code}` with `code` and `error_code` at the top level. Now returns the unified `{success, message, error: {code, error_code, timestamp, request_id}}` shape, identical to all other HTTP exceptions. API consumers that read `code` or `error_code` at the top level must read `error.code` and `error.error_code` instead.
- **Error envelope now includes `error_code`**: The `error` object now always contains an `error_code` field (`NOT_FOUND`, `FORBIDDEN`, etc., or the stringified status code for non-enumerated statuses like `"418"`). Existing consumers that did strict-shape matching on `error` may need to ignore unknown keys.

### Upgrade Notes

- **Permission exception consumers** — If any API client reads `code` or `error_code` at the top level of a 403 response from a `PermissionUnauthorizedException`, update it to read `error.code` and `error.error_code`. All other 4xx/5xx responses already used the nested shape, so most consumers are unaffected.
- **Generated SDK consumers** — Regenerate clients after upgrading. The `ErrorResponse`, `PaginationMeta`, `PaginationLinks`, and `WebhookEnvelope` components are new; operation IDs may have changed to the deterministic camelCase form (e.g., `getUsers` → `getV1UsersByUuid`).
- **No new env vars** — Configuration extensions live entirely in `config/documentation.php` (`security_schemes`, `middleware_map`, `webhooks`). Existing deployments need no `.env` changes.
- **Discriminator support deferred** — The plan included optional `discriminator` emission for polymorphic resources, gated on actual polymorphism in the codebase. The gate found none, so this is deferred to a future release.

---

## [1.41.0] - 2026-03-03 — Beid

### Changed

- **Logging bootstrap is now profile-driven**: `config/logging.php` now resolves deterministic defaults from `LOG_PROFILE` (or `APP_ENV`) before applying explicit env overrides. Added built-in `development`, `staging`, `production`, and `testing` logging profiles, normalized log directory handling, and aligned profile defaults for safer production startup.
- **Production system checks now validate logging safety**: `system:check --production` now flags unsafe logging configurations including no durable sink (`LOG_TO_FILE=false` and `LOG_TO_DB=false`), disabled event audit toggles (`EVENTS_ENABLED`/`EVENTS_AUDIT_LOGGING`), debug-level production logging, and invalid retention values.
- **Canonical logging guide added**: Added `docs/LOGGING_BOOTSTRAP.md` and linked it from `README.md`. `.env.example` now documents `LOG_PROFILE`, retention envs, and a production baseline block.

### Fixed

- **QueryBuilder upsert passed record values as identifiers**: `InsertBuilder::buildUpsertQuery()` sent the full associative data array to driver `upsert()` SQL builders, which expect column-name lists. On PostgreSQL this could pass `null` values into `wrapIdentifier()` and crash with `Argument #1 ($identifier) must be of type string, null given`. The framework now passes insert column names (`array_keys($data)`) and aligns `DatabaseDriver::upsert()` type docs/contracts to use `list<string>` column-name arrays.
- **Audit logging toggles now match runtime behavior**: Core activity audit subscriber registration now respects `events.enabled` and `events.listeners.audit_logging` (`EVENTS_ENABLED`, `EVENTS_AUDIT_LOGGING`) during framework boot. `LogManager` now honors `LOG_TO_FILE=false` and skips file handler/directory setup when disabled. Removed debug `error_log()` noise from `RequestUserContext::getAuditContext()`, and documented event audit toggles in `.env.example`.

### Upgrade Notes

- **`LOG_TO_DB` default changed**: Previously defaulted to `true` when the env var was absent. Now defaults to `false` in all profiles. If your application relies on database logging, add `LOG_TO_DB=true` to your `.env` file.
- **Profile-driven defaults**: Logging levels and sink settings are now determined by the active profile (`LOG_PROFILE` or `APP_ENV`). Explicit env vars still override profile defaults. Review `docs/LOGGING_BOOTSTRAP.md` for the full profile matrix.
- **New env vars**: `LOG_PROFILE`, `LOG_RETENTION_*_DAYS` keys are now recognized. No action needed if unset — profiles provide safe defaults.

---

## [1.40.4] - 2026-02-21 — Alnair (Patch)

### Fixed

- **PHPCS line length in `WhereClause`**: Extracted long error message string in `getConditionsArray()` to stay within the 120-character line limit.

### Notes

- Patch release. No breaking changes. Drop-in replacement for 1.40.3.
- Code style fix only — no runtime behavior changes.

---

## [1.40.3] - 2026-02-21 — Alnair (Patch)

### Fixed

- **Mutation WHERE clauses crash on non-equality operators**: `WhereClause::getConditionsArray()` only treated `=` as a valid operator for UPDATE/DELETE conditions. Queue and notification cleanup jobs using `<=`, `<`, `IS NULL`, etc. threw "Complex WHERE conditions not yet supported" errors. Now supports `<`, `<=`, `>`, `>=`, `!=`, `<>`, `LIKE`, `NOT LIKE`, `IN`, `NOT IN`, `IS NULL`, `IS NOT NULL` with proper SQL generation and binding extraction in both `UpdateBuilder` and `DeleteBuilder`.
- **Queue Redis config validation rejects string values from `.env`**: `DriverRegistry::validateType()` required strict PHP `int`/`bool` types for fields like `port` and `database`, but `getenv()` always returns strings. Now accepts numeric strings for `int`/`port` types (with range validation for ports) and boolean-like strings (`true`/`false`/`1`/`0`/`yes`/`no`/`on`/`off`).
- **Async notification dispatch crashes API requests**: Uncaught exceptions from `queueAsyncDispatch()` in `NotificationService` bubbled up and converted successful DB writes into 500 responses. Now wrapped in try/catch — logs the error and returns gracefully without failing the primary operation.

### Notes

- Patch release. No breaking changes. Drop-in replacement for 1.40.2.
- The mutation WHERE fix affects any code path that uses non-equality conditions in `update()` or `delete()` queries built through the query builder.

---

## [1.40.2] - 2026-02-21 — Alnair (Patch)

### Fixed

- **Config merge crashes on lists containing nested arrays**: The 1.40.1 fix gated `array_unique()` to true lists vs associative arrays, but did not handle lists *containing* nested array/object items (e.g., `queue.monitoring.alert_rules`). `array_unique()` internally stringifies values with `SORT_STRING`, triggering "Array to string conversion" warnings on non-scalar list items. Replaced with a hash-based dedup that uses `json_encode()` (fallback `serialize()`) for complex items and `var_export()` for scalars, eliminating the conversion entirely.

### Notes

- Patch release. No breaking changes. Drop-in replacement for 1.40.1.
- This was the root cause of 500 errors on endpoints that dispatch events loading merged config with nested list items (e.g., comment creation triggering queue/webhook listeners).

---

## [1.40.1] - 2026-02-21 — Alnair (Patch)

### Fixed

- **Config merge `array_unique()` on nested arrays**: `mergeConfig()` in `helpers.php` was applying `array_unique()` to merged arrays containing nested associative arrays (e.g., `session.providers` config blocks), causing "Array to string conversion" warnings. The merge now distinguishes true lists from associative arrays — `array_unique()` only applies when both sides are sequential lists; associative arrays are deep-merged recursively instead.
- **Dead list-key logic removed**: Cleaned up now-unused list-key handling in the same merge function.

### Notes

- Patch release. No breaking changes. Fixes a regression in config merging for any config key containing nested associative arrays.

---

## [1.40.0] - 2026-02-21 — Alnair

Notification delivery orchestration and provisioning error semantics improvements.

### Added

- **`NotificationService::sendSplit()`**: First-class split-delivery API for `persist now + dispatch async channels`.
- **Async dispatch job**: Added `src/Queue/Jobs/DispatchNotificationChannels.php` to dispatch persisted notifications by UUID on queued channels.
- **Idempotency in notifications**: Added idempotent notification send support via a dedicated `notifications.idempotency_key` schema contract and indexed lookup in `NotificationRepository::findRecentByIdempotencyKey()`.
- **Per-channel delivery state tracking**: Added `notification_deliveries` persistence model and repository APIs (`ensureDeliveryRecords`, `recordDeliveryAttempt`, `getChannelsNeedingDispatch`, `getFailedDeliveryChannels`) to track delivery lifecycle per channel.
- **Channel-level idempotency marker**: Added `_meta.delivery_idempotency_key` (`{notification_uuid}:{channel}`) on dispatch payloads for safer provider-side deduplication.
- **Provisioning exception type**: Added `ProvisioningException` (`src/Http/Exceptions/Domain/ProvisioningException.php`) for account setup/provisioning failures that are not authentication failures.

### Changed

- **`NotificationService::send()` refactor**:
  - Supports sync/async split with `sync_channels` and `async_channels`.
  - Supports channel policy evaluation with `channel_failure_policy` (`any_success`, `require_critical`, `all`) and `critical_channels`.
  - Queues async channel dispatch on the configured queue (`async_queue`, default `notifications`).
  - Persists notification metadata in `_meta` including idempotency metadata when present.
- **Top-level idempotency lookup path**: `NotificationRepository::findRecentByIdempotencyKey()` now uses `notifications.idempotency_key` directly (no `_meta` scan fallback).
- **`NotificationService::dispatchStoredNotification()`** now dispatches only channels that still need work (`pending`/`failed`), records per-channel outcomes, and returns `failed_channels` for targeted retry handling.
- **`DispatchNotificationChannels` retry behavior** now fails only when unresolved failed channels remain, so queue retries naturally target failed channels instead of re-sending already delivered ones.
- **`NotificationService::sendWithTemplate()`** now routes through `send()` so idempotency, split delivery, and policy semantics are applied consistently.
- **`NotificationsProvider`** now registers `NotificationService` in DI as a shared service.
- **`Http\Exceptions\Handler`** maps `ProvisioningException` to HTTP `500` and routes it to the `api` log channel.

### Notes

- This release introduces non-backward-compatible response and flow behavior for notification sending to standardize split delivery, failure policy handling, and channel-scoped retry semantics.

## [1.39.0] - 2026-02-20 — Menkent

Token and session management reimplementation. Replaces the legacy token/session model with a security-first architecture: hash-only refresh tokens with one-time-use rotation, session versioning for instant access-token invalidation, replay detection with session-scope revocation, and a clean service decomposition.

### Added

- **`RefreshService`**: Orchestrates refresh token rotation, session revalidation, replay detection, and provider dispatch in a single transactional flow.
- **`AccessTokenIssuer`**: Lightweight wrapper for issuing access token pairs via `TokenManager` with `sid`/`ver`/`jti` JWT claims.
- **`ProviderTokenIssuer`**: Handles non-JWT provider token refresh (LDAP, SAML adapters) during the rotation flow.
- **`SessionRepository`**: Session state read/write/revoke/version-bump operations against `auth_sessions`.
- **`RefreshTokenRepository`**: Repository facade over `RefreshTokenStore` for hash lookup, one-time rotation, and family revocation.
- **`RefreshTokenStore`**: Hash-only refresh token persistence with `SELECT ... FOR UPDATE` transactional rotation, replay detection, and configurable idempotency window support.
- **`SessionStateCache`**: Cache adapter with invalidation hooks for session state — `persistRotatedSession()` and `invalidateSession()` on revoke/version-bump.
- **`AuthenticatedUser`**: Minimal runtime identity value object (`uuid`, `sessionUuid`, `provider`, `username`, `email`, `roles`, `permissions`) used across auth/permission checks.
- **`tests/Integration/Auth/RefreshTokenStoreIntegrationTest.php`**: Integration tests for hash-only token persistence, one-time rotation, and replay detection using in-memory SQLite.
- **`tests/Integration/Auth/TokenManagerSessionVersionTest.php`**: Integration tests for `sid`/`ver` claim validation and token invalidation on session version bump.
- **`docs/TOKEN_SESSION_REIMPLEMENTATION_PLAN.md`**: Comprehensive design document covering schema contracts, runtime flows, security requirements, cache invalidation contract, cleanup policy, and blast radius estimate.

### Changed

- **`TokenManager`**: Issues JWT access tokens with `sid` (session UUID), `ver` (session version), and `jti` (unique token ID) claims. Validates access tokens against server-side session state and version instead of relying on fat JWT payload assumptions.
- **`SessionStore`**: Initializes `session_version` to 1 on session creation. Adds session version validation logic for JWT claim verification.
- **`JwtAuthenticationProvider`**: Extracts `sid`, `ver`, `jti` claims from JWT payload for session-state-based validation.
- **`AuthProvider`**: Registers new services (`RefreshService`, `AccessTokenIssuer`, `ProviderTokenIssuer`) in the DI container.
- **`AuthMiddleware`**: Enforces access-token validation against session state and version.
- **`BaseController`**: `$currentUser` property typed as `AuthenticatedUser|null`.
- **`CachedUserContextTrait`**: Imports and uses `AuthenticatedUser` with request-level caching.
- **`RequestUserContext`**: Simplified to session-first identity hydration — extracts `sessionUuid` from JWT `sid` claim.
- **`SessionCleanupTask`**: Expanded to clean both `auth_sessions` (expired/revoked retention) and `auth_refresh_tokens` (consumed/expired/revoked rows older than retention window).
- **`AuthorizationTrait`**, **`FieldLevelPermissionsTrait`**, **`QueryRestrictionsTrait`**: Updated to work with new session-based auth model.
- **`config/session.php`**: Added `cleanup_batch_size`, `revoked_retention_days`, `refresh_token_retention_days` settings.
- **`.env.example`**: Added `JWT_KEY`, `ACCESS_TOKEN_LIFETIME`, `REFRESH_TOKEN_LIFETIME`, `TOKEN_SALT`, `JWT_ALGORITHM` entries.
- **`JWTService`**: Generates `jti` claim for audit/tracing correlation.

### Notes

- **Breaking change**: Existing sessions and tokens become invalid at cutover. All users must re-authenticate after deployment.
- The `auth_refresh_tokens` table must be created via migration. Schema contracts are documented in `docs/TOKEN_SESSION_REIMPLEMENTATION_PLAN.md`.
- Legacy token columns (`access_token`, `refresh_token`, `access_expires_at`, `refresh_expires_at`, `last_token_refresh`, `token_fingerprint`) should be dropped from `auth_sessions` via migration.
- Refresh tokens are stored as SHA-256 hashes only — no raw tokens at rest.
- Replay detection revokes the entire session scope (all active refresh tokens for that `session_uuid`).
- `jti` is used for audit/tracing only; no blocklist is required in this release.

---

## [1.38.0] - 2026-02-17 — Lesath

Auth token-refresh performance optimization. Eliminates redundant `auth_sessions` database lookups during token refresh by reusing session metadata from the initial query, removes direct `new Connection()` instantiation in favour of DI-resolved services, and adds request-level caching for refresh-token session lookups.

### Changed

- **`TokenManager::getSessionFromRefreshToken()` fetches `provider` and `remember_me`**: The initial session lookup now selects `provider` and `remember_me` alongside `user_uuid`, `created_at`, and `refresh_expires_at`. Two subsequent `auth_sessions` queries that re-fetched these fields are removed.
- **`AuthenticationService::refreshTokens()` resolves session up front**: Calls `SessionStore::getByRefreshToken()` at the top of the method and early-returns on `null`. Downstream `getUserDataByUuid()` receives the `user_uuid` directly from the cached session instead of querying `auth_sessions` again.
- **`AuthenticationService::getUserDataFromRefreshToken()` renamed to `getUserDataByUuid()`**: Accepts a `user_uuid` string instead of a refresh token. Eliminates the third redundant `auth_sessions` lookup and removes direct `new Connection()` instantiation in favour of the injected `UserRepository`.

### Added

- **Request-level caching for `SessionStore::getByRefreshToken()`**: Mirrors the existing `getByAccessToken()` pattern — results are stored in `$requestCache` keyed by `refresh:{hash}` so repeated calls within the same request hit memory instead of the database.

### Notes

- No breaking changes. All modified methods are `private` or internal to the auth subsystem.
- The composite index `idx_auth_sessions_refresh_status` on `(refresh_token, status)` should be added to production databases to complement these lookup optimizations.

---

## [1.37.0] - 2026-02-15 — Kaus

Deferred extension commands, ORM Builder fixes, webhook DI wiring, and documentation generation improvements. Resolves extension CLI commands silently failing when registered before the console application exists, fixes OFFSET-without-LIMIT query errors in the ORM Builder, and hardens the OpenAPI documentation pipeline for CLI usage.

### Fixed

- **ORM `Builder::forPage()` OFFSET-without-LIMIT error**: Changed `offset()->limit()` to `limit()->offset()` so `QueryValidator` no longer throws "OFFSET requires LIMIT" when paginating ORM queries.
- **Extension CLI commands silently dropped**: `ServiceProvider::commands()` and `discoverCommands()` previously returned silently when `console.application` wasn't in the container. Extension commands registered during `boot()` before the console app was created were lost. Now deferred into a static `$deferredCommands` array and picked up by `ConsoleApplication`.
- **WebhookDispatcher missing `ApplicationContext`**: The `CoreProvider` factory for `WebhookDispatcher` was missing `$this->context` as the 3rd constructor argument, causing "ApplicationContext is required for webhook dispatch" errors.
- **OpenAPI generator failing in CLI**: `OpenApiGenerator` passed no context when constructing `DocGenerator`, causing `api_url()` to fail. Now passes `context: $context`.
- **`DocGenerator` server URL blank in CLI**: Added a fallback chain for the server URL: `api_url()` → `app.urls.base` config → discovered URL from merged definition files → `"/"`.
- **Empty properties rendered as `[]` instead of `{}` in OpenAPI JSON**: `CommentsDocGenerator` now outputs `new \stdClass()` for empty `$properties` arrays in two locations (`extractSimplifiedRequestBody` and `parseSimplifiedSchema`), producing valid `{}` in JSON output.

### Added

- **`ExtendsBuilder` interface** (`src/Database/ORM/Contracts/ExtendsBuilder.php`): Contract for scopes that add macros or behaviors to the ORM Builder. Replaces `method_exists($scope, 'extend')` checks with a proper interface.
- **`ServiceProvider::flushDeferredCommands()`**: Static method for the console application to retrieve and clear extension command classes that were registered before the console app existed.
- **`DocGenerator::$discoveredServerUrl`**: Captures the first server URL from merged definition files as a fallback when `api_url()` and config are unavailable.

### Changed

- **`SoftDeletingScope` implements `ExtendsBuilder`**: Now declares the `ExtendsBuilder` interface instead of relying on duck-typing for the `extend()` method.
- **`Builder::setModel()` uses `ExtendsBuilder` interface**: Checks `$scope instanceof ExtendsBuilder` instead of `method_exists($scope, 'extend')` for registering scope macros.
- **`ConsoleApplication` registers deferred commands**: Constructor now calls `registerDeferredExtensionCommands()` which flushes deferred commands from `ServiceProvider` and registers them with the console.

### Notes

- No breaking changes. Extension commands that were silently dropped now register correctly.
- The `ExtendsBuilder` interface is opt-in — scopes without it continue to work as global scopes, they just won't extend the builder with macros.

---

## [1.36.0] - 2026-02-14 — Jabbah

Model event isolation and base64 upload file extension fix. Resolves a critical bug where ORM model event callbacks leaked across unrelated model classes, and removes an unnecessary model instantiation during event registration that caused "No database connection" errors at boot time.

### Fixed

- **Model event callbacks leaking across classes**: `HasEvents::$modelEventCallbacks` was a flat `[$event => [callbacks]]` array shared by all Model subclasses. A `creating` callback registered in `EntityType::boot()` (type-hinted for `EntityType`) would also fire when `Entity` was created, causing a `TypeError`. Callbacks are now keyed by `[className][event]` so each model only fires its own listeners.
- **`registerModelEvent` instantiating model without DB context**: The method called `new static()` to validate the event name via `getEventClass()`, which triggered the model constructor and could fail with "No database connection available" when no `ApplicationContext` was set. Replaced with a simple `in_array` check against known event names — no model instantiation needed.
- **Base64 uploads producing `.bin` file extensions**: `UploadController` hardcoded the default filename as `upload.bin` for base64 uploads without an explicit `filename`. The `generateSecureFilename()` method extracted `bin` as a valid extension and never fell back to the MIME type lookup. Now the MIME type is resolved first and used to derive the correct extension (e.g., `image/png` → `.png`).

### Added

- **`UploadController::extensionFromMime()` helper**: Maps common MIME types (`image/png`, `image/jpeg`, `video/mp4`, `audio/mpeg`, `application/pdf`, etc.) to their proper file extensions for base64 uploads.

### Notes

- No breaking changes. The event scoping fix is backwards-compatible — models that only register their own events see no behavior change.
- The base64 extension fix applies to all new uploads; existing blobs with `.bin` extensions are unaffected.

---

## [1.35.0] - 2026-02-14 — Izar

Cloud storage compatibility and blob retrieval fixes. Resolves S3/R2 upload failures caused by the atomic temp-file-then-move write pattern, fixes blob lookup returning false 404s due to incorrect query builder operator handling, and surfaces storage error details in API responses.

### Fixed

- **S3/R2 upload failures (`io_move_failed`)**: `FlysystemStorage::store()` now writes directly via `writeStream()` for cloud disks (S3, GCS, Azure) instead of using the atomic temp+move pattern (`putStream`). The move step performs a CopyObject operation that fails on Cloudflare R2 and some S3-compatible stores. Local disks continue to use the atomic pattern for crash safety.
- **`BlobRepository::findByUuidWithDeleteFilter` returning null for existing blobs**: The method passed `['status' => ['!=', 'deleted']]` to `findWhere()`, but the query builder's array-format `where()` always uses the `=` operator — the `['!=', 'deleted']` array value was compared with `=` instead of `!=`, producing no results. Rewritten to use explicit three-parameter format: `->where('status', '!=', 'deleted')`.
- **Storage error messages hidden in API responses**: `FlysystemStorage::store()` now always includes the underlying exception message in the `UploadException` (`Storage write failed: <details>`). Previously, non-`FilesystemException` errors returned a generic "Storage write failed" with no diagnostic information.
- **Base64 uploads producing `.bin` file extensions**: `UploadController` hardcoded the default filename as `upload.bin` for base64 uploads without an explicit `filename`. The `generateSecureFilename()` method extracted `bin` as a valid extension and never fell back to the MIME type lookup. Now the MIME type is resolved first and used to derive the correct extension (e.g., `image/png` → `.png`).

### Added

- **`FlysystemStorage::isCloudDisk()` helper**: Checks the disk driver config to determine whether to use direct writes (cloud) or atomic temp+move (local). Supports `s3`, `gcs`, and `azure` drivers.
- **`UploadController::extensionFromMime()` helper**: Maps common MIME types (`image/png`, `image/jpeg`, `video/mp4`, `audio/mpeg`, `application/pdf`, etc.) to their proper file extensions for base64 uploads.

### Notes

- No breaking changes. Cloud uploads that previously failed with `io_move_failed` now succeed.
- The `BlobRepository` fix affects any code using `findByUuidWithDeleteFilter()` — blobs that were incorrectly returning 404 will now resolve correctly.

---

## [1.34.0] - 2026-02-14 — Hamal

Hardened the authentication pipeline and DI wiring for blob uploads, queue serialization, and user resolution. Fixes a critical bug where the auth middleware swallowed downstream controller exceptions as 401 "Authentication error occurred", and resolves multiple DI registration gaps that prevented `UploadController` from being constructed.

### Fixed

- **Auth middleware no longer swallows controller exceptions**: `$next($request)` moved outside the `try/catch(\Exception)` block in `AuthMiddleware::handle()`. Previously, any exception thrown by downstream middleware or the controller (e.g., missing DI binding, storage error) was caught by the generic handler and returned as `"Authentication error occurred"` with a misleading 401 status. Controller exceptions now propagate correctly to the framework's exception handler.
- **`Utils::getUser()` no longer requires legacy `role`/`info` JWT claims**: The method previously returned `null` when the JWT payload lacked `role` and `info` fields (check: `isset($payload['uuid'], $payload['role'], $payload['info'])`). Now only `uuid` is required; `role` defaults to `null` and `info` defaults to `[]`. This fixes `UploadController` and other controllers returning false "Authentication required" after successful middleware auth.
- **`Utils::getUser()` checks request attributes first**: Before decoding the JWT, the method now checks if the auth middleware already set the authenticated user on the Symfony Request's attributes (`$request->attributes->get('user')`). This avoids redundant token extraction via `RequestContext` which could fail on certain server configurations.
- **Auth token extraction falls back to Symfony Request**: Both `AuthMiddleware::extractAuthenticationCredentials()` and `JwtAuthenticationProvider::authenticate()` now fall back to extracting the Bearer token from the Symfony `Request` object when `TokenManager`/`RequestContext`-based extraction returns null. Fixes authentication failures on server configurations where the PSR-7 `RequestContext` doesn't capture the `Authorization` header (e.g., certain Apache CGI/FastCGI + multipart combinations).
- **Queue `DriverRegistry` serialization crash**: `DriverRegistry::getDriver()` used `serialize($config)` to build a cache key, which threw `"Serialization of 'Closure' is not allowed"` when queue config contained connection factories. Replaced with `json_encode()` filtered to scalar/array values.
- **`AuthMiddleware::logAccess` error handling**: Changed `catch (\Error)` to `catch (\Throwable)` around the `$this->authManager->logAccess()` call, preventing `\Exception` subclasses from bubbling up as auth errors.

### Changed

- **`AuthenticationService` removed user tracking columns**: Removed `ip_address`, `user_agent`, `x_forwarded_for_ip_address`, and `last_login_date` UPDATE on the `users` table during login. This tracking data is already stored in `auth_sessions` via `createUserSession()`. Eliminates `SQLSTATE[42703]: Undefined column` errors on databases without these legacy columns.

### Added

- **`UploadController` DI registration**: `StorageProvider` now registers `FileUploader` and `UploadController` as factory definitions in the container, with config-driven constructor parameters (`uploads.path_prefix`, `uploads.cdn_base_url`, `uploads.disk`). Fixes `"Service 'Glueful\Controllers\UploadController' not found"` when the autowirer couldn't resolve `FileUploader`'s string constructor parameters.

### Notes

- No breaking changes. All fixes are backward-compatible.
- The `Utils::getUser()` change means code relying on `$user['role']` or `$user['info']` being non-null should use null-safe access (`$user['role'] ?? 'default'`).

---

## [1.33.0] - 2026-02-14 — Gacrux

Eliminated all `fromGlobals()` / `createFromGlobals()` fallbacks from service code, enforcing container-based request resolution throughout the framework. This fixes unbounded memory growth on high-header requests (PSR-7 `MessageTrait` crash at 512MB) and makes the framework compatible with long-running servers where `$_SERVER` superglobals become stale.

### Changed

- **Auth services resolve `RequestContext` from DI container**: `TokenManager`, `JwtAuthenticationProvider`, `SessionStore`, and `EmailVerification` no longer fall back to `RequestContext::fromGlobals()`. All four resolve `RequestContext` from `ApplicationContext`'s container when not explicitly provided. Throws `\RuntimeException` if neither a direct parameter nor a container is available.
- **`AuthenticationService` uses container-resolved request**: Five locations that called `Request::createFromGlobals()` for IP/User-Agent tracking, `checkAuth()`, `checkAdminAuth()`, and `getCurrentUser()` now resolve from the container. The fallback `new JwtAuthenticationProvider()` (without context) on line 96 now passes `$this->context`.
- **`AuthController` passes context to dependencies**: `new EmailVerification()` and `new AuthenticationService()` fallback constructors now receive `$this->context` for proper container resolution.
- **`SessionStoreResolver::resolve()` fails fast**: Removed silent `new SessionStore()` catch fallback. Container resolution failure is now a `\RuntimeException` instead of a silently degraded store with stale globals.
- **`TokenManager::getSessionStore()` propagates errors**: The catch block no longer swallows exceptions with `new SessionStore()`. Container resolution failures surface immediately.
- **`EmailVerification::sendPasswordResetEmail()` signature**: Now accepts an optional `?ApplicationContext $context` parameter to resolve `RequestContext` from the container instead of calling `fromGlobals()`.
- **Static helpers use container**: `RequestHelper` (3 methods), `Utils::getSession()`, and `Utils::getUser()` resolve `Request`/`RequestContext` from the static `$context` container instead of `createFromGlobals()` / `getallheaders()` / `$_SERVER` superglobals.
- **`Cors::handle()` resolves from container**: Falls back to container-resolved `Request` instead of `createFromGlobals()` when no request parameter is provided.
- **`ConditionallyLoadsAttributes::getRequest()` returns `null` instead of creating from globals**: The trait now checks for an explicit `$request` property (set by middleware) and returns `null` when unavailable. `whenRequested()` already handles null gracefully by including all fields.
- **`SpaManager` resolves from container**: Three `createFromGlobals()` calls in `checkAccess()` and `validateCsrfToken()` replaced with container resolution via `$this->container`.
- **`UserRepository::getCurrentUser()` uses `RequestContext`**: Extracts bearer token via `RequestContext::getBearerToken()` from the container instead of creating a Symfony request from globals.
- **`SecurityManager` accepts `ApplicationContext`**: New `?ApplicationContext $context` constructor parameter. `validateRequest()` resolves `Request` from container when none is provided.
- **`CoreProvider` request alias deduplicated**: The `'request'` service definition now delegates to `RequestProvider`'s shared `Request::class` factory instead of independently calling `createFromGlobals()`, eliminating a duplicate request construction.

### Added

- **`SessionStoreInterface::resetRequestCache()`**: Method added to the interface (previously only on `SessionStore` implementation). `TokenManager::resetRequestCache()` no longer needs a `method_exists()` guard.
- **`JsonResource::$request` property**: Nullable `Request` property that middleware can set on resources, enabling `ConditionallyLoadsAttributes::getRequest()` to access the current request without globals.

### Notes

- **No breaking changes** for callers that instantiate services via the container (the standard path). Callers that construct services directly with `new` and pass `null` for both `RequestContext` and `ApplicationContext` will now receive a `\RuntimeException` with a clear message instead of silently using stale globals.
- `RequestContext::fromGlobals()` and `ServerRequestFactory::fromGlobals()` remain available as public methods for CLI commands and bootstrap code that legitimately cannot use the container.
- `RequestProvider.php` remains the single source of truth for request creation during HTTP handling.
- `getallheaders()` / `apache_request_headers()` remain as secondary fallbacks in `TokenManager::extractTokenFromRequest()` and `JwtAuthenticationProvider::extractTokenFromRequest()` for Apache compatibility, but only after `RequestContext` is already resolved from the container.

---

## [1.32.0] - 2026-02-11 — Fomalhaut

Schema builder `alterTable` now supports the same callback-style API as `createTable`, enabling concise inline table alterations with automatic execution.

### Changed

- **`SchemaBuilderInterface::alterTable()` signature**: Now accepts an optional `?callable $callback` parameter and returns `TableBuilderInterface|self`. Without a callback, returns a fluent `TableBuilder` (existing behavior unchanged). With a callback, passes the builder to the callback, executes, and returns `$this` for chaining — mirroring the `createTable` dual-mode API.
- **`SchemaBuilder::alterTable()` implementation**: Callback path calls `gc_collect_cycles()` before `$tableBuilder->execute()` to force `ColumnBuilder` destructors to run, ensuring `finalizeColumn()` registers columns before the ALTER SQL is generated. Then calls `$this->execute()` to flush pending operations to the database, matching `createTable` behavior.

### Notes

- No breaking changes. All existing callers (including the convenience methods `addColumn`, `dropColumn`, `addIndex`, `dropIndex`, `addForeignKey`, `dropForeignKey`) use the no-callback path and continue to work unchanged.
- The `gc_collect_cycles()` call is necessary because `ColumnBuilder` uses `__destruct()` to finalize columns, and PHP may defer destruction of temporaries created inside the callback until after `execute()` reads them.

---

## [1.31.0] - 2026-02-09 — Enif

Centralized context propagation: core services and ORM receive application context during framework boot, eliminating manual `setContext()` calls scattered across the codebase.

### Added

- **`Model::setDefaultContext()`**: Static method that sets a default `ApplicationContext` for ORM static calls. When set, `Model::__callStatic()` uses this context as a fallback if no explicit context is passed as the first argument. Enables `User::find($id)` without requiring `User::find($context, $id)` after boot.
- **Framework context propagation**: `Framework::initializeCoreServices()` now sets application context on `Model`, `Utils`, `CacheHelper`, `SecureErrorResponse`, `RoutesManager`, and `ImageProcessor`. `initializeHttpLayer()` sets context on `Webhook`. `loadConfiguration()` sets context on `ConfigManager`.

### Changed

- **`AuthBootstrap::initialize()`**: Now also calls `RequestUserContext::setContext()` alongside `JWTService::setContext()` before creating authentication providers, ensuring request user resolution has access to the application context.
- **`Model::__callStatic()`**: Updated to check for an explicit `ApplicationContext` first argument, then fall back to `self::$defaultContext`, then throw. Previously always required the context as the first argument.

### Notes

- No breaking changes. Existing code passing `ApplicationContext` explicitly continues to work unchanged.
- The default context fallback is only available after `Framework::boot()` completes. Code running before boot (e.g. in service provider constructors) must still pass context explicitly.

---

## [1.30.1] - 2026-02-09 — Diphda

### Fixed

- **JWTService context initialization**: `AuthBootstrap::initialize()` now calls `JWTService::setContext()` before creating authentication providers, ensuring the JWT service has access to the application context during token operations.

### Notes

- Patch release. No breaking changes.

---

## [1.30.0] - 2026-02-09 — Diphda

Unified exception handling: consolidated two overlapping exception handlers into a single source of truth.

### Changed

- **Exception handler consolidation**: The modern `Handler` (`src/Http/Exceptions/Handler.php`) is now the single source of truth for exception rendering, reporting, and event dispatch. The legacy `ExceptionHandler` (`src/Exceptions/ExceptionHandler.php`) has been reduced from 1041 lines to a ~250-line thin bootstrap shim that registers PHP's global handlers and delegates to the DI-managed `Handler`.

- **Channel-based log routing**: `Handler::report()` now routes exceptions to named log channels (`auth`, `database`, `security`, `http`, `ratelimit`, `extensions`, `api`, `http_client`, `permissions`, `framework`) via `resolveLogChannel()`. Previously this logic was duplicated in both handlers.

- **Optimized report context**: `Handler::buildReportContext()` produces lightweight context (URI, method, IP only) for high-frequency exceptions (validation, 404) and full context (headers, memory, timing) for others. Replaces the legacy handler's three separate context-building methods.

- **Framework boot wiring**: `ExceptionHandler::register()` is now called at the top of `Framework::boot()` (before Phase 1) so PHP errors during early bootstrap are caught. After the container is built, `ExceptionHandler::setHandler()` wires the DI instance into the global handlers. The previous `setContext()` call is removed.

### Added

- **`Handler::logError()`**: Convenience method for callers outside the middleware pipeline (used by `SecureErrorResponse`). Logs with channel routing and framework/application classification.
- **`Handler::handleForTest()`**: Renders an exception and captures the response array for test assertions without producing output.
- **`Handler::mapChannel()`**: Public API for registering custom exception-to-channel mappings.
- **`Handler::setVerboseContext()`**: Toggle between full and lightweight context building.
- **`Handler::setTestMode()` / `getTestResponse()`**: Test infrastructure moved from the legacy handler to the modern Handler.
- **`ExceptionHandler::setHandler()`**: Static method to inject the DI-managed Handler into the global bootstrap shim.
- **`ExceptionHandler::minimalResponse()`**: Fallback JSON response for errors that occur before the Handler is available.

### Removed

- **Legacy rendering chain**: The `ExceptionHandler::handleException()` method no longer contains its own if/elseif chain for 10+ exception types — all rendering delegates to `Handler`.
- **Duplicate context building**: `buildContextFromRequest()`, `getOptimizedContext()`, `getLightweightContext()`, `buildBasicContext()`, `getFilteredHeaders()`, `getRateLimitInfo()`, `getRequestBodyInfo()` removed from legacy handler (absorbed into `Handler::buildReportContext()`).
- **Duplicate classification**: `isFrameworkException()` and `$channelMap` removed from legacy handler (moved to `Handler`).
- **`ExceptionHandler::setContext()`**: Now a no-op — Handler receives dependencies via DI.
- **`ExceptionHandler::setEventDispatcher()`**: Removed — Handler gets events via DI constructor.
- **`sanitizeErrorMessage()`**: Removed from legacy handler — modern Handler's render paths handle message safety.
- **`outputJsonResponse()`**: Removed — Handler returns `Response` objects.
- **`getCurrentUserFromRequest()`**: Removed — audit logging belongs in a reporter callback.

### Notes

- No breaking changes to the public API. `ExceptionHandler::logError()`, `setTestMode()`, `getTestResponse()`, `setLogger()` remain as static methods and delegate internally.
- `ExceptionHandler::setContext()` is now a no-op. Code calling it will continue to work but can be removed.
- Extension imports updated: payvia, email-notification, entrada, and aegis extensions migrated from deleted `Glueful\Exceptions\*` bridge classes to modern `Glueful\Http\Exceptions\*` namespaces.

---

## [1.29.0] - 2026-02-07 — Capella

Queue system overhaul: leaf worker mode, config normalization, distributed lock fix, and env-driven queue presets.

### Added

- **Leaf worker mode** (`queue:work process`): Spawned workers now execute jobs directly in-process via a dedicated `process` action instead of recursively invoking the manager. Supports `--sleep`, `--max-jobs`, `--max-runtime`, `--max-attempts`, `--stop-when-empty`, `--with-monitoring`, and `--emit-heartbeat` options.
- **ProcessManager `stop()` API**: New `stop(string $workerId, int $timeout = 30): bool` method for stopping and removing a worker from manager state in a single call.
- **Queue presets**: Seven env-driven queue configurations in `config/queue.php` — `critical`, `maintenance`, `default`, `high`, `emails`, `reports`, `notifications` — each with tunable workers, memory, timeout, max jobs, priority, and per-queue autoscale toggle.
- **Schedule queue env vars**: `schedule.php` now uses `SCHEDULE_QUEUE_CRITICAL`, `SCHEDULE_QUEUE_MAINTENANCE`, `SCHEDULE_QUEUE_NOTIFICATIONS`, `SCHEDULE_QUEUE_SYSTEM` instead of hardcoded queue names.
- **Top-level queue env toggles**: `QUEUE_PROCESS_ENABLED` and `QUEUE_AUTO_SCALING` added to `.env.example` for operator control.

### Changed

- **ProcessFactory leaf command**: `buildWorkerCommand()` now spawns `php glueful queue:work process --queue=...` instead of recursively calling the manager action.
- **ProcessManager config normalization**: Constructor resolves `max_workers` from `max_workers` or `max_workers_global` with fallback to `10`, then forces the canonical key.
- **AutoScaleCommand config wiring**: `initializeServices()` builds normalized config objects for both `ProcessManager` and `AutoScaler` — top-level `enabled` from `auto_scaling.enabled`, structured `auto_scale.*` thresholds, and `limits.max_workers_per_queue` from process config.
- **WorkCommand stop path**: `executeStop()` now calls `ProcessManager::stop()` instead of reaching into `WorkerProcess` directly, keeping the manager's internal state consistent.

### Fixed

- **Status payload missing runtime**: `ProcessManager::getStatus()` now includes `'runtime' => $worker->getRuntime()`, fixing the `formatDuration()` display in worker status output.
- **Distributed lock scoped per-host**: Lock key changed from `queue:manager:{queue}:{hostname}` to `queue:manager:{queue}` so the lock is truly shared across hosts in distributed deployments.
- **Queue naming inconsistency**: Hardcoded queue names in `schedule.php` replaced with env-backed variables matching `queue.php` presets. `emails` and `reports` queue `auto_scale` changed from hardcoded `false` to `env()` wrappers for consistency.

### Notes

- No breaking changes. Existing `queue:work` (manager mode) behavior is unchanged — it now spawns leaf workers internally.
- Auto-scaling is default-off for all queue presets. Enable per-queue via `*_QUEUE_AUTO_SCALE=true` env vars.

---

## [1.28.3] - 2026-02-07 — Bellatrix (Patch)

Fix CLI option shortcut collision with Symfony Console's reserved `-q` (`--quiet`).

### Fixed

- **Queue WorkCommand `-q` shortcut collision**: `--queue` option used `-q` as shortcut, which conflicts with Symfony Console's built-in `--quiet` global option. Changed shortcut to `null`. This caused `LogicException: An option with shortcut "q" already exists` when running `php glueful queue:work`.

- **Dev ServerCommand `-q` shortcut collision**: Same `-q` conflict on the `--queue` option. Changed shortcut to `null`.

- **Cache MaintenanceCommand `-q` shortcut collision**: Same `-q` conflict on the `--queue` option. Changed shortcut to `null`.

### Notes

- No breaking changes. Users who were passing `--queue` by full name are unaffected. The `-q` shortcut was never usable because it always collided with Symfony's `--quiet`.

---

## [1.28.2] - 2026-02-07 — Bellatrix (Patch)

CLI migration commands now properly discover extension migrations. PostgreSQL schema introspection is now schema-safe.

### Fixed

- **Container self-registration**: `ContainerFactory::create()` now registers the built container under `ContainerInterface` so that autowiring can inject it into CLI commands. Previously, CLI commands that received the container via constructor DI could not resolve `ContainerInterface` from the container itself.

- **Migration command DI wiring**: `RunCommand`, `StatusCommand`, and `RollbackCommand` constructors now accept optional `ContainerInterface` and `ApplicationContext` parameters and forward them to `BaseCommand`. When the framework boots these commands via the DI container, they receive the fully-configured container (with extension-registered migration paths) instead of creating a fresh one.

- **PostgreSQL schema-safe introspection**: `PostgreSQLSqlGenerator` no longer hardcodes `public` schema. All introspection queries now follow the active schema via `current_schema()`:
  - `tableExistsQuery()`, `columnExistsQuery()`, `getTableSchemaQuery()`, `getTablesQuery()` filter by `current_schema()`
  - `getTableColumns()` information_schema query scoped with `table_schema = current_schema()`
  - Primary key and unique constraint queries (`pg_constraint`/`pg_class`) join `pg_namespace` and filter `nspname = current_schema()`
  - Index query (`pg_index`) joins `pg_namespace` and filters `current_schema()`
  - Foreign key query scoped with schema-aware joins (`kcu.constraint_schema`, `rc.constraint_schema`, `ccu.constraint_schema`) and `table_schema = current_schema()`

### Notes

- No breaking changes. Fixes `php glueful migrate:run`, `migrate:status`, and `migrate:rollback` not seeing migrations registered by extensions via `loadMigrationsFrom()`.
- Root cause: CLI commands were constructing a new container in `BaseCommand::__construct()` when no container was injected, losing the migration paths that extensions had registered during boot.
- PostgreSQL fix enables correct behavior in multi-tenant setups and any deployment using non-`public` schemas.

---

## [1.28.1] - 2026-02-06 — Bellatrix (Patch)

Route stability fixes: Resolved route prefix leakage across extensions and cache-aware route registration.

### Fixed

- **Router group stack cleanup**: `Router::group()` now uses `try/finally` to ensure `groupStack` and `middlewareStack` are always cleaned up, even when exceptions occur inside group callbacks. Previously, an exception inside a nested route group would leave stale prefixes on the stack, causing all subsequent route registrations to inherit incorrect path prefixes.

- **Cache-aware static route registration**: Router now tracks when routes were pre-loaded from cache and allows extensions to overwrite cached route entries instead of throwing `LogicException("Route already defined")`. The `RouteCache` signature does not include extension route files, so extensions must re-register their routes on every request — this fix prevents the duplicate detection from blocking that re-registration.

- **Dynamic route cache deduplication**: When routes are loaded from cache, dynamic route re-registration now replaces the cached entry in both `dynamicRoutes` and `routeBuckets` instead of appending a duplicate, preventing stale handlers from taking priority and eliminating performance overhead from doubled route entries.

### Notes

- No breaking changes. Stability patch for applications using route caching with extensions.
- Root cause was a combination of: (1) missing `try/finally` in `Router::group()`, and (2) `RouteCache` not tracking extension route files in its signature, causing extensions to conflict with cached routes on subsequent requests.

---

## [1.28.0] - 2026-02-05 — Bellatrix

Route caching support: Refactored controllers to use cacheable route syntax.

### Changed

#### ResourceController Refactoring

- **Removed wrapper methods**: Eliminated redundant wrapper pattern for cleaner code
  - Removed: `listResources`, `showResource`, `createResource`, `updateResource`, `deleteResource`, `bulkDeleteResources`, `bulkUpdateResources`

- **Renamed methods to RESTful conventions**:
  - `get()` → `index()` - List resources with pagination
  - `getSingle()` → `show()` - Get single resource by UUID
  - `post()` → `store()` - Create new resource
  - `put()` → `update()` - Update existing resource
  - `delete()` → `destroy()` - Delete resource
  - New: `destroyBulk()` - Bulk delete resources
  - New: `updateBulk()` - Bulk update resources

- **Methods now accept Request directly**: All public methods accept `Symfony\Component\HttpFoundation\Request` and extract parameters from `$request->attributes`

- **Added imports**: `Glueful\Helpers\RequestHelper` and `Symfony\Component\HttpFoundation\Request`

#### Route Definitions (resource.php)

- Updated all routes to use controller syntax `[Controller::class, 'method']` instead of closures
- Routes are now fully cacheable for improved performance

### Breaking Changes

- **Method signature changes**: If you extended `ResourceController` and overrode methods, update to new signatures:

  ```php
  // Before
  public function get(array $params, array $queryParams)

  // After
  public function index(Request $request): Response
  ```

- **Route handler references**: Any code referencing old method names must be updated

#### Route Caching Infrastructure

- **RouteCompiler validation methods**: Added handler validation to detect non-cacheable routes
  - `validateHandlers()`: Validates all routes for caching compatibility
  - `hasClosures()`: Checks if validation issues contain closure handlers
  - `getClosureRoutes()`: Returns list of routes using closure handlers
  - Warns developers about closure handlers that prevent caching

- **RouteCache closure detection**: Enhanced cache management with closure awareness
  - `cacheContainsClosures()`: Detects closures in cached routes
  - Automatically invalidates cache when closures are detected
  - Logs warnings to help identify routes needing conversion

### Notes

- Controller methods now follow Laravel-style naming conventions (index, show, store, update, destroy)
- The `BulkOperationsTrait` methods (`bulkDelete`, `bulkUpdate`) remain unchanged as internal helpers
- Route caching can significantly improve application performance by avoiding route file parsing on each request
- Use `./glueful route:debug` to identify routes still using closure syntax

---

## [1.27.0] - 2026-02-04 — Avior

Developer experience improvements: new CLI commands, route cache signatures, transaction callbacks, and extension management.

### Added

#### New CLI Commands

- **`doctor`** (`System/DoctorCommand.php`): Quick health checks for local development
  - Checks environment, app key, cache, database, route cache, and storage permissions
  - Provides pass/fail status with detailed messages

- **`env:sync`** (`System/EnvSyncCommand.php`): Sync `.env.example` from config
  - Scans `config/*.php` for `env()` usage
  - Updates `.env.example` with discovered variables
  - `--apply` option creates/updates `.env` with missing keys

- **`route:debug`** (`Route/DebugCommand.php`): Dump resolved routes
  - Lists all routes with middleware and handlers
  - Filter by `--method`, `--path`, or `--name`

- **`route:cache:clear`** (`Route/CacheClearCommand.php`): Clear route cache
- **`route:cache:status`** (`Route/CacheStatusCommand.php`): Show route cache status and signature

- **`cache:inspect`** (`Cache/InspectCommand.php`): Inspect cache driver and extension status
  - Shows driver configuration, PHP extension availability, and runtime stats

- **`test:watch`** (`Test/WatchCommand.php`): Run tests on file changes
  - File watcher with configurable polling interval
  - `--command` to specify test command (default: `composer test`)

- **`dev:server`** (`Dev/ServerCommand.php`): Development server alias

#### Database Transaction Callbacks

- **`Connection::afterCommit(callable)`**: Register callback to execute after transaction commits
  - Use cases: search index updates, cache invalidation, event dispatching
  - Callbacks promoted to parent level for nested transactions (savepoints)
  - Executes immediately if not in transaction

- **`Connection::afterRollback(callable)`**: Register callback to execute after rollback
  - Callbacks discarded if nested transaction rolls back

- **Shared TransactionManager**: `Connection::getTransactionManager()` returns shared instance
  - Ensures transaction state and callbacks tracked across all QueryBuilders

### Changed

#### Extensions Enable/Disable Commands

- **EnableCommand**: Now edits `config/extensions.php` instead of only printing instructions
  - Resolves extension by slug or FQCN using ExtensionManager metadata
  - Inserts provider class into the `'enabled'` array using regex-based editing
  - Idempotent: no-op with message if already enabled
  - Warns if `'only'` mode is configured (enabled list ignored)
  - Development-only: blocks execution in production environment

- **DisableCommand**: Comments out provider line instead of removing it
  - Safer approach preserves trailing commas and prevents empty array issues
  - Detects already-commented entries to avoid double-commenting

- **New options**: `--dry-run` (preview), `--backup` (create .bak file)

#### Route Cache Improvements

- **Signature-based invalidation**: Replaced TTL-based caching with content-hash signatures
  - SHA-256 hash of route file paths, mtimes, and sizes
  - Cache invalidates when any source file changes
  - Works consistently across all environments

- **RouteCache API additions**:
  - `getSignature()`: Get current computed signature
  - `getCachedSignature()`: Get signature from cache file
  - `getSourceFiles()`: List all route source files
  - `getCacheFilePath()`: Get cache file path

- **RouteCompiler**: Now includes signature in compiled cache output

#### Application Lifecycle

- **RequestLifecycle integration**: `Application` now calls `beginRequest()` and `endRequest()`
  - Enables proper cleanup for long-running servers (RoadRunner, Swoole)

- **Framework improvements**:
  - Dotenv loading uses `DOTENV_LOADED` flag to prevent double-loading
  - Route caching controlled by `ROUTE_CACHE` env var (default: true)
  - No longer production-only

### Notes

- All new commands use `#[AsCommand]` attribute for auto-discovery
- Transaction callbacks are compatible with the Meilisearch extension's `afterCommit()` pattern
- Route cache signature ensures deploy-time invalidation without manual cache clearing

---

## [1.26.0] - 2026-01-31 — Atria

Fixed extension discovery reliability and improved ExtensionManager efficiency.

### Fixed

#### Extension Discovery Fallback

- **PackageManifest**: Now falls back to `installed.json` when `installed.php` doesn't yield providers
  - Composer's `installed.php` is optimized for speed but may omit the `extra` field in some configurations
  - `installed.json` contains complete package metadata including the `glueful.provider` specification
  - Discovery now tries `installed.php` first, falls back to `installed.json` if no providers found

#### ExtensionManager Lazy Discovery

- **getProviders()**: Added lazy auto-discovery for CLI commands that create their own container
  - Commands calling `getProviders()` without explicit `discover()` now auto-discover extensions
  - Prevents empty provider lists in documentation generation and other CLI tools

### Changed

- **ExtensionManager**: Added `$discovered` flag to prevent redundant discovery
  - `discover()` now sets flag immediately to ensure it runs exactly once
  - `getProviders()` checks `!$this->discovered` instead of checking empty providers array
  - Prevents unnecessary re-discovery when zero extensions are legitimately installed

---

## [1.25.0] - 2026-01-31 — Ankaa

Enhanced RouteManifest with automatic discovery of multiple application route files, enabling domain-driven route organization.

### Added

#### Multi-File Route Discovery

- **Auto-discovery**: All `*.php` files in the application's `routes/` directory are automatically discovered and loaded
- **Alphabetical loading**: Route files are sorted and loaded in alphabetical order for deterministic behavior
- **Exclusion patterns**: Files starting with underscore (`_helpers.php`, `_shared.php`) are excluded as partials/includes
- **Double-load prevention**: Tracks loaded files to prevent duplicate route registration

#### Route Loading Priority

- **Application routes first**: App routes load before framework routes for highest priority matching
- **Framework fallback**: Generic framework routes (e.g., `/{resource}/{uuid}`) act as fallbacks
- **Flexible prefixing**: Application controls its own route prefixes (no auto-wrapping)

#### Domain-Driven Route Organization

Applications can now split large route files into domain-specific files:

```
routes/
├── api.php           # Main/shared routes
├── identity.php      # Auth, profile, preferences
├── parps.php         # Domain-specific routes
├── social.php        # Follow, block
├── engagement.php    # Reactions, comments
└── _helpers.php      # Shared helpers (excluded)
```

### Changed

- **RouteManifest::generate()**: Now returns `app_routes_dir` and `app_routes_exclude` instead of `core_routes`
- **RouteManifest::load()**: Calls new `loadAppRoutes()` method for auto-discovery
- **RouteManifest::reset()**: Now clears loaded files tracking for test isolation

---

## [1.24.0] - 2026-01-31 — Alpheratz

Comprehensive encryption service providing secure, easy-to-use AES-256-GCM encryption for strings, files, and database fields with key rotation support.

### Added

#### Encryption Service Core

- **EncryptionService** (`src/Encryption/EncryptionService.php`):
  - AES-256-GCM authenticated encryption (industry standard)
  - Random 12-byte nonce per encryption (prevents ciphertext repetition)
  - 16-byte authentication tag (tamper detection)
  - Key ID in output format for O(1) key lookup during rotation
  - Self-identifying output format: `$glueful$v1$<key_id>$<nonce>$<ciphertext>$<tag>`

- **String encryption methods**:
  - `encrypt($value, $aad)` - Encrypt UTF-8 strings with optional AAD
  - `decrypt($encrypted, $aad)` - Decrypt with AAD validation
  - `encryptBinary($bytes, $aad)` - Encrypt arbitrary binary data
  - `decryptBinary($encrypted, $aad)` - Decrypt binary data
  - `isEncrypted($value)` - Detect encrypted strings by format

- **File encryption methods**:
  - `encryptFile($source, $dest)` - Encrypt entire files
  - `decryptFile($source, $dest)` - Decrypt encrypted files
  - `encryptStream($inputStream)` - Stream-based encryption

- **Key management**:
  - `rotateKey($newKey)` - Rotate to a new encryption key
  - `encryptWithKey($value, $key, $aad)` - Encrypt with specific key
  - `decryptWithKey($encrypted, $key, $aad)` - Decrypt with specific key

#### AAD (Additional Authenticated Data) Support

- Context binding prevents cross-field attacks
- Encrypting with `aad: 'user.ssn'` requires same AAD for decryption
- Prevents copying encrypted SSN to API key field (AAD mismatch = decryption failure)

#### Key Rotation Support

- **Previous keys configuration**: `encryption.previous_keys` array in config
- **O(1) key lookup**: Key ID in ciphertext enables direct lookup (no trial decryption)
- **Seamless migration**: Old data decrypts with previous keys, new data uses current key

#### Exception Classes

- **EncryptionException** (`src/Encryption/Exceptions/EncryptionException.php`): Base exception class
- **DecryptionException**: Decryption failures (wrong key, tampered data, invalid format)
- **InvalidKeyException**: Key validation errors (wrong length, invalid base64)
- **KeyNotFoundException**: Missing or unconfigured encryption key

#### Base64 Key Support

- Keys can use `base64:` prefix for safe storage in environment files
- Example: `APP_KEY=base64:AbCdEfGhIjKlMnOpQrStUvWxYz012345678901234=`
- Automatically decoded during service initialization

#### CLI Commands

- **`encryption:test`** (`src/Console/Commands/Encryption/TestCommand.php`):
  - Verifies encryption service is working correctly
  - Runs 6 self-tests: basic encryption, binary, AAD binding, tamper detection, random nonce, isEncrypted detection
  - Clear pass/fail output with error details

- **`encryption:file`** (`src/Console/Commands/Encryption/FileCommand.php`):
  - Encrypt or decrypt files from command line
  - Usage: `php glueful encryption:file encrypt /path/to/file`
  - Options: `--force` (overwrite), `--delete-source` (remove original)
  - Auto-generates destination with `.enc` extension

- **`encryption:rotate`** (`src/Console/Commands/Encryption/RotateCommand.php`):
  - Re-encrypt database columns with current key
  - Usage: `php glueful encryption:rotate --table=users --columns=ssn,api_secret`
  - Options: `--batch-size` (default 100), `--dry-run` (preview changes), `--primary-key`
  - Progress reporting with summary statistics

#### Configuration

- **`config/encryption.php`**:
  - `key` - Primary encryption key (from `APP_KEY` env)
  - `cipher` - Algorithm (AES-256-GCM only)
  - `previous_keys` - Array of old keys for rotation (from `APP_PREVIOUS_KEYS` env)
  - `files.chunk_size` - Streaming chunk size (default 64KB)
  - `files.extension` - Encrypted file extension (default `.enc`)

#### Test Coverage

- **EncryptionServiceTest** (`tests/Unit/Encryption/EncryptionServiceTest.php`): 32 tests covering:
  - Core encryption format and round-trip validation
  - Random nonce generation (different output each time)
  - Wrong key and tampered data detection
  - Invalid format handling
  - AAD binding (same AAD, wrong AAD, missing AAD, context swapping prevention)
  - Key validation (missing, too short, too long, base64 prefixed, invalid base64)
  - Binary data handling (non-UTF8 bytes, UTF8 rejection)
  - Key rotation (previous key via key ID, key not found, rotate method)
  - File encryption (create output, restore original, large files, missing source)
  - Custom key encryption methods
  - Error handling for corrupted ciphertext

### Documentation

- **Implementation plan**: `docs/implementation-plans/encryption-service.md` marked as Implemented
- Comprehensive API documentation with usage examples
- Key rotation workflow documentation
- Database column sizing guide for encrypted fields

## [1.23.0] - 2026-01-31 — Aldebaran

Enhanced blob storage system with visibility controls, signed URLs for secure temporary access, and comprehensive test coverage.

### Added

#### Blob Visibility Support

- **Per-blob visibility**: Blobs can now be marked as `public` or `private` individually
  - Upload requests accept `visibility` parameter (`public` or `private`)
  - Defaults to configured `uploads.default_visibility` (private by default)
  - Public blobs accessible without auth (unless global access is `private`)
  - Private blobs require authentication or valid signed URL
- **Database schema**: Added `visibility` column to `blobs` table with index
- **BlobRepository**: Updated default fields to include `visibility` and `storage_type`

#### Signed URL Support

- **SignedUrl helper class** (`src/Support/SignedUrl.php`):
  - HMAC-based URL signing with configurable secret and algorithm
  - Time-limited access with customizable TTL (default 1 hour, max 7 days)
  - Supports additional query parameters in signed URLs
  - Validates signatures and expiration timestamps
  - Falls back to `APP_KEY` if no dedicated secret configured
- **New endpoint**: `POST /blobs/{uuid}/signed-url` generates temporary access URLs
  - Requires authentication
  - Optional `ttl` query parameter to customize expiration
  - Returns signed URL, expiration time, and expiration timestamp
- **Automatic validation**: Private blob retrieval accepts signed URLs as alternative to auth

#### Configuration Options

- **uploads.default_visibility**: Set default visibility for new uploads (`public` or `private`)
- **uploads.signed_urls.enabled**: Enable/disable signed URL generation (default: true)
- **uploads.signed_urls.secret**: Dedicated secret for URL signing (falls back to APP_KEY)
- **uploads.signed_urls.ttl**: Default TTL in seconds (default: 3600)

#### Test Coverage

- **SignedUrlTest** (`tests/Unit/Support/SignedUrlTest.php`): 17 tests covering:
  - URL generation and validation
  - Expiration handling
  - Signature tampering detection
  - Parameter inclusion and validation
  - Different secrets producing different signatures
  - Port and existing query param handling
- **UploadControllerTest** (`tests/Unit/Controllers/UploadControllerTest.php`): 38 tests covering:
  - Resize parameter parsing (width, height, quality, format, fit)
  - Cache key generation and consistency
  - MIME type detection and format conversion
  - Access control logic for private/public/upload_only modes
  - Path prefix building and sanitization
  - Disk resolution from blob metadata
  - Cache-Control header generation
  - Visibility resolution logic

### Changed

- **UploadController**: Enhanced with visibility-aware access control
  - `checkBlobAccess()` method validates visibility, auth, and signed URLs
  - `hasValidSignature()` method verifies signed URL parameters
  - Upload response now includes `visibility` field
- **routes/blobs.php**: Added signed URL generation endpoint with auth middleware
- **config/uploads.php**: Added `default_visibility` and `signed_urls` configuration sections

### Fixed

- **Blob access control**: Now properly respects per-blob visibility settings combined with global access mode

## [1.22.0] - 2026-01-30 — Achernar

Major refactoring release replacing global state with explicit dependency injection via `ApplicationContext`. This release improves testability, enables multi-app support, and prepares the framework for long-running server environments (Swoole, RoadRunner).

### Added

#### Console Command Auto-Discovery

- **Automatic command registration**: Commands are now auto-discovered from `src/Console/Commands/` directory
  - Scans for classes with `#[AsCommand]` attribute
  - Skips abstract classes automatically
  - No manual registration required - just create a command file
- **Production caching**: Cached manifest for fast startup in production
  - Auto-generated on first production run
  - Zero overhead after initial cache
- **New CLI command**: `commands:cache` for cache management
  - `php glueful commands:cache` - Generate cache
  - `php glueful commands:cache --clear` - Clear cache
  - `php glueful commands:cache --status` - Show cache info
- **Removed duplication**: Eliminated duplicate command lists from `Application.php` and `ConsoleProvider.php`

#### ApplicationContext Dependency Injection

- **Explicit context parameter**: All helper functions now require `ApplicationContext` as first parameter:
  - `config($context, $key, $default)` - Get configuration values
  - `app($context, $id)` - Resolve services from container
  - `base_path($context, $path)` - Get base path
  - `storage_path($context, $path)` - Get storage path
  - `container($context)` - Get DI container
  - `auth($context)` - Get authentication guard
  - `image($context, $source)` - Get image processor

- **QueueContextHolder** (`src/Events/QueueContextHolder.php`): New class to hold queue context, replacing deprecated static trait properties (PHP 8.3 compatibility)

- **PHPStan banned_code rule**: Added rule in `phpstan.neon` to prevent `$GLOBALS` usage in new code

#### Service Provider Updates

- **ServiceProvider interface**: `register()` and `boot()` methods now receive `ApplicationContext` parameter
- **BaseExtension**: Extension `boot()` method receives `ApplicationContext` for proper DI access

### Changed

#### Authentication Services

- **AuthenticationService**: Refactored to use `ApplicationContext` for all container and config access
- **SessionStore**: Updated to resolve dependencies via context injection
- **TokenManager**: Converted static caches to instance-based with context support
- **JwtAuthenticationProvider**: Added context-aware configuration loading
- **SessionCacheManager**: Updated for explicit context dependency

#### Core Services

- **ResolvesSessionStore trait**: Simplified to provide default `getContext()` method, removing redundant `method_exists()` checks
- **CacheFactory**: Updated Redis/Memcached configuration to use context
- **CoreProvider**: Refactored logging and service registration for context injection
- **ImageProvider**: Simplified config loading with helper closure pattern

#### Console Commands

- All scaffold and system commands updated to use `$this->getContext()` for configuration access
- Improved output formatting in Database/StatusCommand, Extensions/ListCommand, and others

#### Routes

- Route files (`auth.php`, `docs.php`, `health.php`, `resource.php`) updated to receive and use `ApplicationContext`

### Fixed

#### PHP 8.3 Compatibility

- **InteractsWithQueue trait**: Fixed deprecated static trait method/property access by moving context storage to `QueueContextHolder` class

#### PHPStan Errors

- **ErrorResponseDTO**: Fixed duplicate `$context` property declaration, renamed array context to `$errorContext`
- **SendNotification job**: Fixed visibility mismatch (`private` → `protected`) for `$context` property
- **AuthFailureTracker**: Added missing return statement in `createCacheInstance()` method

#### PHPCS Line Length Violations

- Fixed 25+ files with lines exceeding 120 characters:
  - `CacheFactory.php`, `CoreProvider.php`, `ImageProvider.php`
  - `JwtAuthenticationProvider.php`, `RouteManifest.php`
  - `helpers.php`, `Database/StatusCommand.php`
  - `Extensions/ListCommand.php`, `Extensions/ClearCommand.php`
  - `OpenApiDocsCommand.php`, `ModelCommand.php`, `CreateListenerCommand.php`

#### Test Suite

- **ServiceProviderTest**: Updated `TestServiceProvider` method signatures to match parent class
- **RouterTest**: Added `ApplicationContext` initialization for `RouteCache`
- **AttributeRouteLoaderTest**: Fixed test container to provide `ApplicationContext`
- **RouterIntegrationTest**: Proper context setup for route cache testing
- **CliIntegrationTest**: Skip tests gracefully when database not configured

### Removed

- **ConfigurationCache static class**: Functionality moved to `ApplicationContext::getConfig()`
- **Legacy phpstan.neon excludes**: Removed references to non-existent files (`bootstrap.php`, `index.php`, `apiDefinitionLoader.php`, etc.)

### Migration Guide

#### Updating Helper Function Calls

```php
// Before (1.21.x)
$value = config('app.name');
$service = app(MyService::class);
$path = base_path('storage');

// After (1.22.0)
$value = config($context, 'app.name');
$service = app($context, MyService::class);
$path = base_path($context, 'storage');
```

#### Updating Service Providers

```php
// Before (1.21.x)
class MyProvider extends ServiceProvider
{
    public function register(): void { }
    public function boot(): void { }
}

// After (1.22.0)
class MyProvider extends ServiceProvider
{
    public function register(ApplicationContext $context): void { }
    public function boot(ApplicationContext $context): void { }
}
```

#### Updating Extensions

```php
// Before (1.21.x)
class MyExtension extends BaseExtension
{
    public function boot(): void
    {
        $setting = config('my.setting');
    }
}

// After (1.22.0)
class MyExtension extends BaseExtension
{
    public function boot(ApplicationContext $context): void
    {
        $setting = config($context, 'my.setting');
    }
}
```

---

## [1.21.0] - 2026-01-24 — Mira

Feature release refactoring the file upload system with improved architecture, pure PHP media metadata extraction, and enhanced configurability.

### Added

#### File Uploader Refactoring (`src/Uploader/`)

- **ThumbnailGenerator**: New dedicated class for thumbnail creation:
  - Uses ImageProcessor (Intervention Image) for high-quality thumbnail generation
  - Configurable width, height, and quality settings via config or method parameters
  - Configurable supported formats (JPEG, PNG, GIF, WebP by default)
  - Configurable thumbnail subdirectory for organized storage
  - `generate()` - Create thumbnails with automatic storage
  - `supports()` - Check if MIME type supports thumbnail generation
  - `getSupportedFormats()` - Get list of supported formats

- **MediaMetadataExtractor**: New class for extracting media metadata using getID3:
  - Pure PHP implementation - no external binaries required (removed ffprobe dependency)
  - Supports images, audio, and video files
  - `extract()` - Extract metadata from any media file
  - Falls back gracefully when getID3 is not installed or file type unsupported

- **MediaMetadata**: New readonly value object for type-safe metadata:
  - Properties: `type`, `width`, `height`, `durationSeconds`
  - Helper methods: `isImage()`, `isVideo()`, `isAudio()`, `hasDimensions()`
  - `getAspectRatio()` - Calculate aspect ratio from dimensions
  - `getFormattedDuration()` - Format duration as HH:MM:SS
  - `toArray()` - Convert to array representation

- **FileUploader Enhancements**:
  - New `uploadMedia()` method for media files with automatic thumbnail generation
  - `maybeGenerateThumbnail()` checks global config toggle before generating
  - Simplified internal code by delegating to new specialized classes

#### Configuration

- **filesystem.uploader**: New uploader configuration section in `config/filesystem.php`:
  - `thumbnail_enabled` - Global toggle for thumbnail generation (env: `THUMBNAIL_ENABLED`)
  - `thumbnail_width` - Default thumbnail width (env: `THUMBNAIL_WIDTH`, default: 400)
  - `thumbnail_height` - Default thumbnail height (env: `THUMBNAIL_HEIGHT`, default: 400)
  - `thumbnail_quality` - JPEG quality 1-100 (env: `THUMBNAIL_QUALITY`, default: 80)
  - `thumbnail_formats` - Array of MIME types supporting thumbnails (null = defaults)
  - `thumbnail_subdirectory` - Subdirectory for thumbnails (env: `THUMBNAIL_SUBDIRECTORY`, default: 'thumbs')
  - `allowed_mime_types` - Allowed upload MIME types (null = defaults)

#### Documentation

- **config/storage.php**: Added installation instructions for optional Flysystem adapters:
  - S3/MinIO/DigitalOcean Spaces/Wasabi: `league/flysystem-aws-s3-v3`
  - Google Cloud Storage: `league/flysystem-google-cloud-storage`
  - Azure Blob Storage: `league/flysystem-azure-blob-storage`
  - SFTP: `league/flysystem-sftp-v3`
  - FTP: `league/flysystem-ftp`

- **docs/content/3.features/file-uploads.md**: Updated documentation:
  - Added storage backend installation instructions
  - Added "Media Uploads" section with `uploadMedia()` examples
  - Added thumbnail configuration documentation
  - Added metadata extraction with getID3 documentation
  - Updated Image Gallery example to use new API

### Changed

- **FileUploader**: Refactored to use new ThumbnailGenerator and MediaMetadataExtractor classes
- **Dependencies**: Added `james-heinrich/getid3: ^1.9` for pure PHP media metadata extraction

### Removed

- **ffprobe dependency**: Removed shell_exec calls to ffprobe for video duration extraction
- **Legacy thumbnail code**: ~200 lines of thumbnail generation code moved to ThumbnailGenerator

### Fixed

- **FileUploader PHPStan/Intelephense errors**:
  - Fixed undefined constant `ALLOWED_MIME_TYPES` → `DEFAULT_ALLOWED_MIME_TYPES`
  - Added `(bool)` casts for mixed types in boolean context
  - Split long lines (740-742, 856) for readability
  - Changed `finfo_close()` to OOP style `new \finfo()` (deprecated in PHP 8.1)
  - Removed deprecated `imagedestroy()` calls (GdImage auto-freed in PHP 8.0+)

### Configuration Examples

```php
// Disable thumbnail generation globally
// .env
THUMBNAIL_ENABLED=false

// Custom thumbnail dimensions
THUMBNAIL_WIDTH=300
THUMBNAIL_HEIGHT=300
THUMBNAIL_QUALITY=90
THUMBNAIL_SUBDIRECTORY=thumbnails

// Custom thumbnail formats in config/filesystem.php
'uploader' => [
    'thumbnail_formats' => [
        'image/jpeg',
        'image/png',
        'image/webp',
    ],
],
```

### Usage Examples

```php
use Glueful\Uploader\FileUploader;

// Upload media with automatic thumbnail generation
$uploader = new FileUploader($storage);
$result = $uploader->uploadMedia($file, 'media/images');

// Result includes:
// - file: uploaded file info
// - thumbnail: thumbnail URL (if generated)
// - metadata: MediaMetadata object with dimensions/duration

// Access metadata
$metadata = $result['metadata'];
if ($metadata->isImage()) {
    echo "Image: {$metadata->width}x{$metadata->height}";
} elseif ($metadata->isVideo()) {
    echo "Video duration: " . $metadata->getFormattedDuration();
}
```

---

## [1.20.0] - 2026-01-24 — Regulus

Minor release focusing on framework simplification by removing unused subsystems and improving API URL structure.

### Changed

#### Resource Routes URL Structure

- **Added `/data` prefix** to generic CRUD routes to avoid conflicts with custom application routes:
  - `GET /api/v1/{resource}` → `GET /api/v1/data/{table}`
  - `GET /api/v1/{resource}/{uuid}` → `GET /api/v1/data/{table}/{uuid}`
  - `POST /api/v1/{resource}` → `POST /api/v1/data/{table}`
  - `PUT /api/v1/{resource}/{uuid}` → `PUT /api/v1/data/{table}/{uuid}`
  - `DELETE /api/v1/{resource}/{uuid}` → `DELETE /api/v1/data/{table}/{uuid}`
  - Bulk operations follow same pattern: `/api/v1/data/{table}/bulk`
- **Renamed route parameter** from `{resource}` to `{table}` for clarity
- **Simplified path extraction** using `$request->attributes->get()` instead of manual parsing
- **Updated OpenAPI annotations** with new paths and "Data" tag

#### Rate Limiting Consolidation

- **Consolidated to single rate limiting system**: Removed basic rate limiting in favor of enhanced system
- **Enhanced system features**: Tier-based limits, multiple algorithms (sliding/fixed/token bucket), IETF-compliant headers
- **Updated `rate_limit` middleware alias** to point to `EnhancedRateLimiterMiddleware`

### Removed

#### Async/Fiber System

- **Removed entire `src/Async/` directory** (~30 files):
  - `FiberScheduler` - Cooperative task scheduler
  - `Promise` - Promise-style async wrapper
  - `CurlMultiHttpClient` - Parallel HTTP client
  - `AsyncStream`, `BufferedAsyncStream` - Non-blocking I/O
  - Task types: `FiberTask`, `TimeoutTask`, `DelayedTask`, `RepeatingTask`
  - Exceptions, contracts, and instrumentation
- **Removed `AsyncProvider`** service provider
- **Removed `config/async.php`** configuration file
- **Removed async helper functions** from `helpers.php`:
  - `scheduler()`, `async()`, `await()`, `await_all()`, `await_race()`
  - `async_sleep()`, `async_sleep_default()`, `async_stream()`, `cancellation_token()`
- **Removed async tests** from `tests/Unit/Async/` and `tests/Integration/Async/`
- **Removed async documentation** from docs site

#### Basic Rate Limiting System

- **Removed `src/Security/RateLimiter.php`** and related classes:
  - `AdaptiveRateLimiter`, `RateLimiterRule`, `RateLimiterDistributor`
- **Removed `RateLimiterMiddleware`** (basic version)
- **Removed `RateLimitingTrait`** from controllers
- **Removed `rate_limiter` section** from `config/security.php`
- **Removed rate limiting method calls** from controllers:
  - `ConfigController`, `MetricsController`, `HealthController`, `ExtensionsController`
  - `ResourceController`, `BulkOperationsTrait`

#### Unused Configuration

- **Removed `ENABLE_AUDIT`** from `.env.example` (audit logging uses activity_logs table directly)
- **Removed pagination config** from `.env.example` and `config/app.php` (unused, PaginationBuilder uses hardcoded defaults)

### Migration

#### Resource Routes

Update any client code or documentation referencing the old resource URLs:

```diff
- GET /api/v1/users
+ GET /api/v1/data/users

- POST /api/v1/orders
+ POST /api/v1/data/orders

- PUT /api/v1/products/abc-123
+ PUT /api/v1/data/products/abc-123
```

#### Rate Limiting

If you were using the basic rate limiting trait methods in custom controllers, remove the calls:

```diff
  public function index()
  {
-     $this->rateLimit('my_endpoint');
-     $this->requireLowRiskBehavior();
      // ... controller logic
  }
```

Rate limiting is now handled via middleware. Apply rate limits using the `rate_limit` middleware:

```php
$router->get('/endpoint', $handler)->middleware(['rate_limit:100,60']);
```

#### Async System

If you were using the async helpers (unlikely as they weren't integrated into core), migrate to:

- **Guzzle Promises** for async HTTP requests
- **Queue system** for background job processing

---

## [1.19.2] - 2026-01-24 — Canopus

Patch release consolidating ValidationException classes, improving SQL query building, and enhancing cross-database compatibility.

### Changed

#### ValidationException Consolidation

- **Removed Legacy ValidationException**: Consolidated from 3 classes to 1:
  - Removed `Glueful\Exceptions\ValidationException` (legacy)
  - Removed `Glueful\Uploader\ValidationException` (empty class)
  - All code now uses `Glueful\Validation\ValidationException`
- **Updated Files**: AuthController, ValidationHelper, ConfigController, BaseController, ControllerCommand, Handler, ExceptionHandler, FileUploader, UserRepository
- **Static Factory Pattern**: All validation throws now use `ValidationException::forField()`, `forFields()`, or `withErrors()`

#### Database Query Building Improvements

- **PaginationBuilder**: Improved regex patterns for ORDER BY and LIMIT removal:
  - Handles `LIMIT ?`, `LIMIT 10, 20` (MySQL offset syntax), `LIMIT ? OFFSET ?`
  - ORDER BY regex now safely stops at LIMIT, OFFSET, FOR UPDATE, or end of query
  - Added detection for UNION, INTERSECT, EXCEPT, CTEs (`WITH ... AS`), and window functions (`OVER`)
- **QueryValidator**: Added `schema.table` format support in table name validation
- **WhereClause**:
  - Expanded valid operators: added `IS`, `IS NOT`, `BETWEEN`, `NOT BETWEEN`, `REGEXP`, `RLIKE`, `ILIKE`
  - Added operator injection protection
  - Invalid NULL comparisons (`column > NULL`) now throw clear exceptions

#### Documentation Improvements

- **ParameterBinder**: Updated comment to reflect cross-database compatibility (not just PostgreSQL)
- **QueryBuilder**: Updated GROUP BY comment to reflect SQL standard compliance (not just PostgreSQL-specific)
- **WhereClause**: Cleaned up duplicate PHPDoc blocks

### Fixed

- **Handler.php**: Fixed PHPStan `empty()` warning using strict comparison `$errors !== []`
- **Handler.php**: Fixed Intelephense warning for dynamic `getStatusCode()` call using callable pattern
- **ValidationHelper.php**: Fixed variable shadowing in foreach loop (`$field` → `$errorField`)
- **BaseController**: Updated `validationError()` type hint to accept new error format

### Removed

- **ValidationProvider**: Removed unnecessary backwards compatibility binding for `Validator::class`
- **UserRepository**: Simplified `createValidatorInstance()` to use direct instantiation instead of DI lookup

### Migration

If you were using the legacy ValidationException directly:

```diff
- use Glueful\Exceptions\ValidationException;
+ use Glueful\Validation\ValidationException;

- throw new ValidationException('Error message');
+ throw ValidationException::forField('field', 'Error message');

- throw new ValidationException(['field' => 'error']);
+ throw ValidationException::withErrors(['field' => 'error']);
```

---

## [1.19.1] - 2026-01-22 — Canopus

Patch release simplifying API configuration by consolidating URL and version environment variables.

### Changed

- **Simplified URL Configuration**: All URLs now derive from single `BASE_URL` variable
  - Removed `API_BASE_URL` — no longer needed, use `BASE_URL` instead
  - Example: `BASE_URL=https://api.example.com` → API at `/api/v1/`, docs at `/docs/`

- **Simplified Version Configuration**: Consolidated to single `API_VERSION` variable
  - Removed `API_VERSION_FULL` — docs version now derived as `{API_VERSION}.0.0`
  - Removed `API_DEFAULT_VERSION` — use `API_VERSION` instead
  - Changed format from `API_VERSION=v1` to `API_VERSION=1` (integer)
  - The "v" prefix is added automatically in URL paths

- **Updated Files**:
  - `.env.example` — Simplified to just `BASE_URL` and `API_VERSION`
  - `config/app.php` — URLs derive from `BASE_URL`, removed `version_full`
  - `config/api.php` — Uses `API_VERSION` for versioning default
  - `config/documentation.php` — Derives version from `API_VERSION`
  - `CSRFMiddleware` — Uses `BASE_URL` for path extraction

### Migration

Update your `.env` file:

```diff
- BASE_URL=http://localhost:8000
- API_BASE_URL=http://localhost:8000
- API_VERSION=v1
- API_VERSION_FULL=1.0.0
- API_DEFAULT_VERSION=1
+ BASE_URL=http://localhost:8000
+ API_VERSION=1
```

For production with API on subdomain:

```env
BASE_URL=https://api.example.com
API_VERSION=1
```

---

## [1.19.0] - 2026-01-22 — Canopus

Feature release introducing a comprehensive Search & Filtering DSL with standardized URL query parameter syntax for filtering, sorting, and full-text search, including pluggable search engine adapters for Elasticsearch and Meilisearch integration.

### Added

#### Search & Filtering DSL (`src/Api/Filtering/`)

- **Filter Parser**: Standardized URL query parameter syntax:
  - Equality filters: `filter[status]=active`
  - Comparison operators: `filter[age][gte]=18`, `filter[price][lt]=100`
  - Array operators: `filter[status][in]=active,pending`
  - Text operators: `filter[name][contains]=john`, `filter[email][starts]=admin`
  - Null checks: `filter[deleted_at][null]`, `filter[verified_at][not_null]`
  - Range queries: `filter[price][between]=10,100`

- **14 Filter Operators** with aliases:
  - `eq` (=, equal, equals) - Equality
  - `ne` (!=, neq, not_equal) - Not equal
  - `gt` (>, greater_than) - Greater than
  - `gte` (>=, greater_than_or_equal) - Greater than or equal
  - `lt` (<, less_than) - Less than
  - `lte` (<=, less_than_or_equal) - Less than or equal
  - `contains` (like, includes) - Contains substring
  - `starts` (prefix, starts_with) - Starts with
  - `ends` (suffix, ends_with) - Ends with
  - `in` - In array
  - `nin` (not_in) - Not in array
  - `between` (range) - Between two values
  - `null` (is_null) - Is null
  - `not_null` (notnull, is_not_null) - Is not null

- **Sorting**: Multi-column sorting with direction indicators:
  - Ascending: `sort=name`
  - Descending: `sort=-created_at`
  - Multiple: `sort=-created_at,name`

- **Full-Text Search**: Database LIKE queries and search engine integration:
  - Search all fields: `search=john doe`
  - Search specific fields: `search=tutorial&search_fields=title,body`

- **QueryFilter Base Class**: Model-specific filter classes with:
  - Field whitelisting (`$filterable`, `$sortable`, `$searchable`)
  - Custom filter methods (`filterFieldName()` pattern)
  - Default sort configuration
  - Security limits (max filters, max depth)

- **PHP 8 Attributes** for controller configuration:
  - `#[Filterable]` - Define allowed filter fields
  - `#[Searchable]` - Define searchable fields
  - `#[Sortable]` - Define sortable fields

- **FilterMiddleware**: Automatic query parameter parsing into request attributes

- **OperatorRegistry**: Extensible operator registration with aliases

#### Search Engine Adapters (`src/Api/Filtering/Adapters/`)

- **SearchAdapterInterface**: Contract for search engine adapters
- **SearchResult**: Value object with pagination, total count, and timing
- **DatabaseAdapter**: SQL LIKE queries (default, no setup required)
- **ElasticsearchAdapter**: Full Elasticsearch integration (optional)
  - Multi-match queries with fuzziness
  - Boolean filters
  - Bulk indexing
  - Installation: `composer require elasticsearch/elasticsearch:^8.0`
- **MeilisearchAdapter**: Full Meilisearch integration (optional)
  - Typo-tolerant search
  - Faceted filtering
  - Installation: `composer require meilisearch/meilisearch-php:^1.0`

- **Auto-Migration**: `search_index_log` table created automatically when using search engines (follows `DatabaseLogHandler` pattern)

- **Searchable Trait** (`src/Api/Filtering/Concerns/Searchable.php`):
  - `makeSearchable()` - Index a model
  - `removeFromSearch()` - Remove from index
  - `Model::search($query)` - Static search method
  - Auto-indexing on model save (configurable)
  - Bulk indexing support

#### CLI Commands

- **scaffold:filter**: Generate QueryFilter classes
  - `php glueful scaffold:filter UserFilter`
  - `--filterable=status,role` - Define filterable fields
  - `--sortable=name,created_at` - Define sortable fields
  - `--searchable=name,email` - Define searchable fields
  - `--model=User` - Associate with model

### Changed

- **config/api.php**: Added `filtering` configuration section with search driver settings
- **composer.json**: Added `suggest` section for optional search dependencies
- **CoreProvider**: Registered `FilterParser` and `FilterMiddleware` services
- **ConsoleProvider**: Registered `FilterCommand`

### Configuration

```php
// config/api.php
'filtering' => [
    'enabled' => true,
    'filter_param' => 'filter',
    'sort_param' => 'sort',
    'search_param' => 'search',
    'max_filters' => 20,
    'max_depth' => 3,
    'allowed_operators' => ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', ...],
    'search' => [
        'driver' => 'database', // database, elasticsearch, meilisearch
        'index_prefix' => '',
        'auto_index' => false,
        'elasticsearch' => ['hosts' => ['localhost:9200']],
        'meilisearch' => ['host' => 'http://localhost:7700', 'key' => null],
    ],
],
```

### Usage Examples

```php
// Controller with QueryFilter
class UserController extends Controller
{
    public function index(UserFilter $filter): Response
    {
        $users = User::query()
            ->tap(fn($q) => $filter->apply($q))
            ->paginate();

        return UserResource::collection($users);
    }
}

// Custom QueryFilter
class UserFilter extends QueryFilter
{
    protected ?array $filterable = ['status', 'role', 'created_at'];
    protected ?array $sortable = ['name', 'email', 'created_at'];
    protected array $searchable = ['name', 'email', 'bio'];
    protected ?string $defaultSort = '-created_at';

    public function filterStatus(string $value, string $operator): void
    {
        if ($value === 'any') return;
        $this->query->where('status', $value);
    }
}

// Model with Searchable trait
class Post extends Model
{
    use Searchable;
    protected array $searchable = ['title', 'body'];
}

// Search
$results = Post::search('php tutorial');
```

---

## [1.18.0] - 2026-01-22 — Hadar

Feature release introducing a comprehensive Webhooks System with event-driven integrations, subscription management, HMAC signature verification, reliable delivery with exponential backoff retry, and auto-migration for database tables, completing the first feature of Phase 3: Data Access in Priority 3 API-specific features.

### Added

#### Webhooks System (`src/Api/Webhooks/`)

- **Event-Based Subscriptions**: Subscribe to specific events or wildcard patterns:
  - Exact matching: `user.created`, `order.completed`
  - Wildcard matching: `user.*` matches `user.created`, `user.updated`, `user.deleted`
  - Global wildcard: `*` matches all events
  - Multiple event patterns per subscription

- **HMAC-SHA256 Signatures**: Stripe-style signature format for payload verification:
  - Format: `t=timestamp,v1=signature`
  - Timing-safe comparison to prevent timing attacks
  - Timestamp validation to prevent replay attacks (configurable tolerance)
  - Public `parse()` method for signature header parsing

- **Reliable Delivery System**:
  - Queue-based delivery via `DeliverWebhookJob`
  - Exponential backoff retry: 1m, 5m, 30m, 2h, 12h (configurable)
  - Maximum 5 retry attempts (configurable)
  - Uses Glueful HTTP Client (Symfony HttpClient)
  - 30-second timeout per request

- **Auto-Migration for Database Tables** (follows `DatabaseLogHandler` pattern):
  - `webhook_subscriptions` - Stores subscription configuration
  - `webhook_deliveries` - Tracks delivery attempts and responses
  - Tables created automatically on first use via `WebhookDispatcher::ensureTables()`
  - Zero configuration required

- **ORM Models**:
  - `WebhookSubscription` - Subscription management with `listensTo()` wildcard matching
  - `WebhookDelivery` - Delivery tracking with status methods (`isPending()`, `isDelivered()`, `isFailed()`, `isRetrying()`)
  - HasMany/BelongsTo relationships between models

- **Event Integration**:
  - `DispatchesWebhooks` trait for webhookable events
  - `#[Webhookable]` PHP 8 attribute for marking events
  - `WebhookEventListener` bridges application events to webhooks
  - `WebhookDispatchedEvent` fired when webhooks are queued

- **Static Facade** (`Webhook`):
  - `Webhook::dispatch('event.name', $data)` - Dispatch webhooks
  - `Webhook::subscribe(['event.*'], 'https://...')` - Create subscriptions
  - `Webhook::subscriptionsFor('event.name')` - Get matching subscriptions

- **REST API** (`WebhookController`):
  - `POST /api/webhooks/subscriptions` - Create subscription
  - `GET /api/webhooks/subscriptions` - List subscriptions (with pagination)
  - `GET /api/webhooks/subscriptions/{id}` - Get subscription details
  - `PATCH /api/webhooks/subscriptions/{id}` - Update subscription
  - `DELETE /api/webhooks/subscriptions/{id}` - Delete subscription
  - `POST /api/webhooks/subscriptions/{id}/test` - Send test webhook
  - `GET /api/webhooks/deliveries` - List deliveries (filterable by status)
  - `POST /api/webhooks/deliveries/{id}/retry` - Retry failed delivery

- **CLI Commands** (`src/Console/Commands/Webhook/`):
  - `php glueful webhook:list` - List all webhook subscriptions
  - `php glueful webhook:test <url>` - Test a webhook endpoint
  - `php glueful webhook:retry` - Retry failed webhook deliveries

- **Contracts**:
  - `WebhookDispatcherInterface` - Dispatcher contract
  - `WebhookPayloadInterface` - Payload builder contract

### Changed

- **config/api.php**: Added comprehensive `webhooks` configuration section
- **CoreProvider.php**: Registered `WebhookPayload`, `WebhookDispatcher`, `WebhookEventListener`, `WebhookController`
- **ConsoleProvider.php**: Registered webhook CLI commands
- **Application.php**: Registered webhook CLI commands

### Configuration

```php
// config/api.php
'webhooks' => [
    'enabled' => true,
    'queue' => 'webhooks',
    'connection' => null, // Use default queue connection
    'signature_header' => 'X-Webhook-Signature',
    'signature_algorithm' => 'sha256',
    'timeout' => 30,
    'user_agent' => 'Glueful-Webhooks/1.0',
    'retry' => [
        'max_attempts' => 5,
        'backoff' => [60, 300, 1800, 7200, 43200], // seconds
    ],
    'require_https' => true,
    'cleanup' => [
        'keep_successful_days' => 7,
        'keep_failed_days' => 30,
    ],
]
```

### Usage Examples

```php
// Dispatch a webhook
Webhook::dispatch('user.created', ['user' => $userData]);

// Create a subscription
$subscription = Webhook::subscribe(
    ['user.*', 'order.completed'],
    'https://example.com/webhooks'
);

// Make an event webhookable
class UserCreated extends BaseEvent
{
    use DispatchesWebhooks;

    public function webhookEventName(): string
    {
        return 'user.created';
    }

    public function webhookPayload(): array
    {
        return ['user' => $this->user];
    }
}

// Verify webhook signature (for receiving webhooks)
$isValid = WebhookSignature::verify(
    $payload,
    $request->headers->get('X-Webhook-Signature'),
    $secret,
    300 // tolerance in seconds, null to skip timestamp check
);
```

### Webhook Payload Structure

```json
{
  "id": "wh_evt_01HXYZ123456789ABCDEF",
  "event": "user.created",
  "created_at": "2026-01-22T12:00:00+00:00",
  "data": {
    "user": {
      "id": "usr_01HXYZ987654321FEDCBA",
      "email": "john@example.com",
      "name": "John Doe"
    }
  }
}
```

### Webhook Headers

| Header                | Description        | Example                  |
| --------------------- | ------------------ | ------------------------ |
| `X-Webhook-ID`        | Unique delivery ID | `wh_del_01HXYZ...`       |
| `X-Webhook-Event`     | Event name         | `user.created`           |
| `X-Webhook-Timestamp` | Unix timestamp     | `1706011200`             |
| `X-Webhook-Signature` | HMAC signature     | `t=1706011200,v1=abc...` |
| `Content-Type`        | Always JSON        | `application/json`       |
| `User-Agent`          | Glueful identifier | `Glueful-Webhooks/1.0`   |

### Documentation

- Updated `docs/implementation-plans/priority-3/README.md` marking Webhooks System as complete
- Updated `docs/implementation-plans/priority-3/02-webhooks-system.md` with implementation status

### Tests

- **46 unit tests** with 93 assertions covering:
  - `WebhookSignatureTest` - Signature generation, verification, parsing (16 tests)
  - `WebhookPayloadTest` - Payload building, metadata handling (8 tests)
  - `WebhookSubscriptionTest` - Event matching, wildcards, secret generation (11 tests)
  - `WebhookDeliveryTest` - Status tracking, retry scheduling (11 tests)

### Notes

- **Auto-Migration**: Database tables are created automatically on first use - no manual migration required
- **Queue Integration**: Webhooks are delivered asynchronously via the queue system
- **Existing Events**: Integrates with existing `WebhookDeliveredEvent` and `WebhookFailedEvent` in `src/Events/Webhook/`

---

## [1.17.0] - 2026-01-22 — Alnitak

Feature release introducing Enhanced Rate Limiting with per-route limits, tiered user access, cost-based limiting, and IETF-compliant headers, completing Phase 2 of Priority 3 API-specific features.

### Added

#### Enhanced Rate Limiting System (`src/Api/RateLimiting/`)

- **Per-Route Rate Limits**: New `#[RateLimit]` PHP 8 attribute for defining limits on individual endpoints:
  - `#[RateLimit(attempts: 60, perMinutes: 1)]` - Basic per-minute limiting
  - `#[RateLimit(attempts: 1000, perHours: 1)]` - Hourly limits
  - `#[RateLimit(attempts: 10000, perDays: 1)]` - Daily limits
  - `IS_REPEATABLE` attribute allows stacking multiple limits for multi-window protection
  - Tier-specific limits: `#[RateLimit(tier: 'pro', attempts: 300)]`
  - Custom key patterns: `#[RateLimit(key: 'custom:{ip}:{path}')]`

- **Cost-Based Limiting**: New `#[RateLimitCost]` attribute for expensive operations:
  - `#[RateLimitCost(cost: 10, reason: 'Complex query')]` - Consumes 10 quota units
  - `#[RateLimitCost(cost: 100, reason: 'Full data export')]` - Heavy operations
  - Protects against resource-intensive endpoints while maintaining simple counting

- **Three Limiter Algorithms**:
  - `FixedWindowLimiter` - Simple fixed time window counting
  - `SlidingWindowLimiter` - Smooth distribution, prevents boundary spikes (default)
  - `TokenBucketLimiter` - Allows bursts while maintaining average rate

- **Tiered User Access**: Configurable rate limits per user tier:
  - `anonymous` - 30/min, 500/hour, 5000/day (unauthenticated)
  - `free` - 60/min, 1000/hour, 10000/day (basic authenticated)
  - `pro` - 300/min, 10000/hour, 100000/day (premium users)
  - `enterprise` - Unlimited (null values)
  - Automatic tier resolution from user attributes (`tier`, `plan`, `subscription`, roles)

- **IETF-Compliant Headers**: Following draft-ietf-httpapi-ratelimit-headers specification:
  - Legacy: `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset`
  - IETF Draft: `RateLimit-Limit`, `RateLimit-Remaining`, `RateLimit-Reset`, `RateLimit-Policy`
  - `Retry-After` header on 429 responses (RFC 7231)

- **Core Components**:
  - `RateLimitManager` - Central orchestrator for rate limiting operations
  - `RateLimitResult` - Immutable value object with limit status
  - `RateLimitHeaders` - IETF-compliant header generation
  - `TierManager` - Tier configuration management
  - `TierResolver` - Request tier resolution from user data

- **Storage Adapters**:
  - `CacheStorage` - Production adapter using existing CacheStore
  - `MemoryStorage` - In-memory storage for testing

- **Middleware**:
  - `EnhancedRateLimiterMiddleware` - New middleware implementing `RouteMiddleware`
  - Registered as `enhanced_rate_limit` alias in container
  - Backward compatible with existing `RateLimiterMiddleware`

- **Route Integration**:
  - `Route::rateLimit(attempts, perMinutes, tier, algorithm, by)` - Fluent method
  - `Route::setRateLimitConfig(array)` / `getRateLimitConfig()` - Configuration accessors
  - `Route::setRateLimitCost(int)` / `getRateLimitCost()` - Cost accessors

- **Contracts**:
  - `RateLimiterInterface` - Common limiter contract with `attempt()`, `check()`, `reset()`, `remaining()`
  - `TierResolverInterface` - Contract for resolving user tier from request
  - `StorageInterface` - Storage backend abstraction

### Changed

- **Route.php**: Added `rateLimitConfig` and `rateLimitCost` properties with fluent methods
- **AttributeRouteLoader.php**: Added `processRateLimitAttributes()` method for attribute processing
- **config/api.php**: Added comprehensive `rate_limiting` configuration section
- **CoreProvider.php**: Registered all rate limiting services and middleware alias

### Configuration

```php
// config/api.php
'rate_limiting' => [
    'enabled' => true,
    'algorithm' => 'sliding', // fixed, sliding, bucket
    'default_tier' => 'anonymous',
    'tiers' => [
        'anonymous' => ['requests_per_minute' => 30, ...],
        'free' => ['requests_per_minute' => 60, ...],
        'pro' => ['requests_per_minute' => 300, ...],
        'enterprise' => ['requests_per_minute' => null, ...], // unlimited
    ],
    'headers' => ['enabled' => true, 'include_legacy' => true, 'include_ietf' => true],
    'bypass_ips' => '127.0.0.1,::1',
]
```

### Usage Examples

```php
// Multi-window limiting
#[RateLimit(attempts: 60, perMinutes: 1)]
#[RateLimit(attempts: 1000, perHours: 1)]
public function index(): Response { }

// Tier-specific limits
#[RateLimit(tier: 'free', attempts: 100, perDays: 1)]
#[RateLimit(tier: 'pro', attempts: 10000, perDays: 1)]
#[RateLimit(tier: 'enterprise', attempts: 0)] // 0 = unlimited
public function query(): Response { }

// Cost-based limiting
#[RateLimit(attempts: 1000, perDays: 1)]
#[RateLimitCost(cost: 100, reason: 'Full data export')]
public function export(): Response { }
```

### Documentation

- Updated `docs/implementation-plans/priority-3/README.md` marking Rate Limiting Enhancements as complete
- Updated `docs/implementation-plans/priority-3/03-rate-limiting-enhancements.md` with implementation status

### Notes

- **Backward Compatible**: Existing `RateLimiterMiddleware` continues to work unchanged
- **Opt-In**: Use `enhanced_rate_limit` middleware to enable new features
- **Unit Tests**: 44 tests with 88 assertions covering all components

---

## [1.16.0] - 2026-01-22 — Meissa

Feature release introducing flexible API Versioning Strategy with multiple resolution methods, deprecation system, and version negotiation, completing Phase 1 of Priority 3 API-specific features.

### Added

#### API Versioning System (`src/Api/Versioning/`)

- **Multiple Version Resolution Strategies**:
  - `UrlPrefixResolver` - Extract version from URL path (e.g., `/api/v1/users`)
  - `HeaderResolver` - Extract from `X-Api-Version` header
  - `QueryParameterResolver` - Extract from `?api-version=1` query parameter
  - `AcceptHeaderResolver` - Extract from Accept header (e.g., `application/vnd.glueful.v1+json`)

- **Version Negotiation**: `VersionNegotiator` class for multi-strategy resolution:
  - Configurable resolver priority
  - Fallback to default version
  - Strict mode for rejecting unsupported versions

- **PHP 8 Attributes**:
  - `#[Version('1', '2')]` - Specify supported versions for routes/controllers
  - `#[Deprecated(since: '1', removeIn: '3', message: '...')]` - Mark deprecations
  - `#[Sunset(date: '2025-06-01')]` - Specify sunset dates (RFC 8594)

- **Version Manager**: Central management for version configuration:
  - `getDefault()` / `setDefault()` - Default version management
  - `getSupported()` / `isSupported()` - Supported version queries
  - `getDeprecated()` / `isDeprecated()` - Deprecation tracking
  - `getSunsetDate()` - Sunset date retrieval

- **Middleware**: `VersionNegotiationMiddleware` implementing `RouteMiddleware`:
  - Automatic version resolution from request
  - Deprecation warning headers
  - Sunset headers (RFC 8594)
  - Version attribute on request for downstream use

- **Value Object**: `ApiVersion` immutable value object:
  - Version parsing and comparison
  - Major/minor version extraction
  - Equality and comparison operators

- **Contracts**:
  - `VersionResolverInterface` - Contract for version resolvers
  - `VersionNegotiatorInterface` - Contract for version negotiation
  - `DeprecatableInterface` - Contract for deprecatable resources

### Changed

- **config/api.php**: Added comprehensive `versioning` configuration section:
  - `default` - Default API version
  - `supported` - List of supported versions
  - `deprecated` - Deprecated versions with sunset dates
  - `strategy` - Primary resolution strategy
  - `resolvers` - Ordered list of resolvers
  - `resolver_options` - Per-resolver configuration
  - `headers` - Response header configuration

- **Route.php**: Added `version()` fluent method and `versionConfig` property
- **CoreProvider.php**: Registered versioning services

### Response Headers

- `X-Api-Version` - Current API version in use
- `Deprecation` - Deprecation notice (RFC 8594)
- `Sunset` - Sunset date header (RFC 8594)
- `Warning` - Deprecation warning message

### Configuration

```php
// config/api.php
'versioning' => [
    'default' => '1',
    'supported' => ['1', '2'],
    'deprecated' => [
        '1' => ['sunset' => '2025-06-01', 'message' => 'Please migrate to v2'],
    ],
    'strategy' => 'url_prefix',
    'resolvers' => ['url_prefix', 'header', 'query', 'accept'],
    'headers' => [
        'include_version' => true,
        'include_deprecation' => true,
        'include_sunset' => true,
    ],
]
```

### Usage Examples

```php
// Route-level version constraint
$router->get('/users', [UserController::class, 'index'])
    ->version(['1', '2']);

// Attribute-based versioning
#[Version('2')]
#[Deprecated(since: '2', removeIn: '3', message: 'Use /v3/users instead')]
public function legacyUsers(): Response { }

// Version-specific route groups
$router->group(['prefix' => '/api/v1', 'middleware' => ['api_version']], function ($router) {
    $router->get('/users', [UserController::class, 'indexV1']);
});
```

### Documentation

- Updated `docs/implementation-plans/priority-3/README.md` marking API Versioning as complete
- Updated `docs/implementation-plans/priority-3/01-api-versioning.md` with implementation status

### Notes

- **Standards Compliant**: Follows RFC 8594 for Sunset headers
- **Flexible**: Supports multiple resolution strategies simultaneously
- **Graceful Deprecation**: Warns clients via headers before removal

---

## [1.15.0] - 2026-01-22 — Rigel

Feature release introducing Real-Time Development Server with file watching, colorized logging, and integrated services, completing Priority 2 developer experience features.

### Added

#### Real-Time Development Server

- **FileWatcher Class**: New `Glueful\Development\Watcher\FileWatcher` for automatic file change detection:
  - Polling-based watching for cross-platform compatibility
  - Configurable directories, extensions, and ignore patterns
  - Change detection for created, modified, and deleted files
  - `watch(callable $onChange)` - Watch with callback on changes
  - `checkOnce()` - Single check for changes (non-blocking)
  - `setInterval(int $ms)` - Configure polling interval
  - `setExtensions(array)` / `addExtensions(array)` - Configure watched file types
  - `setIgnore(array)` / `addIgnore(array)` - Configure ignore patterns

- **RequestLogger Class**: New `Glueful\Development\Logger\RequestLogger` for colorized HTTP logging:
  - Color-coded HTTP methods (GET=green, POST/PUT=yellow, DELETE=red)
  - Color-coded status codes (2xx=green, 3xx=yellow, 4xx/5xx=red)
  - Duration formatting with slow request highlighting (>200ms yellow, >1s red)
  - Memory usage display
  - `log(LogEntry $entry)` - Log a request entry
  - `logStartup(string $host, int $port, array $options)` - Log server startup
  - `logRestart(string $reason)` - Log server restart
  - `logQueue(string $message)` - Log queue worker activity
  - `logFileChange(string $file, string $type)` - Log file changes

- **LogEntry Class**: New `Glueful\Development\Logger\LogEntry` structured data class:
  - Immutable request log entry with method, path, status, duration, memory
  - `fromAccessLog(string $line)` - Parse PHP built-in server log format
  - `create(...)` - Factory method with defaults
  - Status helpers: `isSuccessful()`, `isRedirect()`, `isClientError()`, `isServerError()`
  - `isSlow(float $thresholdMs)` - Check if request was slow

#### Enhanced ServeCommand

- **File Watching**: New `--watch` / `-w` option for auto-restart on code changes:
  - Watches `api/`, `src/`, `config/`, `routes/` directories
  - Monitors `.php`, `.env`, `.json`, `.yaml`, `.yml` files
  - Automatic server restart when changes detected
  - Configurable poll interval with `--poll-interval`

- **Queue Worker Integration**: New `--queue` / `-q` option:
  - Starts queue worker alongside development server
  - Queue output prefixed with `[Queue]` for clarity
  - Graceful shutdown of both processes

- **Port Auto-Selection**: Improved port handling:
  - Automatically finds next available port if preferred port is in use
  - Tries up to 10 consecutive ports before failing

- **Colorized Output**: Enhanced request logging:
  - Color-coded HTTP methods and status codes
  - Request duration with slow request highlighting
  - Clean, formatted output for development

### Changed

- **ServeCommand**: Completely rewritten with new features:
  - Added `--watch` / `-w` for file watching
  - Added `--queue` / `-q` for queue worker integration
  - Added `--poll-interval` for watcher configuration
  - Changed `--host` shortcut from `null` to `-H`
  - Enhanced output formatting with RequestLogger
  - Improved signal handling for graceful shutdown

### Documentation

- Updated `docs/implementation-plans/priority-2/README.md` marking Real-Time Dev Server as complete.
- Updated `docs/implementation-plans/priority-2/04-realtime-dev-server.md` with implementation status.

### Notes

- **Cross-Platform**: File watcher uses polling strategy for compatibility with all operating systems.
- **Performance**: Default poll interval is 500ms, configurable via `--poll-interval`.
- **Directories**: Default watched directories are `api/`, `src/`, `config/`, `routes/`.

## [1.14.0] - 2026-01-22 — Bellatrix

Feature release introducing Interactive CLI Wizards for enhanced developer experience, continuing Priority 2 developer experience features.

### Added

#### Interactive CLI System

- **Prompter Class**: New `Glueful\Console\Interactive\Prompter` class providing fluent API for CLI prompts:
  - `ask(string $question, ?string $default, ?callable $validator)` - Text input with validation
  - `askRequired(string $question, ?string $default)` - Required text input
  - `secret(string $question, ?callable $validator)` - Hidden input for passwords
  - `confirm(string $question, bool $default)` - Yes/no confirmation
  - `choice(string $question, array $choices, $default)` - Single selection from options
  - `multiChoice(string $question, array $choices, ?array $defaults)` - Multiple selection
  - `suggest(string $question, array $suggestions, ?string $default)` - Auto-completion input
  - Auto-fallback to defaults in non-interactive mode (`--no-interaction`)

- **ProgressBar Wrapper**: New `Glueful\Console\Interactive\Progress\ProgressBar` enhanced wrapper:
  - Predefined format constants (NORMAL, VERBOSE, DEBUG, WITH_MESSAGE)
  - `iterate(iterable $items)` - Generator for automatic progress tracking
  - `map(iterable $items, callable $callback)` - Process items with progress
  - `times(int $count, callable $callback)` - Run callback N times with progress
  - Fluent interface for configuration

- **Spinner Animations**: New `Glueful\Console\Interactive\Progress\Spinner` class:
  - Multiple animation styles: dots, line, arrows, bouncing, growing, circle, square, toggle, simple
  - `run(callable $callback)` - Wrap task with spinner animation
  - `runWithSuccess(callable $callback, string $successMessage)` - With completion message
  - `setStyle(string $style)` / `setFrames(array $frames)` - Customize animation
  - Success, error, warning, and info completion states

#### BaseCommand Enhancements

- **Interactive Helpers**: Added to `Glueful\Console\BaseCommand`:
  - `getPrompter()` - Get Prompter instance
  - `isInteractive()` - Check if running in interactive mode
  - `prompt()`, `promptRequired()` - Quick text input methods
  - `multiChoice()` - Multi-select from options
  - `suggest()` - Input with auto-completion
  - `createEnhancedProgressBar()` - Get enhanced progress bar
  - `createSpinner()` - Create spinner instance
  - `withProgress(iterable, callable)` - Process items with progress
  - `withSpinner(callable, string)` - Run task with spinner
  - `withSpinnerSuccess(callable, string, string)` - Spinner with success message
  - `confirmDestructive(string)` - Confirmation for destructive operations

#### Scaffold Command Improvements

- **scaffold:model**: Now supports interactive mode:
  - Prompts for model name when not provided as argument
  - Interactive option selection for migration, soft-deletes, fillable fields
  - Detailed help text with usage examples
  - Maintains full CLI compatibility with `--no-interaction`

### Changed

- **BaseCommand**: Added `$prompter` property and interactive helper methods.
- **ModelCommand**: Changed 'name' argument from REQUIRED to OPTIONAL for interactive support.

### Documentation

- Updated `docs/implementation-plans/priority-2/README.md` marking Interactive CLI Wizards as complete.

### Notes

- **Non-Interactive Mode**: All interactive prompts gracefully fallback to defaults when `--no-interaction` flag is used (CI/CD friendly).
- **Existing Commands**: Destructive commands (db:reset, migrate:rollback, cache:clear, etc.) already have proper confirmation dialogs.

## [1.13.0] - 2026-01-22 — Saiph

Feature release introducing Enhanced Scaffold Commands and Database Factories & Seeders, completing Priority 2 developer experience features for v1.13.0.

### Added

#### Enhanced Scaffold Commands

- **scaffold:middleware**: New command to generate route middleware classes implementing `RouteMiddleware` interface with options:
  - `--force` / `-f` - Overwrite existing files
  - `--path` / `-p` - Custom output path
  - Supports nested namespaces (e.g., `Admin/AuthMiddleware`)
- **scaffold:job**: New command to generate queue job classes extending `Glueful\Queue\Job` with options:
  - `--queue` - Specify target queue name
  - `--tries` - Number of retry attempts
  - `--backoff` - Backoff delay in seconds
  - `--timeout` - Job timeout in seconds
  - `--unique` - Generate unique job (prevents duplicates)
- **scaffold:rule**: New command to generate validation rule classes implementing `Rule` interface with options:
  - `--params` - Constructor parameters (comma-separated)
  - `--implicit` - Generate implicit rule (validates empty values)
- **scaffold:test**: New command to generate PHPUnit test classes with options:
  - `--unit` - Generate unit test (default)
  - `--feature` - Generate feature test with HTTP testing traits
  - `--class` - Target class to test
  - `--methods` - Methods to generate test stubs for (comma-separated)

#### Database Factories & Seeders

- **Factory**: New `Glueful\Database\Factory\Factory` base class for test data generation:
  - `definition()` - Define default model attributes
  - `count(int $n)` - Set number of models to create
  - `state(array|string|callable)` - Apply state transformations
  - `sequence(array...)` - Rotate attribute values across created models
  - `make()` / `makeMany()` - Create models without persisting
  - `create()` / `createMany()` - Create and persist models
  - `has(string $relation, int|Factory)` - Create with has-many relationships
  - `for(string $relation, Factory|Model)` - Create with belongs-to relationships
  - `recycle(Collection|Model)` - Reuse existing models for relationships
  - `afterCreating(callable)` / `afterMaking(callable)` - Lifecycle callbacks
- **FakerBridge**: New `Glueful\Database\Factory\FakerBridge` class for optional Faker integration:
  - `getInstance()` - Get cached Faker instance
  - `isAvailable()` - Check if Faker is installed
  - `setLocale(string)` - Configure Faker locale
  - `create(string $locale)` - Create new Faker instance
  - Helpful error messages when Faker is not installed
- **Seeder**: New `Glueful\Database\Seeders\Seeder` base class for database seeding:
  - `run()` - Abstract method to implement seeding logic
  - `call(string|array $class)` - Call other seeders
  - `withTransaction(callable)` - Wrap operations in database transaction
  - `truncate(string $table)` - Clear table before seeding
  - `$dependencies` - Declare seeder execution order
- **HasFactory**: New `Glueful\Database\ORM\Concerns\HasFactory` trait for models:
  - `factory(int $count = 1)` - Get factory instance for model
  - `factoryUsing(string $factoryClass)` - Use custom factory class
  - Auto-resolves factory class from model name convention

#### Console Commands

- **db:seed**: New command to run database seeders with options:
  - `[class]` - Specific seeder class to run (default: DatabaseSeeder)
  - `--force` / `-f` - Required to run in production environment
  - Environment protection prevents accidental production seeding
- **scaffold:factory**: New command to generate model factory classes with options:
  - `--model` / `-m` - The model class the factory creates
  - `--force` / `-f` - Overwrite existing files
  - `--path` / `-p` - Custom output path
  - Warns if Faker is not installed
- **scaffold:seeder**: New command to generate database seeder classes with options:
  - `--model` / `-m` - The model class to use for seeding
  - `--force` / `-f` - Overwrite existing files
  - `--path` / `-p` - Custom output path
  - Special handling for DatabaseSeeder (main orchestrator)

### Changed

- **Console**: All new commands registered in `ConsoleProvider` and `Application` command list.
- **Application.php**: Added `SeedCommand`, `FactoryCommand`, `SeederCommand`, `MiddlewareCommand`, `JobCommand`, `RuleCommand`, `TestCommand` to command registry.

### Documentation

- New `docs/FACTORIES.md` with comprehensive usage guide covering:
  - Factory creation and definition
  - Factory states and sequences
  - Relationships (has/for)
  - Seeder creation and execution
  - Console commands reference
  - Best practices
- Updated `docs/implementation-plans/priority-2/README.md` marking enhanced scaffold commands and factories/seeders as complete.

### Notes

- **Faker Dependency**: Factories require `fakerphp/faker` as a dev dependency. Install with `composer require --dev fakerphp/faker`.
- **Production Safety**: The `db:seed` command requires `--force` flag in production environments.
- **Generated Files**: Factories are created in `database/factories/`, seeders in `database/seeders/`.

## [1.12.0] - 2026-01-21 — Mintaka

Feature release introducing API Resource Transformers, completing all Priority 1 features for modern API development.

### Added

#### API Resource Transformers

- **JsonResource**: New base class for transforming data into consistent JSON API responses with property delegation and nested resource resolution.
- **ModelResource**: New ORM-aware resource class extending JsonResource with model-specific helpers:
  - `attribute()` / `hasAttribute()` - Attribute access with defaults
  - `dateAttribute()` / `whenDateNotNull()` - ISO 8601 date formatting
  - `isRelationLoaded()` / `getRelation()` - Relationship introspection
  - `relationshipResource()` / `relationshipCollection()` - Transform loaded relationships
  - `hasAnyRelationLoaded()` / `hasAllRelationsLoaded()` - Bulk relationship checks
- **ResourceCollection**: New collection class for transforming multiple items with:
  - `withPagination()` - Manual pagination metadata
  - `withPaginationFrom()` - Auto-detect QueryBuilder or ORM pagination format
  - `withLinks()` - Generate pagination links with query parameters
  - `additional()` - Add extra response data
  - `preserveKeys()` - Maintain array keys during iteration
- **AnonymousResourceCollection**: Dynamic collection creation from `JsonResource::collection()` calls.
- **PaginatedResourceResponse**: Advanced pagination handler with:
  - `fromQueryResult()` - Create from QueryBuilder flat format
  - `fromOrmResult()` - Create from ORM meta format
  - `withBaseUrl()` / `withQueryParams()` - Link generation
  - `setPage()` / `setPerPage()` / `setTotal()` - Manual configuration
  - `getMeta()` / `getLinks()` - Access pagination data
- **MissingValue**: Sentinel class for conditional attribute omission.
- **Traits**:
  - `ConditionallyLoadsAttributes` - Conditional inclusion helpers:
    - `when()` - Include attribute when condition is true
    - `unless()` - Include attribute when condition is false
    - `mergeWhen()` - Merge multiple attributes conditionally
    - `whenHas()` - Include if key exists in source data
    - `whenNotNull()` - Include if value is not null
    - `whenLoaded()` - Include only if relationship is loaded
    - `whenCounted()` - Include relationship count if loaded
    - `whenPivotLoaded()` - Access pivot table data
  - `DelegatesToResource` - Property access delegation to underlying resource
  - `CollectsResources` - Collection transformation logic

#### Console Commands

- **Scaffold**: New `scaffold:resource` command to generate API resource classes with options:
  - `--model` / `-m` - Generate ModelResource with ORM integration
  - `--collection` / `-c` - Generate ResourceCollection class
  - `--force` / `-f` - Overwrite existing files
  - `--path` / `-p` - Custom output path

### Changed

- **Console**: `ResourceCommand` registered in `ConsoleProvider` and `Application` command list.

### Documentation

- New `docs/RESOURCES.md` with comprehensive usage guide covering:
  - Basic usage and resource creation
  - Conditional attributes and relationships
  - Collections and pagination
  - Model resources and ORM integration
  - Response customization and best practices
- Updated `docs/implementation-plans/README.md` marking all Priority 1 features as complete.
- Updated `docs/implementation-plans/03-api-resource-transformers.md` with implementation status.

### Tests

- New test suite in `tests/Unit/Http/Resources/`:
  - `JsonResourceTest.php` - Base resource transformation
  - `ResourceCollectionTest.php` - Collection handling
  - `ConditionalAttributesTest.php` - Conditional inclusion logic
  - `ModelResourceTest.php` - ORM integration with mock models
  - `PaginationTest.php` - Pagination and link generation
- Total: 71 tests, 155 assertions

## [1.11.0] - 2026-01-21 — Alnilam

Feature release introducing the ORM/Active Record system, completing the data layer of Priority 1 features.

### Added

#### ORM / Active Record

- **Model**: New `Model` base class implementing Active Record pattern with CRUD operations, mass assignment protection, and attribute handling.
- **Builder**: New `Builder` class wrapping QueryBuilder with model-aware query functionality, eager loading, and scope support.
- **Collection**: New `Collection` class for model results with rich iteration, filtering, and transformation methods.
- **Relations**: Complete relationship system:
  - `HasOne` - One-to-one relationship (owning side)
  - `HasMany` - One-to-many relationship
  - `BelongsTo` - Inverse one-to-one/many relationship
  - `BelongsToMany` - Many-to-many with pivot table support
  - `HasOneThrough` - Has-one through intermediate model
  - `HasManyThrough` - Has-many through intermediate model
  - `Pivot` - Pivot model for many-to-many relationships
- **Traits**:
  - `HasAttributes` - Attribute get/set, casting, dirty tracking, and serialization
  - `HasEvents` - Model lifecycle events integration with framework event system
  - `HasRelationships` - Relationship definition and eager loading
  - `HasTimestamps` - Automatic `created_at`/`updated_at` management
  - `HasGlobalScopes` - Global query scope registration and removal
  - `SoftDeletes` - Soft delete support with `deleted_at` column
- **Casts**: Custom attribute casting system:
  - `AsJson` - JSON encode/decode
  - `AsArrayObject` - JSON to ArrayObject with ARRAY_AS_PROPS
  - `AsCollection` - JSON to Collection instance
  - `AsDateTime` - String to DateTimeImmutable with configurable format
  - `AsEncryptedString` - Transparent encryption/decryption
  - `AsEnum` - Backed enum casting
  - `Attribute` - Custom getter/setter accessors with caching
- **Events**: Model lifecycle events extending `BaseEvent`:
  - `ModelCreating` / `ModelCreated`
  - `ModelUpdating` / `ModelUpdated`
  - `ModelSaving` / `ModelSaved`
  - `ModelDeleting` / `ModelDeleted`
  - `ModelRetrieved`
- **Scopes**: `SoftDeletingScope` global scope with `withTrashed()`, `onlyTrashed()`, `restore()` macros.
- **Contracts**: `ModelInterface`, `CastsAttributes`, `Scope`, `SoftDeletable` interfaces.
- **Provider**: New `ORMProvider` service provider for DI container registration.

#### Console Commands

- **Scaffold**: New `scaffold:model` command to generate ORM model classes with options:
  - `--migration` / `-m` - Generate accompanying migration
  - `--soft-deletes` / `-s` - Include SoftDeletes trait
  - `--timestamps` / `-t` - Include HasTimestamps trait
  - `--fillable` - Comma-separated fillable attributes
  - `--table` - Custom table name
- **Reorganization**: Renamed command namespace from `make:*` / `generate:*` to `scaffold:*`:
  - `make:request` → `scaffold:request`
  - `generate:controller` → `scaffold:controller`

### Changed

- **Framework**: ORM initialization added to `Framework::initializeCoreServices()` via `Model::setContainer()`.
- **Container**: `ORMProvider` registered in `ContainerFactory` provider list.
- **CI**: GitHub Actions workflow jobs now run sequentially (lint → tests → coverage → static → security) for better debugging and resource usage.

### Documentation

- New `docs/ORM.md` with comprehensive usage guide covering models, relationships, eager loading, events, and casts.
- Updated `docs/implementation-plans/README.md` marking ORM as complete.
- Updated `docs/implementation-plans/01-orm-active-record.md` with implementation status.

### Tests

- New test suite in `tests/Unit/Database/ORM/`:
  - `ModelTest.php` - Model base functionality
  - `HasAttributesTest.php` - Attribute handling and casting
  - `RelationsTest.php` - Relationship loading and management
  - `CollectionTest.php` - Collection methods and iteration
  - `CastsTest.php` - Custom cast classes
  - `SoftDeletesTest.php` - Soft delete behavior

## [1.10.0] - 2026-01-21 — Elnath

Feature release introducing centralized exception handling and declarative request validation, completing the foundation layer of Priority 1 features.

### Added

#### Exception Handler

- **Exceptions**: New `ExceptionHandlerInterface` contract for customizable exception handling implementations.
- **Exceptions**: New `RenderableException` interface for exceptions that can render themselves to responses.
- **Exceptions**: New `HttpException` base class for all HTTP-related exceptions with status codes and context.
- **Exceptions**: New `Handler` class providing default exception handling with environment-aware error responses.
- **Exceptions**: New `ExceptionMiddleware` for automatic exception-to-response conversion in the middleware pipeline.
- **Exceptions**: New `ExceptionProvider` service provider for DI container registration.
- **Exceptions**: Client exceptions (4xx):
  - `BadRequestException` (400)
  - `UnauthorizedException` (401)
  - `ForbiddenException` (403)
  - `NotFoundException` (404)
  - `MethodNotAllowedException` (405)
  - `ConflictException` (409)
  - `UnprocessableEntityException` (422)
  - `TooManyRequestsException` (429)
- **Exceptions**: Server exceptions (5xx):
  - `InternalServerException` (500)
  - `ServiceUnavailableException` (503)
  - `GatewayTimeoutException` (504)
- **Exceptions**: Domain exceptions:
  - `ModelNotFoundException` - Entity not found in database
  - `AuthenticationException` - Authentication failure
  - `AuthorizationException` - Authorization/permission failure
  - `TokenExpiredException` - JWT/session token expired

#### Request Validation

- **Validation**: New `#[Validate]` attribute for declarative validation on controller methods.
- **Validation**: New `FormRequest` base class for complex validation with authorization, custom messages, and data preparation hooks.
- **Validation**: New `ValidatedRequest` wrapper for type-safe access to validated data.
- **Validation**: New `ValidationMiddleware` for automatic request validation in the middleware pipeline.
- **Validation**: New `RuleParser` for Laravel-style string rule syntax (`'required|email|max:255'`).
- **Validation**: New `'validate'` middleware alias for route-level validation.
- **Validation**: New validation rules:
  - `Confirmed` - Password confirmation matching
  - `Date` - Date format validation with optional format
  - `Before` / `After` - Date comparison rules
  - `Url` - URL format with optional protocol restriction
  - `Uuid` - UUID format validation
  - `Json` - JSON string validation
  - `Exists` - Database existence check
  - `Nullable` / `Sometimes` - Conditional validation
  - `File` - File upload validation (extensions, size)
  - `Image` - Image file validation (types, size)
  - `Dimensions` - Image dimensions (width, height, ratio)
- **Console**: New `make:request` command to generate FormRequest classes.

### Changed

- **Validation**: `ValidationException` now extends `ApiException` and returns proper 422 HTTP responses with structured error format.
- **Validation**: `DbUnique` rule updated to support both PDO injection and string-based syntax from `RuleParser`.
- **Application**: Request dispatch now uses centralized exception handling via `ExceptionMiddleware`.

### Documentation

- Updated implementation plans to mark Exception Handler and Request Validation as complete.
- Added implementation status indicators to architecture diagrams.

## [1.9.2] - 2026-01-20 — Deneb

Patch release with OpenAPI 3.1 support, automatic resource route expansion from database schemas, and documentation UI improvements.

### Added

- **Documentation**: New `ResourceRouteExpander` class that automatically expands `{resource}` routes to table-specific endpoints with full database schemas.
- **Documentation**: OpenAPI 3.1.0 support with proper JSON Schema draft 2020-12 alignment:
  - Nullable types use array syntax (`type: ["string", "null"]`)
  - License supports SPDX `identifier` field
  - `jsonSchemaDialect` declaration included
- **Documentation**: Scalar UI configuration options: `hide_client_button`, `show_developer_tools`.
- **Documentation**: Tags in documentation sidebar are now sorted alphabetically.

### Changed

- **Documentation**: Default OpenAPI version changed from 3.0.0 to 3.1.0.
- **Documentation**: Renamed output file from `swagger.json` to `openapi.json` (modern naming convention).
- **Documentation**: Resource route tags renamed from "Resources - {table}" to "Table - {table}" for clarity.
- **Documentation**: Config key `paths.swagger` renamed to `paths.openapi`.

### Removed

- **Documentation**: Removed `TableDefinitionGenerator` class - resource routes now expand directly from database schemas without intermediate JSON files.
- **Documentation**: Removed `--database` and `--table` options from `generate:openapi` command (no longer needed).

### Fixed

- **Database**: Fixed `SchemaBuilder::getTableColumns()` returning empty arrays due to incorrect `array_is_list()` check on associative column data.

## [1.9.1] - 2026-01-19 — Castor

Patch release with a major refactor of the OpenAPI documentation system, adding interactive UI generation and improved PHPDoc parsing.

### Added

- **Documentation**: New `DocumentationUIGenerator` class for generating interactive API documentation HTML pages supporting:
  - Scalar (default) - Modern, beautiful API documentation
  - Swagger UI - Classic OpenAPI documentation interface
  - Redoc - Clean, responsive three-panel design
- **Documentation**: New `--ui` option for `generate:openapi` command to generate documentation UI alongside openapi.json.
- **Documentation**: New `config/documentation.php` centralizing all documentation settings including paths, API info, servers, security schemes, and UI configuration.
- **Documentation**: New `OpenApiGenerator` class orchestrating the full documentation pipeline with lazy-loaded dependencies.
- **Validation**: New `Numeric` rule for validating numeric values with optional range, integer-only, and positive-only constraints.
- **Validation**: New `Regex` rule for validating values against regular expression patterns.

### Changed

- **Documentation**: Renamed `ApiDefinitionsCommand` to `OpenApiDocsCommand` with enhanced options and better UX.
- **Documentation**: `CommentsDocGenerator` now uses `phpDocumentor/ReflectionDocBlock` for robust PHPDoc parsing instead of regex patterns.
- **Documentation**: `CommentsDocGenerator` now discovers extension routes via `ExtensionManager::getProviders()` for Composer-installed extensions.
- **Documentation**: `CommentsDocGenerator` now caches parsed route files based on file modification time.
- **Documentation**: `DocGenerator` extracted shared `mergeDefinition()` method from duplicate extension/route merge logic.
- **Documentation**: OpenAPI version is now configurable via `config('documentation.openapi_version')`.
- **Dependencies**: Updated Symfony packages from `^7.3` to `^7.4`.
- **Dependencies**: Added `phpdocumentor/reflection-docblock: ^6.0` for PHPDoc parsing.

### Removed

- **Documentation**: Removed `ApiDefinitionGenerator` class (replaced by `OpenApiGenerator`).

### Fixed

- **Console**: Fixed `Application` to use `add()` instead of undefined `addCommand()` method for Symfony Console 7.x compatibility.

## [1.9.0] - 2026-01-17 — Betelgeuse

Minor release raising the minimum PHP version to 8.3 and addressing compatibility with Symfony Console 7.3.

### Breaking Changes

- **PHP**: Minimum required PHP version is now 8.3 (up from 8.2). Update your environment before upgrading.

### Fixed

- **Console**: Renamed `Application::addCommand(string)` to `Application::registerCommandClass(string)` to resolve method signature conflict with Symfony Console 7.3's new `addCommand(Command|callable)` method.
- **Routing**: `RouteManifest::load()` now prevents double-loading routes during framework initialization, eliminating "Route already defined" warnings in CLI commands.
- **Security**: Fixed PHPStan strict boolean check in `CSRFMiddleware` for cookie token validation.
- **Tests**: Added missing PSR-4 namespace declarations to async test files (`AsyncStreamHelpersTest`, `SchedulerTimerCancellationTest`, `HttpStreamingClientTest`, `HttpPoolingConfigTest`, `RaceSemanticsTest`).

### Changed

- **CI**: Test matrix now targets PHP 8.3 and 8.4 (dropped PHP 8.2 support).
- **Routing**: Added `RouteManifest::reset()` and `RouteManifest::isLoaded()` helper methods for testing scenarios.

### Upgrade Guide

1. Ensure your environment runs PHP 8.3 or higher.
2. If you called `$app->addCommand(MyCommand::class)` directly, rename to `$app->registerCommandClass(MyCommand::class)`.
3. Run `composer update` to refresh dependencies.

## [1.8.1] - 2025-11-23 — Vega

Small patch release tightening password policy options and improving async stream helper ergonomics for buffered I/O callers.

### Added

- Helpers/Security: `Utils::validatePassword()` gained a `$requireLowercase` flag so applications can explicitly enforce mixed-case passwords alongside numbers, symbols, and uppercase requirements.

### Fixed

- Async/IO: `async_stream()` now accepts raw resources, `AsyncStream`, or `BufferedAsyncStream` instances and normalizes them before wrapping. This ensures buffered streams always reference a canonical async transport, respects configured buffer sizes, and keeps static analysis annotations accurate.

## [1.8.0] - 2025-11-13 — Spica

Feature release adding first-class session and login response events, enabling safe enrichment of cached session payloads and login responses without modifying framework code.

### Added

- Events/Auth:
  - `SessionCachedEvent`: Dispatched after a session is written to cache (and DB). Listeners can augment the cached payload (e.g., `user.organization`) or warm related caches. Implemented at `src/Auth/SessionCacheManager.php` after successful `cache->set` in `storeSession()`.
  - `LoginResponseBuildingEvent`: Dispatched just before returning the login JSON. Provides a mutable response map so apps can extend the payload (e.g., `context.organization`).
  - `LoginResponseBuiltEvent`: Dispatched after the login response is finalized for metrics/analytics.
- Controllers/Auth:
  - `AuthController::login()`: Pre-return response enrichment hook wired using the new login response events.

### Changed

- Docs:
  - `docs/SESSION_EVENTS_PROPOSAL.md` updated to reflect final implementation (paths under `src/...`), setter-based mutation (no PHP by-ref promotion), dispatch locations, and a concrete listener example.

### Notes

- Backward compatible: No behavior change unless listeners are registered.
- Performance: Events are synchronous; heavy listeners should offload to queues.
- Guidance: Prefer adding app-specific data under a `context.*` key to avoid collisions with reserved fields.

## [1.7.4] - 2025-10-28 — Arcturus

Patch release adding a minimal, configurable account‑status gate to authentication and new docs for writing migrations that create views/functions.

### Added

- Auth: Optional status policy in `AuthenticationService::authenticate()` and refresh‑token flow. Users must have a status in `security.auth.allowed_login_statuses` (default: `['active']`) to log in or refresh.

### Changed

- Config: Introduced `security.auth.allowed_login_statuses` under `config/security.php` and read it in authentication flows. This centralizes auth policy under security.

### Notes

- Behavior is secure by default and silent on failure (prevents account enumeration). Override the allowed statuses in your app’s `config/security.php` as needed.
- If you previously added an `auth.allowed_login_statuses` key during development, move it to `security.auth.allowed_login_statuses`.

## [1.7.3] - 2025-10-21 — Pollux

Patch release fixing QueryBuilder 2‑argument where/orWhere handling and further improving dev‑server log clarity.

### Fixed

- Database/QueryBuilder: Normalize 2‑argument `where($column, $value)` and `orWhere($column, $value)` to
  use the `=` operator internally. This resolves a `TypeError` where non‑string values (e.g., integers)
  were interpreted as the operator and passed to `WhereClause::add()`.
  - Improves portability for boolean filters across PostgreSQL/MySQL/SQLite.

### Improved

- CLI: `serve` command further refines classification of PHP built‑in server access/lifecycle lines written to STDERR
  (e.g., “Accepted”, “Closed without sending a request”, “[200]: GET /…”) as normal output, while preserving real errors.

## [1.7.2] - 2025-10-21 — Antares

Patch release improving route loading resilience and dev-server log clarity.

### Fixed

- Extensions: `ServiceProvider::loadRoutesFrom()` is now idempotent and exception-safe.
  - Prevents duplicate route registration if the same routes file is loaded more than once.
  - Catches exceptions from route files; logs and continues in production, rethrows in non‑production for fast feedback.

### Improved

- CLI: `serve` command log handling reclassifies PHP built‑in server access/startup lines from STDERR as normal output, reducing false `[ERROR]` noise while preserving real error reporting.

## [1.7.1] - 2025-10-21 — Canopus

Patch release addressing extension discovery/boot sequencing so extensions reliably load at runtime.

### Fixed

- Extensions: Call `ExtensionManager::discover()` before `::boot()` during framework initialization
  (`src/Framework.php`). This resolves a bug where enabled extensions appeared as
  “EXCLUDED from final provider list” and their `boot()` never ran.
- Migrations: Extension migrations registered via `loadMigrationsFrom()` are now properly discovered
  by `migrate:status`/`migrate:run` once providers are discovered at boot.
- CLI: `extensions:why`/`extensions:list` now reflect included providers after boot, improving
  diagnostics when extensions are enabled via config or Composer discovery.

### Impact

- Applications that previously saw “No pending migrations found” for extension migrations should now
  see those migrations once the provider is enabled. No config changes are required.

## [1.7.0] - 2025-10-18 — Procyon

Major async/concurrency subsystem. Introduces a fiber-based scheduler, async HTTP client with streaming, buffered I/O, cooperative cancellation, metrics instrumentation, and a Promise-style wrapper for ergonomic chaining. Includes centralized async configuration and DI wiring.

### Added

- Async/Concurrency: Fiber-based `Glueful\Async\FiberScheduler` with `spawn`, `all`, `race`, and `sleep` semantics.
- Tasks: `FiberTask`, `ClosureTask`, `CompletedTask`, `FailedTask`, `DelayedTask`, `RepeatingTask`, `TimeoutTask`.
- Helpers: `scheduler()`, `async()`, `await()`, `await_all()`, `await_race()`, `async_sleep()`, `async_sleep_default()`, `async_stream()`, `cancellation_token()`.
- Async I/O: `Glueful\Async\IO\AsyncStream` and `BufferedAsyncStream` with line/whole-read helpers and buffered reads/writes.
- Async HTTP: `Glueful\Async\Http\CurlMultiHttpClient` with cooperative polling, pooling via `poolAsync()`, and streaming via `HttpStreamingClient::sendAsyncStream()`; `FakeHttpClient` for testing.
- Promise: `Glueful\Async\Promise` wrapper providing `then/catch/finally` and `all/race` composition over Tasks.
- Cancellation: `SimpleCancellationToken` and cooperative propagation across scheduler, I/O, and HTTP.
- Instrumentation: Expanded `Metrics` interface and implementations (`LoggerMetrics`, `NullMetrics`) with fiber/task events (suspend/resume, queue depth, cancellation, resource limits).
- Config: New `config/async.php` with `scheduler`, `http`, `streams`, and `limits` settings.
- DI: `AsyncProvider` wires `Metrics`, `Scheduler`, `HttpClient`, and registers `AsyncMiddleware` (alias `"async"`).

### Changed

- Scheduler: Resource limit enforcement (max concurrent tasks, per-task execution time, optional memory and file-descriptor caps); timer handling via min-heap; richer metrics hooks.
- HTTP: Refactored `CurlMultiHttpClient` to use a shared `curl_multi` pump and optional `max_concurrent` cap; retry knobs exposed via config.

### Fixed

- Cancellation and timeouts are honored during sleeps and I/O waits across scheduler and async streams.

### Documentation

- High-level async docs added to the site (API reference and troubleshooting); extensive PHPDoc across async packages.

### Tests

- New unit/integration coverage for async scheduler, HTTP client, streaming, timers, and helpers (see `tests/Unit/Async/*`, `tests/Integration/Async/*`).

### Migration Notes

- New `config/async.php`. Defaults are backward-compatible (limits disabled when set to 0). No changes required unless opting into limits or tuning.
- To use async within routes, add `AsyncMiddleware` (alias `async`) or use the helpers (`async()`, `await_all()`, etc.).

## [1.6.2] - 2025-10-14 — Capella

Template configuration responsibility moved to the Email Notification extension.

### Changed

- Mail/Templates: The primary templates directory is now controlled by the Email Notification
  extension configuration. The framework no longer sets a default `services.mail.templates.path`.
  Applications can still provide this key in their own config if desired; otherwise the extension’s
  `templates.extension_path` (and its internal default) will be used.
- Mail/Templates: `services.mail.templates.custom_paths`, caching, layout, mappings, and global
  variables remain supported at the framework level.

### Migration Notes

- If you previously relied on the framework’s default `templates.path`, set your preferred primary
  directory via the extension config (`email-notification.templates.extension_path`) or add your own
  `services.mail.templates.path` in application config.

## [1.6.1] - 2025-10-14 — Arcturus

JWT RS256 signing support.

### Added

- Auth/JWT: RS256 signing support via `JWTService::signRS256(array $claims, string $privateKey)`
  for generating JWTs using an RSA private key. Requires the `openssl` extension.

## [1.6.0] - 2025-10-13 — Sirius

Minor features and DX improvements.

### Added

- DI/Compile: emit `services.json` manifest during container compile at
  `storage/cache/container/services.json` containing `shared`, `tags`, `provider`, `type`, and `alias_of`.
- CLI: `di:container:map` now prefers the compiled `services.json` in production to avoid reflection.
- Runtime: `ContainerFactory` prefers a precompiled container class in production when available.
- Router: `ConditionalCacheMiddleware` for ETag/If-None-Match and Last-Modified/If-Modified-Since 304 handling.
- HTTP: `Response::withLastModified(DateTimeInterface)` helper.
- Config: `Glueful\Config\DsnParser` with `parseDbDsn()` and `parseRedisDsn()` utilities.
- CLI: `config:dsn:validate` to validate Database/Redis DSNs from flags or environment.
- Docs: `docs/roadmap-1.6-status.md` tracking 1.6 implementation status.

### Changed

- `di:container:compile` writes both the compiled container PHP and the `services.json` manifest.

### Fixed

- Removed redundant string casts flagged by PHPStan in container boot/loader paths.

## [1.5.0] - 2025-10-13 — Orion

Notification system wiring improvements and safer email verification flow.

### Added

- DI provider for notifications: `Glueful\Container\Providers\NotificationsProvider` registers
  `ChannelManager` and `NotificationDispatcher` as shared services.

### Changed

- EmailVerification and SendNotification now prefer DI-resolved `NotificationDispatcher`/`ChannelManager`
  with a safe fallback when DI isn’t available. This enables extensions to self‑register channels/hooks
  during boot without ad‑hoc construction.
- Removed hard dependency on `ExtensionManager` checks in email verification and password reset flows.
  Channel availability and configuration are determined at send time via the dispatcher.
- Soft diagnostics: added non‑blocking logs when the email channel is unavailable or no channels succeeded
  for email verification/password reset.
- Retry command now reads retry settings from `email-notification.retry` to align with the extension’s
  configuration namespace.

### Fixed

- Resolved namespace and escaping issues in SendNotification; addressed static analysis warnings and
  long‑line formatting in EmailVerification diagnostics.

### Developer Notes

- If an Email Notification extension is installed and enabled, it will be able to register its email
  channel and hooks against the shared dispatcher during boot. Existing fallback paths remain for
  environments without DI.

## [1.4.2] - 2025-10-11 — Rigel (patch)

Dev-only tidy-ups and documentation sync. No runtime changes.

### Fixed

- PSR-4 autoloading for tests: corrected namespace in `tests/Unit/Permissions/AttributeMiddlewareTest.php` to `Glueful\Tests\...`, removing Composer warnings during autoload generation.

### Documentation

- Updated ROADMAP and site release notes to reflect the 1.4.1 install flow improvements and guidance.

---

## [1.4.1] - 2025-10-11 — Rigel (patch)

Installation flow hardening and SQLite-first defaults. Improves non-interactive installs and avoids fragile checks during initial setup.

### Added

- Post-install guidance on switching databases and running migrations after install.
- Quiet/non-interactive support propagated to install sub-commands:
  - `migrate:run` and `cache:clear` honor `--no-interaction` and `--quiet` in install `--quiet` mode.

### Changed

- InstallCommand runs migrations with `--force` by default (equivalent to `-f`).
- Install process is SQLite-only; other engines are skipped during install and can be configured afterwards.
- `cache:clear` during install now passes `--force` (and non-interactive flags when quiet) to avoid confirmation prompts.

### Removed

- Database connection health check during install (SQLite does not require a network connection and migrations surface real issues).

### Fixed

- Eliminated redundant sqlite comparison that triggered a phpstan strict-comparison warning.
- `php glueful install --quiet` no longer prompts interactively when clearing cache or running migrations.

## [1.4.0] - 2025-10-11 — Rigel

Rigel release — consolidates session management behind a single, testable API and removes legacy token storage. This refactor simplifies dependency wiring, unifies TTL policy, and improves cache‑key safety for tokens.

### Added

- SessionStoreInterface and default SessionStore implementation as the canonical session API (create/update/revoke/lookup/health).
- TTL helpers on the store: `getAccessTtl()` and `getRefreshTtl()` with provider + remember‑me support.
- SessionStoreResolver utility and ResolvesSessionStore trait to consistently resolve the store via DI with a safe fallback.
- End‑to‑end smoke script for local validation: `tools/test_session_refactor.php` (temporary; remove as needed).

### Changed

- TokenManager now defers TTL policy to SessionStore and persists sessions via the store. Static resolver unified through SessionStoreResolver.
- JwtAuthenticationProvider and SessionCacheManager resolve the store via the new trait; reduced ad‑hoc instantiation.
- SessionAnalytics prefers the store for listing sessions (falls back to cache query when needed).
- Cache keys for sessions now use safe prefixes and hashed tokens:
  - `session_data_<uuid>`, `session_token_<sha256(token)>`, `session_refresh_<sha256(token)>`.
- JWTService cleaned up; in‑memory invalidation removed; DB‑backed revocation relied upon.

### Removed

- Legacy TokenStorageService and TokenStorageInterface (all usages migrated to SessionStore).
- Deprecated code paths and comments tied to the legacy storage/invalidation.

### Fixed

- Base64URL decoding uses URL‑safe decode paths in session flows.
- Cache key sanitization for tokens prevents invalid‑character failures across cache backends.

## [1.3.1] - 2025-10-10 — Altair

Altair patch — improves CI/automation ergonomics for initial installs and cleans up static analysis.

### Changed

- Console: `install` command is now truly non-interactive when any of these flags are present: `--quiet`, `--no-interaction`, or `--force`.
  - Skips the confirmation prompt about environment variables in these modes, enabling fully unattended setup in CI/CD.
  - Keeps informative output; for silent runs use Symfony’s global `-q` as usual.

### Fixed

- Console: removed redundant `method_exists()` guard around `InputInterface::isInteractive()` to satisfy PHPStan (the method is guaranteed by the interface).
- Minor DX polish in the install flow messaging.

## [1.3.0] - 2025-10-06 — Deneb

Deneb release — refines the HTTP client with first‑class, configurable retries via Symfony’s retry system, improving resilience and clarity for API integrations.

### Added

- HTTP client retry support using Symfony `RetryableHttpClient` with `GenericRetryStrategy`.
- `Client::withRetry(array $config)` to wrap any configured client with retries.
- `ApiClientBuilder` retry configuration via `retries(...)`, `buildWithRetries()`, and `getRetryConfig()`.
- Sensible defaults and presets in builders (e.g., payments/external service) for common retry scenarios.

### Changed

- Refactored client retry behavior to Symfony’s strategy-based approach (status codes, backoff, jitter, max retries), replacing custom retry handling for a more robust and testable implementation.

## [1.2.0] - 2025-09-23 — Vega

Vega release — introduces robust task management architecture and enhanced testing reliability. Named after one of the brightest stars in the night sky, this release brings enhanced reliability and clarity to task execution and framework testing infrastructure.

### Added

- **Tasks/Jobs Architecture**: Complete separation of business logic (Tasks) from queue execution (Jobs)
  - New `src/Tasks/` directory with business logic classes
  - New `src/Queue/Jobs/` wrappers for reliable queue integration
  - Support for both direct execution and queued processing
- **Task Management System**:
  - `CacheMaintenanceTask` - Comprehensive cache maintenance operations
  - `DatabaseBackupTask` - Database backup with configurable retention
  - `LogCleanupTask` - Log file cleanup with retention policies
  - `NotificationRetryTask` - Notification retry processing
  - `SessionCleanupTask` - Session cleanup and maintenance
- **Queue Job Wrappers**:
  - `CacheMaintenanceJob`, `DatabaseBackupJob`, `LogCleanupJob`, `NotificationRetryJob`, `SessionCleanupJob`
  - Reliable job execution with failure handling and logging
- **Enhanced Console Commands**:
  - New `cache:maintenance` command with improved options
  - Updated console application structure and service provider registration
- **Comprehensive Testing Suite**:
  - Complete integration test coverage for all Tasks and Jobs
  - Enhanced test bootstrap with proper DI container management
  - Fixed test interference issues and container state management

### Changed

- **Architecture Migration**: Migrated from `src/Cron/` to `src/Tasks/` + `src/Queue/Jobs/` pattern
- **Service Registration**: Tasks and Jobs now properly registered in DI container via `TasksProvider`
- **Testing Infrastructure**: Enhanced test bootstrap for better reliability and container management

### Removed

- **Legacy Cron Classes**: Removed all classes from `src/Cron/` directory
  - `CacheMaintenance.php`, `DatabaseBackup.php`, `LogCleaner.php`
  - `NotificationRetryProcessor.php`, `SessionCleaner.php`

### Fixed

- **Test Infrastructure**: Resolved integration test failures and DI container initialization issues
- **Code Quality**: Fixed PHP CodeSniffer violations across test files and bootstrap
- **Container Management**: Fixed test state interference between unit and integration tests

## [1.1.0] - 2025-09-22 — Polaris

Polaris release — introduces comprehensive testing infrastructure and enhanced documentation to guide framework development. Like the North Star that guides navigation, this release provides developers with the tools and knowledge to build robust applications with confidence.

### Added

- Testing utilities with `TestCase` base class for application testing
- Comprehensive event system documentation covering all framework events
- Support for framework state reset in testing environments
- Complete event listener registration patterns and best practices
- Event system abstraction layer with `BaseEvent` class

### Updated

- Enhanced event system documentation with complete examples and best practices
- Improved testing infrastructure for better framework integration

### Changed

- Event system now provides clear abstraction layer over PSR-14 implementation

## [1.0.0] - 2025-09-20 — Aurora

Aurora release — the first stable release of the split Glueful Framework package (formerly part of glueful/glueful). This version establishes the framework runtime as a standalone library with comprehensive features and sets a clear baseline for future 1.x releases.

### Added

- Comprehensive permissions and authorization system.
- Alias support for services and improved provider bootstrapping.
- Core and Console service providers for out-of-the-box wiring.
- Next‑Gen Router (complete rewrite)
  - Fast static/dynamic matching with first‑segment bucketing and deterministic precedence.
  - Attribute route loader (Controller/Get/Post/Put/Delete attributes).
  - Route cache compiler with dev TTL and invalidation for both app and framework routes.
  - Standardized JSON errors for 404/405 (405 includes Allow header).
- Dependency Injection overhaul
  - DSL for service registration (Def, ServiceDef, Utils) and compile‑time service generation.
  - Compiled DI container support for faster production startup.
- Configuration & bootstrap
  - Lazy configuration cache and path helpers; clarified Framework::create(...)->boot() flow.
  - App providers via `config/serviceproviders.php`; unified discovery with extensions.
- Extensions System v2
  - Deterministic provider discovery (app providers + vendor extensions) via ProviderLocator.
  - Extension service compilation and performance improvements.
- Observability & logging
  - BootProfiler for startup timing; standardized log processor.
  - MetricsMiddleware + ApiMetricsService; pluggable tracing middleware.
- Security
  - Expanded middleware set (Auth, Rate Limiter, CSRF, Security Headers, Admin, IP allow‑list).
  - Hardened health/readiness endpoints with allow‑list support.
  - Security CLI commands: `security:check`, `security:scan`, `security:report`, `security:vulnerabilities`.
- Caching & performance
  - File cache sharding + in‑process stats cache; tagging & warmup utilities.
- File uploads
  - FileUploader now accepts Symfony UploadedFile natively; extension+MIME validation; hazard scanning.
  - S3 storage with configurable ACL and signed URLs (private by default) and TTL.
- Field selection
  - GraphQL‑style field selection and projection utilities.
- Tooling & Docs
  - Unified GitHub Actions pipeline (`php-ci`), updated PR/Issue templates.
  - Cookbook expanded, with setup (`docs/cookbook/00-setup.md`) and uploads (`docs/cookbook/23-file-uploads.md`).

### Changed

- Dependency Injection: replaced Symfony DI with a lightweight, custom container optimized for Glueful.
- Events: migrated to PSR-14 with a custom dispatcher implementation.
- Storage: migrated to Flysystem; updated configuration structure and options.
- Configuration: refactored to array-based schemas for clarity and compile-time validation.
- Validation: moved to a rules-based system with clearer composition.
- Composer dependencies refreshed to latest compatible versions.
- Router now returns standardized JSON for 404/405 via `Glueful\Http\Response::error()`.
- DI: prefer DSL‑based `services()` definitions; compiled container recommended in production.
- Configuration/env alignment:
  - `REDIS_DB` (instead of `REDIS_CACHE_DB`), `MAIL_ENCRYPTION` (instead of `MAIL_SECURE`).
  - `LOG_FILE_PATH` (replaces `LOG_PATH`), PSR‑15 toggles (`PSR15_ENABLED`, `PSR15_AUTO_DETECT`, `PSR15_STRICT`).
  - S3 controls: `S3_ACL`, `S3_SIGNED_URLS`, `S3_SIGNED_URL_TTL`.
  - `.env.example` cleanup; `LOG_TO_DB=false` by default to avoid DB dependency.
- FileUploader resolves repositories via container; improved filename handling and MIME detection.
- Route cache invalidation also watches framework `src/routes/*.php` in development.
- ExceptionHandler removed from Composer autoload "files"; PSR‑4 autoload only.

Breaking changes:

- DI container swap may affect service definitions, compiler passes, and container-aware utilities.
- Event system changes require updating listener/subscriber registration to PSR-14.
- Storage configuration keys and adapters changed to Flysystem-based configuration.
- Config and validation refactors may require updating custom rules, schemas, and boot code.

### Removed

- Legacy LDAP/SAML authentication integration.
- Queue configuration management classes.
- Custom config and serialization modules superseded by the new configuration approach.
- Symfony DI usage and related integration points.
- Legacy route and middleware system and related documentation.
- Legacy docs/SETUP.md (moved to Cookbook); deprecated report docs.
- Old monolithic CI workflow and split test workflows (replaced by single `php-ci`).

### Fixed

- Documentation updates and cleanup in the database and storage guides.
- S3 bucket config typos in `S3Storage` (`services.storage.s3.bucket`).
- PHPStan short‑ternary warnings in `RouteCache` and `FileUploader`; safer file read fallbacks.
- Numerous type‑safety and strictness improvements across cache, auth, console, and DI layers.

### Security

- Allow‑listed health/readiness endpoints; expanded security checks and CLI audits.

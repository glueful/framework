# Glueful Framework Roadmap

This roadmap tracks high‑level direction for the framework runtime (router, DI, HTTP, config, security, caching, queues, observability). The API skeleton and documentation site have their own cadences and may reference this roadmap for synchronization.

## Purpose & Scope
- Framework only: core libraries and runtime behavior.
- Application scaffold (glueful/api‑skeleton) and docs site (glueful/docs) follow this roadmap but ship independently.

## Release Cadence & Compatibility
- Versioning: Semantic Versioning. 1.x is the stable baseline after the repo split.
- Cadence: Patches as needed; minors roughly every 4–8 weeks; majors only for clearly communicated breaking changes.
- Deprecations: Soft‑deprecate in N, remove no earlier than N+2 minors with warnings and migration notes.
- Pre‑public phase: minors may include breaking changes when called out in the CHANGELOG (codename releases like “Aurora”).

## Themes for 1.x
- Stability & performance: keep startup and dispatch budgets; expand compiled artifacts (routes, container) and caching.
- Observability/operations: first‑class tracing, metrics, structured logging, and diagnostics.
- Security hardening: CSP builder, headers, rate limiting/distributed coordination, health/readiness governance.
- Developer experience: DI DSL ergonomics, CLI UX, cookbook completeness, clearer error messages.
- Interop: PSR‑7/PSR‑15 coverage, adapters, and guidance.

## Milestones (subject to change)

### 1.67.0 — Adhil (Minor, Released 2026-07-10)
- **Extension seams: four opt-in extension points, all exact pass-throughs until bound.**
  - `Connection::newPdo()` — a fresh, non-pooled, independent PDO session (advisory locks and other
    session-scoped infrastructure can no longer be poisoned by the shared statement session).
  - `QueryExecutor::addExecutionWrapper()` (`ExecutionWrapperInterface::around()`) — composes wrappers
    around the *actual* prepare/execute, so a lock or resource can be held across the full statement
    boundary (the before-only interceptor seam cannot span execution).
  - `Connection::addInsertHook()` — write-side row hooks over `insert()`/`insertBatch()`/`upsert()` for
    transparent column stamping/transforming, with batch-shape hardening.
  - Blob lifecycle + authorization: `BlobCreatedHook` (post-persist accept/reject with checked
    compensation — object delete + `BlobRepository::forceDelete()` + verified quarantine fallback) and
    `BlobAccessPolicy` (post-core-checks authorization for `show`/`info`/`delete`/`signedUrl` via
    `BlobAccessContext`/`BlobAction`); thumbnails defer until the hook accepts
    (`FileUploader::generateThumbnailFor()`), so a rejected upload never leaves an orphaned thumbnail.
- Notes: **Minor release** — no new env vars, no migrations, no default changes; the framework binds
  none of the seams, so unbound behavior is byte-for-byte identical. Built for (and consumed by) the
  Thallo multi-tenancy runtime; the seams are deliberately tenancy-agnostic.

### 1.66.3 — Adhara (Patch, Released 2026-07-06)
- **Route caching no longer crashes routes with parenthesized `where()` constraints.** Reconstructing
  a dynamic route from the compiled cache reverse-engineered its path from the regex, mistaking a
  constraint's non-capturing `(?:…)` group for the parameter's capture group; the rebuilt route then
  had more capture groups than parameter names, raising `ValueError` from `array_combine()` in
  `Route::match()` on the first request. Exposed by 1.66.2, which re-enabled route caching for
  SPA-mounting apps. Reconstruction now rebuilds each route from its authoritative original path +
  `where` constraints (serialized into the cache) and recompiles identically to registration.
- Notes: the compiled cache format is bumped, so stale route caches regenerate automatically on
  upgrade; no code or config change required.

### 1.66.2 — Adhara (Patch, Released 2026-07-06)
- **`serveFrontend()` no longer disables route caching.** Mounting an admin/SPA bundle registered
  the mount root and `/{rest}` catch-all as closures, and `RouteCache` rejects any route table that
  contains a closure — so every SPA-mounting app ran uncached and logged a `[RouteCache]` warning on
  each boot. The seam now registers controller handlers (`SpaMountController::root`/`asset`) backed by
  a new `FrontendMountRegistry` that resolves the mount from the request path by longest-prefix match
  (multiple mounts supported). Asset/index serving is byte-for-byte identical (mime, security headers,
  immutable-vs-revalidate cache split, ETag/304, path-traversal/dotfile/`.php` denial, SPA fallback).
- Notes: no `serveFrontend()` signature/behavior change, no new env vars; SPA-mounting apps regain
  route caching automatically.

### 1.66.1 — Adhara (Patch, Released 2026-07-06)
- **The extension installer is now synchronous.** The 1.66.0 detached/background-job installer was
  unreliable under a web SAPI (`PHP_BINARY` is not a CLI interpreter behind Apache/php-cgi/nginx+FPM,
  so the job never started). `POST /extensions/install` now runs `composer require` inline and
  returns the result in one response; the extension installs disabled and is activated with the
  enable toggle (WordPress-style). composer runs as `<cli-php> <composer> require …` with an explicit
  env (`COMPOSER_HOME`), independent of the web PATH/`putenv`/`HOME`. Removed the job-poll endpoint,
  the `DetachedRunner`/`InstallJobStore` and the two internal runner commands.
- **Extensions installer no longer rejects installable extensions with `422`.**
  `ExtensionCatalog::hydrateVersion()` required *every* Packagist release to be typed
  `glueful-extension`, so any extension whose older tags predate that type (Packagist omits the
  field when it defaults to `library`) was dropped from the catalog and the install allowlist
  rejected it. Type re-verification now checks the latest release only.
- Notes: install API reshaped (single response, no job polling); the broken 1.66.0 installer
  affected no working integration. Run `php glueful cache:clear` after upgrading.

### 1.66.0 — Adhara (Minor, Released 2026-07-05)
- **Install extensions from the admin UI, no SSH.** A new install pipeline runs `composer require`
  for a catalog extension from the browser: `ExtensionInstaller` validates the package against the
  Packagist catalog (allowlist + `glueful/` vendor prefix, no shell), spawns a **detached**
  `composer require` (`proc_open` + array argv + `setsid`, survives FPM recycles), then auto-enables
  in a **fresh PHP subprocess** to dodge the running worker's stale autoloader and rewrites the
  extension cache. Progress polls a `CacheStore`-backed job store. Ships `src/Extensions/Install/`,
  `src/Support/Process/`, `ExtensionCatalog`, and a batteries-included `ExtensionsController` at
  `/api/v1/extensions`.
- **Guardrails:** `system.config` permission tier, `EXTENSIONS_INSTALL_ENABLED` kill-switch (off in
  production by default), host-writability preflight (`409` on read-only deploys), catalog-membership
  allowlist, and per-install audit logging.
- **SVG uploads no longer 400 on the content check.** `FileUploader::validateFileContent()` now honors
  the configured `uploads.allowed_types` allowlist (wildcards included) instead of the hard-coded
  default constant; safety posture unchanged (SVG served as attachment, `<script>` payloads rejected).
- Notes: **Minor release** — adds three optional `EXTENSIONS_INSTALL_*` env vars with safe defaults;
  no migrations, no breaking changes.

### 1.65.3 — Acrux (Patch, Released 2026-07-03)
- **`RandomStringGenerator::generate()` no longer reads past its random-byte buffer.** The
  rejection-sampling inner loop consumed bytes without a refill guard; rejections clustering at
  the buffer end walked past it ("Uninitialized string offset N") — an inherently flaky failure
  in consumers generating passwords, and a quiet bias risk (`ord('')` = 0 favors the charset's
  first character). The inner loop now refills before reading, with a worst-case-charset
  regression test.
- **`serveFrontend()` static assets get extension-mapped MIME types.** Content sniffing called
  CSS/JS `text/plain` (no magic bytes), and the accompanying `nosniff` header made browsers
  refuse them outright. Extension map first (`css` → `text/css`); sniffing only for
  extensionless files.
- Notes: **Patch release** — bugfix only, no new env, no migrations, no behavioral changes.

### 1.65.2 — Acrux (Patch, Released 2026-07-02)
- **Array-valued `fields`/`expand` query params no longer 500.** `FieldSelector::fromRequest()` /
  `fromRequestAdvanced()` read those params through `query->all()` and treat a non-string value as
  absent, instead of letting Symfony `InputBag`'s non-scalar guard throw a `BadRequestException`
  (an unhandled 500 on public read/delivery endpoints when a client sends `?fields[]=a`). Scalar
  `fields`/`expand` parse exactly as before.
- Notes: **Patch release** — bugfix only, no new env, no migrations, no behavioral changes.

### 1.65.1 — Acrux (Patch, Released 2026-07-01)
- **`extensions:enable`/`disable` no longer leave a trailing-whitespace line** in
  `config/extensions.php`. `ExtensionStateWriter::writeList()` re-emitted a literal indent in front
  of the captured pre-bracket whitespace, producing a dangling 4-space line above `]` on every
  toggle — which then tripped `Squiz.WhiteSpace.SuperfluousWhitespace` in phpcs/CI. The writer now
  folds that whitespace into the match and writes the closing indent + bracket cleanly.
- **CLI commands no longer collide with Symfony's global option shortcuts** — `serve --queue`
  dropped `-q`, `cache:expire --verify` and `di:container:compile --validate` dropped `-v`, and
  `install --quiet` was renamed `--unattended`; the commands were previously unrunnable (Symfony
  threw on the shortcut clash at merge time). All long options preserved.
- Notes: **Patch release** — bugfixes only, no new env, no migrations, no behavioral changes.

### 1.65.0 — Acrux (Minor, Released 2026-06-30)
- **`QueryBuilder::forceDelete()`** permanently deletes matching rows, bypassing soft-delete even on
  a `deleted_at` table — previously hard-deleting such a row needed raw SQL. Added to
  `QueryBuilderInterface`.
- **Validation coercion rules** — `CastToInt`, `CastToBoolean`, `CastToDate` `MutatingRule`s let a
  validator pipeline both normalize and validate (`filtered()` previously returned uncoerced input).
- **`DbUnique` exclude-by-column** — a fifth `$exceptColumn` argument (default `'id'`) so a record
  keyed by a non-`id` column (e.g. `uuid`) can exclude the current row on update.
- **`api_key_uuid` request attribute** — `ApiKeyAuthenticationProvider` exposes the acting key's own
  uuid alongside `api_key_scopes`, for per-key attribution (audit, rate-limit keying).
- **`ServiceProvider::resetLoadedRoutes()`** resets the process-global loaded-routes latch so a fresh
  `Framework::boot()` in the same process re-registers extension route files (notably across test boots).
- **Routing/schema fixes.** `AuthMiddleware` now always populates `auth.user` (synthesises a basic
  `UserIdentity` when no enricher ran, so permission gates never silently fail-closed);
  `RequireScopeMiddleware` now enforces file-defined `require_scope:` params fail-closed (it previously
  fell open); `TableBuilder::alterTable()` now applies `dropColumn()` (previously a silent no-op).
- Notes: **Minor release** — no new env, no migrations, no breaking changes. Scope enforcement
  tightened (see CHANGELOG Upgrade Notes). api-skeleton bumped to `^1.65.0`.

### 1.64.0 — Zosma (Minor, Released 2026-06-28)
- **API key prefix is configurable.** `ApiKeyService` reads the brand from `auth.api_keys.prefix`
  (env `API_KEY_PREFIX`, default `gf`), so apps can rebrand generated keys (`gf_live_…` → `lm_live_…`).
  Only the first 16 chars are stored as the indexed lookup prefix. Backward compatible — the default
  reproduces the existing `gf_*` keys.
- **API key lifecycle is auditable.** `ApiKeyService::create/rotate/revoke` now emit framework entity
  events for the `api_keys` table (previously they went through `Model::save()` and emitted nothing),
  so an audit consumer can record who minted/rotated/revoked a key. Identity-only payload (never the
  plaintext or `key_hash`); best-effort dispatch.
- **Webhook management fixes.** List endpoints no longer 500 on the strict query validator
  (`->limit()->offset()` order); `WebhookSubscription` inserts no longer fail on PostgreSQL
  (`$timestamps = false`); auto-created webhook UUID columns widened to `varchar(32)` to fit the
  generated ids. These were latent — core ships the controller but doesn't register its routes.
- **Uploaded blob visibility is now persisted.** `FileUploader::saveBlobRecord()` now writes the
  requested `visibility` (falling back to `uploads.default_visibility`); previously every blob fell
  back to the `private` default, so a "public" upload 401'd on retrieval.
- Notes: **Minor release** — one new optional env (`API_KEY_PREFIX`, default `gf`, backward
  compatible), no migrations, no breaking changes. api-skeleton bumped to `^1.64.0`.

### 1.63.5 — Yildun (Patch, Released 2026-06-27)
- **Webhook management API fully typed in OpenAPI.** Built on 1.63.4 (operation summaries) by adding
  the schemas: `#[QueryParam]` for the list/stats query params, `#[ApiRequestBody]` for create/update,
  and `#[ApiResponse(schema: …)]` for the subscription/delivery/list/stats responses, backed by new
  doc-only DTOs under `Glueful\Api\Webhooks\DTOs`. Applications that mount the controller now get a
  precise spec + typed client. No behavior change (DTOs reflected for docs only, never hydrated).
- Notes: **Patch release** — documentation metadata only, no behavioral change, no new env, no
  migrations, no action required. api-skeleton bumped to `^1.63.5`.

### 1.63.4 — Yildun (Patch, Released 2026-06-27)
- **Webhook management API is self-documenting.** The framework's `WebhookController` (subscriptions +
  deliveries) had working routes but no OpenAPI attributes, so applications that mount it got endpoints
  invisible to `generate:openapi` and the typed client. Added `#[ApiOperation]`/`#[ApiResponse]` to all
  11 methods — they're now documented like any annotated controller. No behavior change; the core still
  doesn't auto-register these routes.
- Notes: **Patch release** — documentation metadata only, no behavioral change, no new env, no
  migrations, no action required. api-skeleton bumped to `^1.63.4`.

### 1.63.3 — Yildun (Patch, Released 2026-06-26)
- **Blob writes are auditable.** `BlobRepository` was built without an `ApplicationContext`, so its
  create/update/delete never dispatched entity events — blob uploads emitted no `EntityCreatedEvent` and
  couldn't be audited. It's now constructed with the context, so uploads emit events an audit/activity
  consumer can record.
- Notes: **Patch release** — bugfix, no new env, no migrations, no action required. api-skeleton bumped
  to `^1.63.3`.

### 1.63.2 — Yildun (Patch, Released 2026-06-26)
- **Image-variant caching fix.** Serving a resized blob (`GET /blobs/{uuid}?width=…`) with the variant
  cache enabled 500'd — the rendered binary was cached through a JSON serializer (e.g. the Redis driver's
  `SecureSerializer`), which can't encode non-UTF-8 bytes. Variants are now cached base64-encoded and
  decoded on read; a corrupt/legacy entry re-renders. The un-resized original was never affected.
- Notes: **Patch release** — bugfix only, no new env, no migrations, no action required. api-skeleton
  bumped to `^1.63.2`.

### 1.63.1 — Yildun (Patch, Released 2026-06-25)
- **Resilient event dispatch.** `EventDispatcher::dispatch()` now isolates listener failures — it catches
  each listener's `Throwable`, logs it, and continues — so one broken/misconfigured listener can't abort
  the dispatch and starve the rest of the chain (previously the first throw ended dispatch for that event).
- **`ActivityLoggingSubscriber` is resolvable again.** It required a non-nullable `LogManager` that the
  container never registers, so it threw on every auth/security event — and (pre-isolation) that aborted
  delivery of those events to *all* listeners. Now takes `?LogManager $logger = null` with a
  `getInstance()` fallback. Together these restore login/logout/security event delivery to listeners
  (framework activity logging + app/extension subscribers such as glueful/audit).
- **Failed logins emit `AuthenticationFailedEvent`.** The event existed and had subscribers but was never
  dispatched; `AuthenticationService::verifyCredentials()` now fires it (best-effort, context-guarded) on
  rejected credentials (`invalid_credentials`) or a disabled account (`user_disabled`), with the attempted
  username and request IP/user-agent — making failed login attempts observable to audit/activity logging.
- Notes: **Patch release** — bugfixes, no new env, no migrations, no action required. api-skeleton bumped to `^1.63.1`.

### 1.63.0 — Yildun (Minor, Released 2026-06-25)
- **Entity-deletion event.** `BaseRepository::delete()` now dispatches `Glueful\Events\Database\EntityDeletedEvent`
  after a successful delete (`affected_rows > 0`), carrying the **pre-delete** record + metadata matching the
  create/update events (`entity_id`, `primary_key`, `affected_rows`, `operation`). Completes the create/update/delete
  event triplet so audit/cache-invalidation/notification consumers can react to deletes. Mirrors `EntityCreatedEvent`
  (`getEntity()`/`getEntityId()`/`getTable()`/`getCacheTags()`/`isUserRelated()`, plus a `getOriginalData()` alias).
- **Subclass domain-event dispatch.** `BaseRepository::dispatchEvent()` widened `private` → `protected` so repository
  subclasses (incl. extensions) can emit their own domain events through the framework's best-effort, context-guarded
  helper (no-ops without an injected `ApplicationContext`).
- Notes: **Minor release**, additive — a new event (fires only if subscribed) + a visibility widening; no breaking
  changes, no new env, no migrations. api-skeleton bumped to `^1.63.0`.

### 1.62.0 — Xuange (Minor, Released 2026-06-24)
- **User-record enrichment seam.** New `Glueful\Auth\Contracts\UserRecordEnricherInterface` + the
  `users.record_enricher` tag — the read-side symmetric of `identity.claims_provider`. Lets an
  authorization extension (glueful/aegis) attach a user's `roles` to the records an identity store
  (glueful/users) returns on `/users`, `/users/{uuid}` and `/me`, with no dependency between the two.
- Notes: **Minor release**, additive — no behavior change unless an extension registers an enricher,
  no new env, no migrations. api-skeleton bumped to `^1.62.0`.

### 1.61.2 — Wezen (Patch, Released 2026-06-23)
- **Permission gate no longer fail-closes (403) for authorized users.** `AuthMiddleware::autoEnrichRequest()` resolved the `auth.user` enricher by a leading-backslash container id (`'\Glueful\…\AuthToRequestAttributesMiddleware'`) that never matched the `::class` registration key, so `Container::has()` returned false, the enricher never ran, and `GateAttributeMiddleware` saw a null principal — returning 403 on every `#[RequiresPermission]` / `gate_permissions` route even for fully authorized users. Lookup now uses the `::class` constant.
- **File/Memcached cache drivers accept colon-namespaced keys**, matching Redis — login no longer fails on those backends when `SessionCacheManager` stores `session:`-prefixed keys.
- Notes: **Patch release** — bugfixes, no new env, no migrations, no action required. api-skeleton bumped to `^1.61.2`.

### 1.61.1 — Wezen (Patch, Released 2026-06-22)
- **CORS on every response.** `Application::handle()` now applies CORS headers to the final response in both the dispatch and exception-handler branches — previously only the OPTIONS preflight carried them, so cross-origin **regular** and **error** responses (422/401/…) shipped without `Access-Control-Allow-Origin` and browsers withheld the body. New public `Cors::applyToResponse(Request, Response)` supports the fix (no-op without an `Origin`, for disallowed origins, or when already set — never clobbers the preflight responder).
- Notes: **Patch release** — bugfix, no new env, no migrations, no action required. api-skeleton bumped to `^1.61.1`.

### 1.61.0 — Wezen (Minor, Released 2026-06-20)
- **OpenAPI tag allow/deny filter.** The doc generator gains `documentation.options.tags.include` / `.exclude` (env `API_DOCS_INCLUDE_TAGS` / `API_DOCS_EXCLUDE_TAGS`) — drop operations from the generated spec by tag *before* write, so a consumer-facing spec can exclude infra groups (e.g. `Health`/`Documentation`/`Security`) without disabling whole route sources. Allow-list empty = keep all; deny wins; dropped ops take their now-unreferenced tags + schemas with them. Pure static `DocGenerator::filterPathsByTags()`.
- **Doc-config cleanup.** Removed the dead `documentation.paths.route_definitions` / `extension_definitions` keys (unread by the reflect generator, which merges only top-level `docs/json-definitions/*.json`).
- Notes: **Minor release**, additive — filtering defaults off, no breaking API changes, no new migrations. api-skeleton bumped to `^1.61.0`.

### 1.60.0 — Vega (Minor, Released 2026-06-19)
- **Engine-agnostic installer (`php glueful install`).** Install now configures + migrates **any** engine (MySQL/PostgreSQL/SQLite), not just SQLite — the DB step's SQLite-only guard is gone, replaced by a connection test + `migrate:run` against the configured engine, and the previously-orphaned interactive credential prompts are reconnected. The command is a thin wrapper (710 → 238 lines) over the new orchestrator.
- **`Glueful\Installer\` first-run setup seams.** Reusable services an app can drive from CLI **or** a UI without shelling out: `EnvWriter` (atomic, quoted `.env` writes — the single writer now, replacing two unsafe copies in `install`/`generate:key`), `ConnectionTester` (transient probe of explicit creds with a short connect timeout + typed result), `Installer` (preflight-first pipeline, step-based result), `DatabaseConfig`, `InstallState`. Two hard invariants: a failed DB test mutates nothing, and the tested credentials are exactly the connection migrations run on (`MigrationManager` gains an optional injected `Connection`). PostgreSQL `sslmode`/`connect_timeout` now reach the DSN.
- Notes: **Minor release**, additive — no breaking API changes, no new env, no migrations. **Has `### Upgrade Notes`** — `install` is now interactive; non-interactive callers pass `--quiet`/`--skip-database` (api-skeleton's `post-create-project-cmd` updated). api-skeleton bumped to `^1.60.0`.

### 1.59.0 — Unukalhai (Minor, Released 2026-06-19)
- **First-party frontend serving (`ServiceProvider::serveFrontend()`).** A new provider seam serves a built SPA or static bundle at any **literal** path (e.g. `/admin`, `/app/console`) — not just `/extensions/{mount}`. It reuses the proven hardened file engine (path-traversal/dotfile/`.php` denial, mime, `SecurityHeaders`, ETag/304), adds an `index.html` deep-link fallback for client-side routing (`spaFallback`, default on; pass `false` for a plain 404-on-miss bundle), and a content-hash-aware cache split (immutable hashed assets vs `no-cache` shell so deploys are seen). Request trailing slashes are normalized by the router; the mount argument is strict. Replaces and removes `mountStatic()`, and deletes the unused `SpaManager`/`StaticFileDetector`/`SpaProvider`.
- **OpenAPI doc ergonomics.** The reflect generator's auto-inferred `401`/`403` (secured) and `429` (rate-limited) responses now carry a default `{success,message}` JSON body (configurable via `documentation.errors`, incl. always-emitted statuses like `500`), and `#[FromQuery]`/`#[FromRoute]` accept optional `description`/`example` so query/path params can move into typed DTOs without losing docs — removing walls of repeated `#[QueryParam]`/`#[ApiResponse]` attributes.
- **Router HEAD fix.** `Router::dispatch()` no longer 500s on a `HEAD` to a `BinaryFileResponse` (it stripped the body with `setContent('')`, which that response rejects); it now swaps in a body-less `Response` preserving status + headers. Affects any file/download route.
- Notes: **Minor release** (new features + a breaking `mountStatic()` removal), per the pre-public policy. **Has `### Upgrade Notes`** — migrate `mountStatic('foo', $dir)` → `serveFrontend('/foo', $dir)`. No env changes, no migrations. api-skeleton bumped to `^1.59.0`.

### 1.58.1 — Thuban (Patch, Released 2026-06-15)
- **OpenAPI response-schema fidelity.** Three additive reflect-generator fixes so typed `ResponseData` DTOs document response bodies accurately: the success envelope marks `success`/`message`/`data` as `required`, and `ClassSchemaReflector` honors `#[ArrayOf]` in **response** mode (it was request-mode only, falling back to `@var`). To allow `#[ArrayOf]` on response-DTO item types, the attribute is relaxed to target any class; the request-DTO "element must implement `RequestData`" guarantee is preserved by moving the check into the hydrator, where it fails loud alongside the other v2 structural-misuse guards.
- Notes: **Patch release**, fully additive — no behavior change for request DTOs, no config/env changes, no migrations. api-skeleton bumped to `^1.58.1`.

### 1.58.0 — Thuban (Minor, Released 2026-06-15)
- **Typed request-DTO hydration v2.** Promotes `RequestData` hydration from v1 (flat scalars only) to a full convention: `array`/nested-`RequestData` fields hydrate recursively and fail as a clean `422` (never a `TypeError`/500), with dot-path error keys (`schema.0.name`) and a depth cap. Fields can be sourced from path/query — not only the JSON body — via explicit `#[FromRoute]`/`#[FromQuery]` attributes (body is the default; one source per field). `#[ArrayOf('scalar'|DtoClass::class)]` is the sole element-type source for request DTOs (`@var` is not read). A `ValidatesSelf` contract adds a post-hydration cross-field hook, and a built-in-aware `RuleRegistry` (container-bound, built-in names reserved) lets apps register custom named rules used in `#[Rule]`. Source-attribute misuse (dual-source, a source attr on a nested DTO, or a `#[FromRoute]` with no route placeholder) fails loud, including at OpenAPI generation. The reflect generator emits `#[FromRoute]`→path / `#[FromQuery]`→query params and excludes them from the body schema.
- Notes: **Minor release**, fully additive — flat scalar v1 DTOs are byte-identical, no config/env changes, no migrations. Closes the request-DTO v1 boundaries from 1.57.0. api-skeleton bumped to `^1.58.0`.

### 1.57.0 — Sargas (Minor, Released 2026-06-14)
- **Types-first request/response DTO I/O.** A typed convention where types drive both runtime enveloping and the generated OpenAPI spec: `RequestData` params are hydrated + validated from the JSON body (`#[Rule]`), `ResponseData` returns are auto-enveloped (`#[ResponseStatus]`, `HasResponseMessage` for custom messages), `CollectionResponse`/`PaginatedResponse` cover lists, a `422` is auto-derived from request DTOs, and `JsonResource`/`ResourceCollection`/`PaginatedResourceResponse` returns are auto-normalized through their own `toResponse()`. New `scaffold:dto` command. The framework's own auth/upload/resource/health controllers adopt the convention as worked reference examples.
- **OpenAPI consolidated to a single code-first `reflect` generator; the legacy `comments` (docblock) generator removed.** Structure (paths, params, security, request/response schemas) is derived from the live route table + types; a minimal typed attribute surface (`#[ApiOperation]`, `#[QueryParam]`, `#[ApiRequestBody]`, `#[ApiResponse]` `body:`) documents only what types can't express. `reflect ⊇ comment` was proven over the live route table before the comment parser was deleted along with the `documentation.generator` switch and `API_DOCS_GENERATOR` env var.
- Notes: **Minor release** (new features + comment-generator removal + default changes), per the pre-public policy. **Has `### Upgrade Notes`** — the `comments` OpenAPI generator, `documentation.generator`, and `API_DOCS_GENERATOR` are removed; route `@route`/`@summary`/`@response` docblocks are no longer read (use the typed DTOs + `#[Api*]` attributes). No migrations. api-skeleton bumped to `^1.57.0`.

### 1.56.0 — Rastaban (Minor, Released 2026-06-13)
- **Security hardening pass, second wave.** Continues the June 2026 review across the runtime's remaining sensitive surfaces. Queue/scheduler: persisted database/Redis queue payloads and scheduled-job envelopes are HMAC-signed (handler class + parameters + row name/schedule) and verified before handler resolution; stored handler classes must implement `JobInterface` to be instantiated (no more arbitrary-constructor trigger from a writable backend). Deserialization: the remaining native `unserialize()` cache sinks (`MemcachedCacheDriver`, `FileNode`, `CacheMaintenanceTask`) now route through `SecureSerializer`.
- **SSRF-safe HTTP + redaction.** `Client::safeRequest()`/`safeFetch()`/`safeRequestAsync()` validate scheme + resolve + public-IP-pin each hop and pin the validated DNS result (anti-rebinding); webhooks and external health checks use the safe path (health checks may opt into private targets via `allow_private_hosts`). Sensitive-parameter redaction is unified in `Glueful\Support\SensitiveParamRedactor` across request/response logging, exception reporting, auth access logs, and the security-violation listener; rate-limit cache keys hash IP/identifier material.
- **Fail-closed defaults + JWT temporal claims.** The standalone CORS handler no longer defaults open and `CORS_SUPPORTS_CREDENTIALS` defaults to `false`; `ImageSecurityValidator` defaults to an empty allow-list with external URLs disabled; `JWTService::decode()` requires bounded `exp`/`nbf`/`iat`. File encryption switches to chunked authenticated streaming and rejects all-zero keys; `RequestProvider` honors `TRUSTED_PROXIES`.
- Notes: **Minor release** (default changes + new env vars), per SemVer/the pre-public policy. **Has `### Upgrade Notes`** — CORS fails closed (`CORS_SUPPORTS_CREDENTIALS` now `false`), remote image fetch opt-in, queue/scheduler payloads signed by default (`QUEUE_PAYLOAD_SIGNING` / `QUEUE_REQUIRE_SIGNED_PAYLOADS`), JWT requires `exp`, Memcached cache format change (flush on upgrade), set `TRUSTED_PROXIES` behind a proxy. New optional `http.safe_fetch.max_redirects`; no migrations. api-skeleton bumped to `^1.56.0`.

### 1.55.0 — Peacock (Minor, Released 2026-06-11)
- **Security & correctness hardening pass.** A focused sweep of the security-sensitive surfaces, from a five-part framework review. Routing/permissions: `#[RequiresPermission]`/`#[RequiresRole]` now actually enforce (Router populates `handler_meta`; `AttributeRouteLoader` auto-attaches the gate for method- and class-level attributes) — previously a fail-open. Auth: `#[RequireScope]` no longer passes for non-API-key (JWT) requests; the unverified-JWT claims fallback is removed; `?api_key=` is off by default behind `security.api_keys.allow_query_param`. Storage: all `FlysystemStorage` writes/reads/deletes route through PathGuard (added `StorageManager::put()`/`fileExists()`/`delete()`); signed URLs fail closed without a configured secret.
- **Database data-integrity + injection hardening.** Soft-delete column cache is namespaced per `Connection` (no cross-database poisoning); `ConnectionPool::release()` rolls back open transactions + resets session state before reuse; duplicate-column WHERE predicates on UPDATE/DELETE now both apply (range support) instead of silently collapsing (over-deletion). JOIN/HAVING/ORM-`has()` operators and JOIN types are allow-listed, JSON paths grammar-validated, `wrapIdentifier()` doubles embedded quotes.
- **Container/extension boundary fails loud.** Extension load failures (`services()`/`defs()`/`tags()`) rethrow outside production (recorded + WARNING-logged in prod) instead of silently dropping the provider; a service bound to a bare interface/abstract is rejected at load time. Closes the recurring extension-wiring bug class. Plus `SecureSerializer` gadget-shape exclusion and a config-gated CSRF fail-closed rate limiter.
- Notes: **Minor release** (new feature + default changes), per SemVer/the pre-public policy. **Has `### Upgrade Notes`** — permission attributes now enforce (bind a provider or expect 403s), API-key query param off by default, signed URLs require a configured secret, extensions fail loud at boot. New optional config keys `security.api_keys.allow_query_param` / `security.csrf.rate_limit_fail_closed`; no new env vars, no migrations. api-skeleton bumped to `^1.55.0`.

### 1.54.0 — Okab (Minor, Released 2026-06-10)
- **Container precedence fix (every core-default seam is now real).** `ContainerFactory` merged extension definitions with `+=`, silently dropping any extension override of a core-bound id — `UserProviderInterface`, and every "core default + extension override" seam, was un-overridable through the normal provider path. Now `array_replace` (extension-over-core) with `ApplicationContext` re-pinned post-merge. Deploy note: regenerate the precompiled container (`php glueful di:container:compile --force`) — a pre-1.54 artifact still encodes the old precedence.
- **Entitlement seam (`Glueful\Entitlements`, contract only).** `EntitlementCheckerInterface` (`allows()`/`limit()`, explicit tenant uuid) + absent-allow `NullEntitlementChecker` bound in `CoreProvider`. Core ships no consumer and learns nothing about tenants/plans; `glueful/subscriptions` binds the real checker and the first consumer (an entitlement-driven rate-limit `TierResolver`). Rule established: promote contracts to core; keep consumers extension-side unless naturally generic and tenancy-free.
- **Storage driver registry + provider-pack extraction (breaking).** New `StorageDriverFactoryInterface`/`StorageDriverRegistryInterface` + optional `NativeSignedUrlProviderInterface`/`StorageHealthCheckInterface` capability contracts; factories register via the `storage.driver_factory` tag (higher tag priority wins). Core keeps only `local`/`memory`; `s3`/`gcs`/`azure` extracted to first-party packs (`glueful/storage-s3` ships now, incl. R2/MinIO/Spaces/Wasabi presets; gcs/azure follow). Missing drivers fail fast with a package-naming `UnsupportedStorageDriverException`. Plus: `storage:test [disk]` (read-only; `--write` opt-in), optional default-off visibility-scoped `native_url` in the blob API, and the effective-disk `blobs.storage_type` fix.
- Notes: **Minor release with breaking changes**, per the pre-public policy. Deploy: `php glueful commands:cache` (new command) + `di:container:compile --force` (precedence fix). Optional env `UPLOADS_NATIVE_MAX_PRIVATE_TTL`; no core migrations. api-skeleton bumped to `^1.54.0`.

### 1.53.0 — Nunki (Minor, Released 2026-06-08)
- **Generic DB extension seams + queue/query write-path fixes.** Two new **chainable** core seams let extensions hook the database layer without patching it: pre-execution query interceptors (`QueryExecutor::addQueryInterceptor()` — interceptors run before every statement and may *veto* it, e.g. scope/access enforcement) and `Connection::table()` decorator hooks (`Connection::addTableHook()` — auto-apply scopes/columns to raw query-builder access by table). Both are no-ops on a plain install (zero behavior change) and run all registrations in order (no last-writer-wins). Landed first as the prerequisite for the forthcoming `glueful/tenancy` extension but intentionally generic.
- **Four bug fixes surfaced while building `glueful/tenancy`:** (1) `SecureSerializer` namespace-wildcard allowlist (e.g. `Glueful\Queue\Jobs\*`) is now actually honored — worker-side `Job::unserialize()` was broken for those classes — and `C:` (Serializable) tokens are now validated; (2) table-qualified WHERE columns (`->where('t.col', …)->update()/->delete()`) no longer throw `InvalidArgumentException` (an incomplete identifier-unwrap left a stray quote the column validator rejected; SELECT was unaffected); (3) `Connection::class` now resolves from the container (it was bound only as `'database'`, so the documented `db($ctx)` / `app($ctx, Connection::class)` accessors threw "not found" in a real app).
- Notes: **Minor release, fully backward compatible.** Framework-only — no env vars, no migrations, no breaking changes. The api-skeleton `^1.52.0` constraint already permits 1.53.0 (no skeleton changes this release).

### 1.52.0 — Mizar (Minor, Released 2026-06-07)
- **Lean core — four subsystems extracted to optional extensions**: Archive (`glueful/archive`), CDN / edge-cache (`glueful/cdn`), queue operations — supervision / autoscaling / worker-metrics (`glueful/queue-ops`), and rich media — image processing / thumbnails / metadata (`glueful/media`) move out of core into standalone extensions, each behind a narrow core seam (`EdgeCacheInterface` + `NullEdgeCache`; `WorkerMonitorInterface` + `NullWorkerMonitor`; `MediaProcessorInterface`). A plain core install boots, serves uploads, runs a lean single-worker `queue:work`, and caches responses with none of these subsystems' heavy deps present — `intervention/image` and `james-heinrich/getid3` are dropped from core `require`.
- Notes: **Minor release with breaking changes**, per the pre-public policy — removed classes/commands/config + dropped deps, each restored via a single `composer require glueful/{archive,cdn,queue-ops,media}`. The command-surface removals (`archive:manage`, `cache:purge`, `queue:autoscale`) require `php glueful commands:cache --clear` on deploy. Additive in core (no action): `queue:work --once` / `--connection=` and `WorkerOptions` `0 = unlimited`. Full migration guide in `UPGRADE.md`; api-skeleton bumped to `^1.52.0` (ships lean — extensions opt-in).

### 1.51.0 — Larawag (Minor, Released 2026-06-06)
- **Notification subsystem refinement**: core in-app `database` channel + dispatch-time channel validation (single source of truth = the `ChannelManager` registry); optional/safe persistence via `NotificationStoreInterface` + `NullNotificationStore` (`NOTIFICATIONS_DATABASE_STORE=false` degrades explicitly rather than hitting gated tables); an injectable async-queue seam (`NotificationQueueDispatcherInterface`); structured channel results (`NotificationResult` + opt-in `RichNotificationChannel`); and extension-driven channel registration via `ServiceProvider::boot()` helpers into the shared `ChannelManager`/dispatcher.
- Notes: **Minor release with breaking changes**, called out in the CHANGELOG with Upgrade Notes — `ChannelManager` channel-name methods renamed (no aliases); notification jobs/commands now require an `ApplicationContext`; the framework no longer hardcodes the `EmailNotification` provider (channel packages self-register from `boot()`). No env vars, no migrations; the `notifications` capability default stays `true`.

### 1.50.2 — Kochab (Patch, Released 2026-06-05)
- **`@queryParam` route-doc tag**: `CommentsDocGenerator` now parses `@queryParam name:type="…" [{required}]` in route docblocks as an `in: query` OpenAPI parameter — an editor-clean alternative to the positional `@param … query …` form, which overloads the reserved `@param` tag and triggers IDE/Intelephense false positives (P1133). The legacy `@param` form still parses.
- **Doc-gen path-param fix**: URL `{name}` path params were auto-derived only when *no* params were documented, so a route with a query param plus a `{id}` lost its path param from the spec. Path params are now always derived and merged (de-duped by name; explicit docblock wins). `routes/resource.php` migrated to `@queryParam`.
- Notes: **Patch release. Framework-only — no env vars, no migrations, no API breaks.** The api-skeleton `^1.50.1` constraint already permits 1.50.2.

### 1.50.1 — Kochab (Released 2026-06-05)
- **`mergeConfig()` no longer a silent no-op**: `ServiceProvider::mergeConfig()` delegated to an unregistered `config.manager` service, so extension `config/*.php` defaults never reached `config()` (affected all 8 first-party extensions). Now backed by the new `ApplicationContext::mergeConfigDefaults()`, which merges defaults *under* framework/app/env config (app values win) and persists across `clearConfigCache()`.
- **`LoginResponseBuildingEvent` listeners now affect the response**: `LoginResponseShaper::shape()` dispatched the event but discarded listener mutations; it now reads `$event->getResponse()` back, so listeners can add fields to the login response as documented.
- Notes: **Patch release. Framework-only — no env vars, no migrations, no API breaks.** The api-skeleton `^1.50.0` constraint already permits 1.50.1. Behavioral note: enabled first-party extensions now receive their declared config defaults (previously ignored).

### 1.50.0 — Kochab (Released 2026-06-04)
- **Users extraction / provider-agnostic identity**: the concrete user store (`User`, `UserRepository`, in-core `UserProvider`, account/2FA/password-reset, `EmailVerification`) moves to the first-party `glueful/users` extension, behind `UserProviderInterface` + the canonical immutable `UserIdentity` and `IdentityResolver` (status gate + additive claims fold via the `identity.claims_provider` tag). Core binds a fail-closed `NullUserProvider` by default; `AuthenticatedUser` is retired. New `TwoFactorServiceInterface` so 2FA is an optional extension capability. Guide: `docs/IDENTITY.md`.
- **Core owns its schema (security spine + platform capabilities)**: the framework ships first-class, source-tracked, **config-gated** migrations under `framework/migrations/<capability>/` for the tables its own code reads/writes — auth (`auth_sessions`/`auth_refresh_tokens`/`api_keys`, always on) plus `uploads`, `queue`, `scheduler`, `notifications` (incl. the formerly runtime-only `notification_retry_queue`), `metrics`, `locks`, `archive`. Gates live in `config/capabilities.php` + driver config; lazy runtime DDL is removed from `DatabaseQueue`/`JobScheduler`/`NotificationRetryService`/`ApiMetricsService`. Guide: `docs/MIGRATIONS_AND_CAPABILITIES.md`.
- **Ordered, package-scoped migrations**: `MigrationPriority` tiers + a `source` column so packages can ship same-named migrations without conflict; pending order is `(priority, basename, source)`.
- **Declarative permission catalog**: providers declare permissions/roles; `permissions:list`/`diff`/`sync` for visibility, drift, and persistence; `actingWithPermissions()/actingWithRoles()` test helpers.
- **Column-aware soft-delete**: `QueryBuilder::delete()` only soft-deletes when the table has `deleted_at`.
- Notes: **Minor release with breaking changes**, called out per the pre-public policy. Apps must enable a user store — install + enable `glueful/users` (see `docs/IDENTITY.md`). Removed from core: `Glueful\Models\User`, `Glueful\Repository\UserRepository`, `AuthenticatedUser`; `api_keys.user_id` → `user_uuid`. **api-skeleton is intentionally NOT bumped in this release** — its update (require + enable `glueful/users`, drop the relocated migrations) is coordinated separately once the extensions publish.

### 1.49.1 — Jishui (Released 2026-06-01)
- **Reserved-word column names**: `QueryValidator` no longer rejects SQL reserved words used as *column* names (`from`, `order`, `group`, `key`, `values`, …). Column identifiers are always emitted through the driver's `wrapIdentifier()`, so a quoted `from`/`"from"` is valid SQL — the strict-mode keyword check was blocking valid columns and was inconsistent (`to` slipped through only because `TO` was missing from the list). Table/schema/alias keyword checks and the SQL-injection character guard are unchanged.
- Notes: **Patch release. Framework-only — no API breaks, no env vars, no migrations.** The existing api-skeleton `^1.49.0` constraint already permits 1.49.1.

### 1.49.0 — Jishui (Released 2026-06-01)
- **HTTP Basic auth in `Http\Client`**: `Client::transformOptions()` now forwards the `auth_basic` option to Symfony HttpClient (previously dropped), so callers can do `['auth_basic' => [$user, $pass], 'form_params' => [...]]` without hand-building an `Authorization` header.
- **`whatsapp` notification queue type**: added to `SendNotification::SUPPORTED_TYPES` (+ timeout arm) so phone-messaging extensions registering a `whatsapp` channel can be delivered asynchronously.
- **Intervention Image v4**: upgraded `intervention/image` to `^4.1` and ported `ImageProcessor` to the v4 API (`decode`/`createImage`/`insert`/`flip(Direction)`/named `save` options). The public `ImageProcessor`/`image()` API is unchanged.
- **Dependency hardening**: patched all known advisories within the existing `^7.4` / `^10.5` constraints (Symfony 7.4.x — incl. HIGH-severity mailer/mime injection CVEs — and PHPUnit 10.5.63); `composer audit` clean. Pinned `symfony/event-dispatcher` + `symfony/string` to `^7.4` to hold the PHP 8.3 floor.
- Notes: **Minor release. No framework API breaks, no env vars, no migrations.** `composer update` suffices; only apps using `intervention/image` *directly* need to move to its v4 API. api-skeleton bumped to `^1.49.0`.

### 1.48.0 — Imai (Released 2026-05-31)
- **Router Verb Completeness**: `PATCH` and `OPTIONS` become first-class routing verbs. New `$router->patch()` / `$router->options()` shortcuts and `#[Patch]` / `#[Options]` attributes; the `#[Route(methods: [...])]` array form now accepts `PATCH`, `OPTIONS` and `HEAD` (it previously threw for anything but GET/POST/PUT/DELETE). Both verbs were previously unreachable through the public routing API.
- **Explicit `OPTIONS` beats auto-CORS**: `Router::dispatch()` still answers `OPTIONS` automatically (204 + `Allow`) when no `OPTIONS` route is registered, but an explicitly registered `OPTIONS` route now runs its own handler instead of being silently shadowed by the CORS preflight responder.
- **Route precedence documented and pinned**: The three-tier precedence model (static beats dynamic; literal first segment beats a parameter first segment; within a first-segment group, registration order wins) is now documented in the cookbook and locked down by `RoutePrecedenceTest`. A misleading in-code comment in `Router::match()` claiming a specificity sort that never existed was corrected.
- Notes: **Minor release, fully backward compatible.** Framework-only — no migrations, no env vars, no breaking changes. The existing api-skeleton `^1.47.0` constraint already permits 1.48.0; the skeleton constraint is bumped to `^1.48.0` for clarity.

### 1.47.0 — Hadar (Released 2026-05-30)
- **Extension System Re-Architecture**: Extension loading is rebuilt around a single model — **Composer discovers, one `enabled` list activates, a pure resolver orders and validates.** The four overlapping discovery sources, the multi-key config files, and the dev↔prod parity hazard (live-resolve in dev, lazy-cache in prod) are gone. `config/extensions.php` and `config/serviceproviders.php` are now a single `enabled` list of plain string FQCNs.
- **Pure `ExtensionResolver` + shared `ProviderClassResolver`**: A pure resolver selects from `enabled`, validates (missing provider/dependency, framework-version mismatch via `composer/semver`, dependency cycle), and topologically orders providers — reading no environment, so dev and prod resolve identically. `ProviderClassResolver` is the one resolution path used by both `ExtensionManager` and `ContainerFactory` (no drift). `PackageManifest::getCandidates()` reads `installed.json` for the full `extra.glueful` metadata.
- **CLI governs the one list**: `extensions:enable|disable` validate before writing (refuse to leave the config broken), edit the `enabled` list via `ExtensionStateWriter`, and recompile. `extensions:list` shows state (`enabled ✓` / `available ○` / `enabled-but-missing ⚠`) and folds in the old `why`. `extensions:cache` is strict; `create:extension` scaffolds a Composer package + path repository. `ProviderLocator`, the local-folder scan, runtime PSR-4 registration, and `extensions:why` are removed.
- **Production parity**: production boots only from the compiled manifest and fails fast if it's missing — run `php glueful extensions:cache` in the deploy step.
- Notes: **Minor release with a breaking config change**, called out per the pre-public policy. Adds `composer/semver` to `require` (run `composer update`). Migration guide: `docs/EXTENSIONS_UPGRADE.md`. api-skeleton bumped to `^1.47.0`; the 7 first-party extensions and docs updated to the new model.

### 1.46.0 — Gienah (Released 2026-05-28)
- **Fluent Query Caching**: `QueryBuilder::cache(?int $ttl = null, array $tags = [])` is now wired through `QueryExecutor` to `QueryCacheService` for read queries (`get`/`first`/`count`/`max`). Results are tagged automatically by the tables involved plus any caller-supplied `$tags`, enabling targeted invalidation via `$cache->invalidateTags([...])` alongside the automatic per-table invalidation. Caching activates per-query when `->cache()` is called (no global toggle required); the executor lazily resolves a cache backend and degrades to uncached execution if none is configured. Previously the method was a silent no-op — builder flags were set but execution ignored them, and there was no `tags` parameter.
- **Framework-wide PHPStan level-8 hardening (initiative kickoff)**: Internal typing fixes in the query-binding path (`ParameterBinderInterface::flattenBindings()` `@param` widened to `array<int|string, mixed>` matching positional/named binding reality; `preg_match` truthiness made explicit). Behavior-preserving. The full ~914-error level-8 gap across `src/` is catalogued in `docs/LEVEL8_TYPING_DEBT.md` with categories, per-area counts, risk notes, and an incremental area-by-area adoption strategy. The CI gate remains level 6 (`composer analyse`); level 8 is the target.
- Notes: Minor release. Framework-only — no migrations, no env vars, no api-skeleton changes (existing `^1.45` constraint already permits 1.46). Fully backward compatible.

### 1.45.0 — Fomalhaut (Released 2026-05-27)
- **The Second Factor**: Baseline email-PIN two-factor authentication ships in framework core. Opt-in and **off by default** (`TWO_FACTOR_ENABLED=false`) — a fresh install behaves exactly like a pre-2FA framework and the `/2fa/*` routes are not even registered. Richer factors (TOTP, WebAuthn, recovery codes) remain `glueful/mfa` extension scope.
- **Core Email 2FA**: When enabled, `POST /auth/login` for an enrolled user returns a `challenge_token` and emails a 6-digit PIN (bcrypt-hashed via `Glueful\Security\OTP`, cached under `2fa:pin:{jti}` with a strictly-projected user array — no password hash can leak); the client completes login at `POST /2fa/verify`. New `src/Auth/TwoFactor/` services (`TwoFactorService`, `ChallengeTokenIssuer`, `JtiBlocklist`), `TwoFactorController` (`/2fa/enable|verify|disable`, IP-rate-limited), `2fa:enable|disable|status` CLI, and a `config/auth.php` `two_factor` block. `/2fa/verify` re-validates the account (existence, status allowlist, 2FA-still-enabled) before minting a session via `TokenManager::createUserSession`, and writes a **session-scoped** freshness marker so `/2fa/disable` can't be ridden by a stolen token from another session. `AuthenticationService::authenticate()`'s username/password branch was split into `verifyCredentials()` + `issueSession()` to expose the "verified user, no session yet" gate; both login paths shape responses via a new `LoginResponseShaper` so a 2FA-completed login is on-the-wire identical to a direct login. Requires `glueful/email-notification` and the `010_AddTwoFactorEnabledToUsers` migration.
- **`selectRaw()` Parameter Bindings**: `QueryBuilder::selectRaw(string $expression, array $bindings = [])` now binds positional `?` values, closing the last unsafe-by-design gap in the SELECT clause. Bindings stored on `QueryState`, returned by `getBindings()` in true SQL clause order; fixes a latent `clone()` column/binding mismatch. New `docs/SECURITY.md` documents the SQL-injection and XSS model.
- **MFA Middleware Cleanup**: `AdminPermissionMiddleware` dropped the dead `validateMfaToken()`/`X-MFA-Token` placeholder (always returned `false`); `require_mfa` now reads only the session handshake within a named freshness window.
- Notes: Minor release. New opt-in env var `TWO_FACTOR_ENABLED` (default `false`) plus optional tunables. Breaking: the `X-MFA-Token` header path on `require_mfa` routes is removed (it never functioned). See CHANGELOG Upgrade Notes.

### 1.44.0 — Errai (Released 2026-05-22)
- **Closing the Trust Gaps**: A focused follow-up to Dabih that reconciles four places where the README, CLI, or public API advertised behavior the code didn't deliver. The 1.43.0 release raised credibility surface, which made these gaps more damaging — this release closes them.
- **Real Tag-Aware Cache Invalidation (Redis)**: `RedisCacheDriver::addTags()`/`invalidateTags()` now backed by Redis SETs (`_gf_tag:{tag}` → cache keys). Pipelined `SADD` on association, bulk `DEL` (keys + tag sets) on invalidation. Capability flag flipped to `true`. Unblocks `QueryCacheService`, `DistributedCacheService`, `ResponseCachingTrait`, and `cache:clear --tags` — all previously failing silently. Memcached and File drivers documented as deliberate no-op fallbacks (Memcached lacks set primitives; File would need a separate index). README cache claim narrowed to "tag-based invalidation on the Redis driver."
- **Real `ArchiveService::restoreFromArchive()`**: Replays archived rows into a target table inside a database transaction. Honors `ArchiveRestoreOptions`: `targetTable`, `offset`/`limit`, and `conflictResolution` (`skip` records conflicts; `overwrite` hard-deletes to bypass soft-delete then reinserts). PK detection: `uuid` then `id`. The existing `loadArchive()` already handled checksum verify + decrypt + decompress; only the row replay was missing. Also fixed a latent SQLite bug in `validateTable()` (PRAGMA returns empty for missing tables instead of throwing).
- **`security:report` Stripped to Honest Sections**: Removed `analyzeAuthenticationSecurity()`, `getAuditSummary()`, `runVulnerabilityAssessment()`, `gatherSecurityMetrics()` (all `rand()`), the `sendReportByEmail()` stub, and `assessCompliance()` (hardcoded strings). Dropped `--include-vulnerabilities`, `--include-metrics`, `--email`, `--days` options and PDF format. The command now exports HTML/JSON/text reports of the production readiness score, environment config, system info, and recommendations only.
- **`fields:whitelist-check` Inspects Real Routes**: Replaced the hardcoded three-entry placeholder route list with real introspection via `Router::getStaticRoutes()`/`getDynamicRoutes()` and `Route::getFieldsConfig()`. Added a new low-severity `NON_STRICT_WHITELIST` finding for `/api/` routes with non-strict whitelists. Removed the fabricated `pattern_frequency` block (`65/25/10`).
- Notes: Minor release. No env var changes. Breaking changes to `security:report` output shape and removed CLI options — see CHANGELOG Upgrade Notes.

### 1.43.0 — Dabih (Released 2026-05-21)
- **ORM-Aware N+1 Detection**: New `PreventsLazyLoading` trait on `Model` flags lazy-loaded relations on members of a hydrated collection with the model class and relation name in the warning. Four modes (`off`/`warn`/`strict`/`auto`) configured via `DB_LAZY_LOADING_MODE`. Strict mode throws `LazyLoadingViolationException` for CI enforcement; warn mode logs once per `(model, relation)` pair per request. Per-model opt-out via `$instanceLazyLoadingMode = 'off'`. Custom violation handler hook for routing to Sentry / PSR logger.
- **Driver-Aware `$query->explain()`**: `QueryBuilder::explain()` uses `EXPLAIN QUERY PLAN` on SQLite (the useful form) and plain `EXPLAIN` on MySQL/PostgreSQL. New `Builder::explain()` on the ORM applies global scopes and delegates. `QueryExecutorInterface::getDriverName()` exposes the underlying PDO driver name for other callers that need similar branching.
- **Kubernetes Health Probes**: Three new routes at the canonical paths — `GET /health/live`, `GET /health/ready`, `GET /health/startup`. Liveness is dependency-free; readiness checks database, cache, and config; startup reports initialization complete. Existing `/healthz` and `/ready` continue to work.
- **API Key Hardening**: Dedicated `api_keys` table with per-key scopes, CIDR/IP allowlists, expiration, rotation with grace period, and environment-prefixed plaintext (`gf_live_*` / `gf_test_*`). Keys SHA-256 hashed; first 16 chars indexed as `key_prefix` for O(1) lookup; `UNIQUE` constraint on `key_hash`; collision-tolerant verify. New `#[RequireScope]` route attribute (`IS_REPEATABLE`) processed by `AttributeRouteLoader` — auto-attaches `require_scope` middleware. Four CLI commands: `apikey:create|list|rotate|revoke`. Migration ships in `api-skeleton ^1.26.0` as `009_CreateApiKeysTable.php`.
- **Router Exposes Matched Route on Request**: `Router::dispatch()` sets `_route` and `_route_params` on `$request->attributes` before middleware runs, so route-level metadata (e.g. `RequireScope` config, `RateLimit` config) is readable from middleware without re-resolving.
- **ORM Bug Fixes**: `HasAttributes::getAttribute()` and `__isset()` are now relation-aware (property access routes to relations; `??` no longer swallows lazy-load results). `HasRelationships::newRelatedInstance()` propagates the parent's `ApplicationContext`. `Builder::getRelation()` wraps relation construction in `Relation::noConstraints()` so eager loading no longer emits `WHERE x = NULL`.
- Notes: Minor release. `ApiKeyAuthenticationProvider` is now single-track — the legacy `users.api_key` fallback (which queried a column that doesn't exist in the canonical schema) is removed. `UserRepository::findByApiKey()` is also removed (zero callers verified across the framework, all extensions, api-skeleton, and other org repos). New env var `DB_LAZY_LOADING_MODE` defaults to `auto`.

### 1.42.0 — Caph (Released 2026-05-20)
- **OpenAPI Spec Excellence**: Generated `openapi.json` now declares all configured security schemes (BearerAuth, ApiKeyAuth, …) via `SecuritySchemeRegistry` driven by `documentation.security_schemes` / `middleware_map` config — per-operation `security` is derived from route middleware instead of being hardcoded.
- **Unified `ErrorResponse` Schema**: New OpenAPI component for the `{success, message, error: {code, error_code, timestamp, request_id}}` envelope with an `error_code` enum (`NOT_FOUND`, `FORBIDDEN`, …). All CRUD 4xx responses `$ref` it so generated SDKs typecheck error responses.
- **Deterministic Operation IDs**: New `OperationIdGenerator` produces camelCase SDK method names and closes a gap where comment-driven generation emitted operations without any `operationId`.
- **Pagination + Field-Selection Components**: `PaginationMeta`, `PaginationLinks`, and per-resource envelope schemas match `PaginatedResourceResponse`. New `addRouteWithFieldsAttribute()` helper surfaces `?fields=` / `?expand=` from `#[Fields]` attributes.
- **Auto-Derived Examples**: `ExampleDeriver` populates JSON request bodies with realistic examples inferred from Validator rules and schema properties; `@example` annotations override the derived value.
- **OpenAPI 3.1 Webhooks**: `WebhookDocsBuilder` emits a top-level `webhooks` object from `documentation.webhooks` config — including the `X-Glueful-Signature` and `X-Glueful-Timestamp` headers actually sent by `WebhookDeliveryService`. New `docs/WEBHOOKS.md`.
- **`generate:client` CLI Wrapper**: Thin command that shells out to `openapi-typescript` (TS targets) or `openapi-generator-cli` (everything else). Glueful does not own codegen logic.
- Notes: Minor release with one breaking change — `PermissionUnauthorizedException` envelope unified with the standard `{success, message, error: {…}}` shape. Consumers reading top-level `code`/`error_code` must read `error.code`/`error.error_code` instead.

### 1.41.0 — Beid (Released 2026-03-03)
- **Profile-Driven Logging Bootstrap**: `config/logging.php` now resolves deterministic defaults from `LOG_PROFILE` (or `APP_ENV`) with built-in `development`, `staging`, `production`, and `testing` profiles. Explicit env vars override profile defaults.
- **Production Safety Checks**: `system:check --production` flags no durable log sink, disabled event/audit toggles, debug-level logging, and invalid retention values.
- **Upsert Column Fix**: `InsertBuilder::buildUpsertQuery()` now passes column names instead of full data arrays to driver `upsert()` SQL builders, fixing PostgreSQL `wrapIdentifier()` crash on null values.
- **Audit Toggle Alignment**: Framework boot and `LogManager` now respect `EVENTS_ENABLED`, `EVENTS_AUDIT_LOGGING`, and `LOG_TO_FILE=false` correctly.
- Notes: Minor release. `LOG_TO_DB` default changed from implicit `true` to `false` in all profiles — add `LOG_TO_DB=true` to `.env` if DB logging is required.

### 1.40.4 — Alnair (Patch, Released 2026-02-21)
- **PHPCS Line Length Fix**: Extracted long error message in `WhereClause::getConditionsArray()` to comply with 120-character line limit. Code style only — no runtime changes.

### 1.40.3 — Alnair (Patch, Released 2026-02-21)
- **Mutation WHERE Operator Support**: `WhereClause`, `UpdateBuilder`, and `DeleteBuilder` now handle comparison and null operators (`<`, `<=`, `>`, `>=`, `!=`, `LIKE`, `IN`, `IS NULL`, `IS NOT NULL`) instead of crashing on non-equality conditions.
- **Queue Config String Coercion**: `DriverRegistry` validates numeric-string ints/ports and boolean-like strings from `.env`, fixing Redis config rejection in production.
- **Async Notification Best-Effort**: `NotificationService::queueAsyncDispatch()` wrapped in try/catch so side-effect failures don't crash primary API operations.

### 1.40.2 — Alnair (Patch, Released 2026-02-21)
- **Config Merge Safe Dedup for Nested Lists**: Replaced `array_unique()` with hash-based dedup (`json_encode`/`serialize` for complex items, `var_export` for scalars) so list merges containing nested arrays/objects no longer trigger "Array to string conversion" warnings. Fixes 500 errors on event-driven flows that load merged config with nested list items.

### 1.40.1 — Alnair (Patch, Released 2026-02-21)
- **Config Merge Fix**: `mergeConfig()` in `helpers.php` no longer applies `array_unique()` to nested associative arrays. True lists are list-merged with dedup; associative arrays are deep-merged recursively. Fixes "Array to string conversion" warnings on config keys like `session.providers`.

### 1.40.0 — Alnair (Released 2026-02-21)
- **Notification Split Delivery**: `NotificationService::sendSplit()` provides first-class sync/async channel separation. `send()` now supports `sync_channels`, `async_channels`, `channel_failure_policy` (`any_success`/`require_critical`/`all`), and `critical_channels`.
- **Notification Idempotency**: Dedicated `notifications.idempotency_key` column with indexed DB lookup replaces the previous `_meta` JSON scan. Channel-level idempotency via `notification_deliveries` unique key on `(notification_uuid, channel)`.
- **Per-Channel Delivery Tracking**: New `notification_deliveries` table and repository APIs (`ensureDeliveryRecords`, `recordDeliveryAttempt`, `getChannelsNeedingDispatch`, `getFailedDeliveryChannels`) track delivery lifecycle per channel. Async retries target only failed channels.
- **Async Dispatch Job**: `DispatchNotificationChannels` dispatches persisted notifications by UUID, fails only when unresolved failed channels remain.
- **`ProvisioningException`**: New domain exception for account setup failures, mapped to HTTP 500 and `api` log channel in `Handler`.
- Notes: Non-backward-compatible changes to notification sending flow and response structure.

### 1.39.0 — Menkent (Released 2026-02-20)
- **Token/Session Reimplementation**: Full replacement of the token/session model with hash-only refresh tokens, one-time-use rotation in a single DB transaction, and session versioning for instant access-token invalidation.
- **New Service Architecture**: `RefreshService`, `AccessTokenIssuer`, `ProviderTokenIssuer`, `SessionRepository`, `RefreshTokenRepository`, `RefreshTokenStore`, `SessionStateCache` decompose the monolithic auth flow into single-responsibility units.
- **`AuthenticatedUser` Value Object**: Minimal runtime identity object used across controllers, traits, and middleware for auth/permission checks.
- **Replay Detection**: Consumed/revoked refresh token presentation triggers session-scope revocation of all active tokens for that session.
- **Session-First Identity Hydration**: `RequestUserContext` simplified to extract `sessionUuid` from JWT `sid` claim instead of relying on fat JWT payload assumptions.
- **Cleanup Task Expansion**: `SessionCleanupTask` now cleans both `auth_sessions` and `auth_refresh_tokens` with configurable retention windows.
- Notes: Breaking change — existing sessions/tokens become invalid at cutover; users must re-authenticate.

### 1.38.0 — Lesath (Released 2026-02-17)
- **Token-Refresh DB Lookup Reduction**: `TokenManager::getSessionFromRefreshToken()` now fetches `provider` and `remember_me` in the initial query, eliminating two subsequent `auth_sessions` lookups that re-fetched these fields during token refresh.
- **AuthenticationService DI Cleanup**: `refreshTokens()` resolves the session via `SessionStore::getByRefreshToken()` up front instead of querying `auth_sessions` independently. Removed direct `new Connection()` instantiation in favour of the injected `UserRepository`.
- **Request-Level Refresh-Token Cache**: `SessionStore::getByRefreshToken()` now caches results in `$requestCache` (keyed by `refresh:{hash}`), matching the existing `getByAccessToken()` pattern.
- Notes: No breaking changes. All modified methods are private/internal to the auth subsystem.

### 1.37.0 — Kaus (Released 2026-02-15)
- **Deferred Extension Commands**: `ServiceProvider::commands()` and `discoverCommands()` no longer silently drop commands when the console application isn't yet created. Commands are stored in a static `$deferredCommands` array and picked up by `ConsoleApplication` on construction via `flushDeferredCommands()`.
- **ORM Builder `forPage()` Fix**: Changed `offset()->limit()` to `limit()->offset()` so `QueryValidator` doesn't throw "OFFSET requires LIMIT" during pagination.
- **`ExtendsBuilder` Interface**: New contract (`Contracts\ExtendsBuilder`) for scopes that add macros to the ORM Builder, replacing duck-typed `method_exists()` checks. `SoftDeletingScope` implements it.
- **WebhookDispatcher DI Fix**: `CoreProvider` factory now passes `ApplicationContext` as the 3rd argument.
- **OpenAPI Documentation Hardening**: `DocGenerator` receives context from `OpenApiGenerator` for CLI usage. Server URL fallback chain: `api_url()` → config → discovered URL → `"/"`. Empty properties now render as `{}` instead of `[]` in generated JSON.
- Notes: No breaking changes. Extension CLI commands that were previously lost now register correctly.

### 1.36.0 — Jabbah (Released 2026-02-14)
- **Model Event Isolation**: `HasEvents::$modelEventCallbacks` now keyed by `[className][event]` instead of flat `[$event]`. Prevents event callbacks registered in one model (e.g., `EntityType::creating`) from firing on unrelated models (e.g., `Entity`).
- **Boot-safe Event Registration**: `registerModelEvent()` no longer calls `new static()` to validate event names. Eliminates "No database connection" errors when models boot without `ApplicationContext`.
- **Base64 Upload File Extensions**: Base64 uploads now produce correct file extensions (`.png`, `.jpg`, etc.) derived from the MIME type instead of always defaulting to `.bin`.
- Notes: No breaking changes. Fixes cross-model event leaking and boot-time DB errors.

### 1.35.0 — Izar (Released 2026-02-14)
- **Cloud Storage Direct Write**: `FlysystemStorage::store()` bypasses the atomic temp+move pattern for S3/R2/GCS/Azure disks, writing directly via `writeStream()`. Fixes `io_move_failed` CopyObject failures on Cloudflare R2 and compatible stores. Local disks retain the atomic pattern for crash safety.
- **Blob Lookup Fix**: `BlobRepository::findByUuidWithDeleteFilter()` rewritten to use explicit three-parameter `where('status', '!=', 'deleted')` instead of array-format `['!=', 'deleted']` which the query builder treated as `=`.
- **Storage Error Propagation**: `FlysystemStorage` now always includes the underlying exception message in upload errors, replacing the generic "Storage write failed" for non-Flysystem exceptions.
- **Base64 Upload File Extensions**: Base64 uploads now produce correct file extensions (`.png`, `.jpg`, etc.) derived from the MIME type instead of always defaulting to `.bin`.
- Notes: No breaking changes. Fixes blob 404s, S3-compatible upload failures, and base64 file naming.

### 1.34.0 — Hamal (Released 2026-02-14)
- **Auth Middleware Exception Isolation**: `$next($request)` moved outside the auth `try/catch` so downstream controller/middleware exceptions propagate correctly instead of being swallowed as 401 "Authentication error occurred".
- **`Utils::getUser()` Modernization**: No longer requires legacy `role`/`info` JWT claims (only `uuid` required). Checks request attributes from auth middleware before falling back to token decoding.
- **Symfony Request Token Fallback**: `AuthMiddleware` and `JwtAuthenticationProvider` fall back to extracting Bearer token from the Symfony Request when `RequestContext`-based extraction returns null.
- **`UploadController` + `FileUploader` DI Registration**: Both registered in `StorageProvider` with config-driven factory definitions, fixing container resolution failures on blob upload routes.
- **Queue Serialization Fix**: `DriverRegistry` cache key generation replaced `serialize()` with `json_encode()` to avoid Closure serialization crashes.
- **Login Tracking Cleanup**: Removed legacy column updates (`ip_address`, `user_agent`, `x_forwarded_for_ip_address`, `last_login_date`) from `AuthenticationService` — already tracked in `auth_sessions`.
- Notes: No breaking changes. All fixes backward-compatible.

### 1.33.0 — Gacrux (Released 2026-02-14)
- **Container-Enforced Request Resolution**: Eliminated all `fromGlobals()` / `createFromGlobals()` fallbacks from 15 service files. Auth services (`TokenManager`, `JwtAuthenticationProvider`, `SessionStore`, `EmailVerification`, `AuthenticationService`) and utility services (`RequestHelper`, `Utils`, `Cors`, `SpaManager`, `UserRepository`, `SecurityManager`) now resolve `RequestContext`/`Request` from the DI container's shared singleton.
- **Memory Safety**: Fixes unbounded memory growth on high-header requests where multiple independent `fromGlobals()` calls each reconstructed PSR-7 request objects from `$_SERVER` superglobals.
- **Long-Running Server Compatibility**: Services no longer read stale `$_SERVER` globals — all request data comes from the container-managed singleton that is reset between requests.
- **Silent Fallback Removal**: `SessionStoreResolver` and `TokenManager::getSessionStore()` no longer silently construct bare `SessionStore()` instances on container failure — errors surface immediately with clear messages.
- **`SessionStoreInterface::resetRequestCache()`**: Added to interface; removes `method_exists()` guard in `TokenManager`.
- Notes: No breaking changes for container-wired services. Direct instantiation without context now throws `\RuntimeException` instead of silently degrading.

### 1.32.0 — Fomalhaut (Released 2026-02-11)
- **Schema Builder `alterTable` Callback API**: `alterTable()` now accepts an optional callback parameter, mirroring the `createTable` dual-mode pattern. Without a callback, returns a fluent `TableBuilder` (unchanged). With a callback, applies alterations and auto-executes, returning `$this` for chaining.
- **ColumnBuilder Finalization Fix**: `gc_collect_cycles()` called before execute to ensure `ColumnBuilder` destructors register columns via `finalizeColumn()` before ALTER SQL is generated.
- Notes: No breaking changes. Existing callers (including convenience methods) use the no-callback path and are unaffected.

### 1.31.0 — Enif (Released 2026-02-09)
- **Centralized Context Propagation**: Framework boot now sets `ApplicationContext` on core services (`Model`, `Utils`, `CacheHelper`, `SecureErrorResponse`, `RoutesManager`, `ImageProcessor`, `ConfigManager`, `Webhook`) and auth services (`RequestUserContext`), eliminating scattered manual `setContext()` calls.
- **ORM Default Context**: `Model::setDefaultContext()` enables static model calls (`User::find($id)`) without explicitly passing context after boot. `__callStatic` falls back to the default context when no explicit context is provided.
- Notes: No breaking changes. Explicit context passing continues to work. Default context only available after `Framework::boot()`.

### 1.30.1 — Diphda (Patch, Released 2026-02-09)
- **JWTService Context Fix**: `AuthBootstrap::initialize()` now sets `JWTService::setContext()` before creating authentication providers, ensuring JWT operations have access to the application context.
- Notes: Patch release. No breaking changes.

### 1.30.0 — Diphda (Released 2026-02-09)
- **Exception Handler Consolidation**: Unified two overlapping exception handlers into a single source of truth. Modern `Handler` absorbs channel-based log routing, context optimization, framework/app classification, and test mode. Legacy `ExceptionHandler` reduced from 1041 to ~250 lines — a thin bootstrap shim that registers PHP global handlers and delegates to the DI-managed `Handler`.
- **Boot Wiring Reorder**: Global error handlers registered at the top of `Framework::boot()` (before Phase 1). Handler wired into shim after container build via `ExceptionHandler::setHandler()`.
- **Extension Import Migration**: payvia, email-notification, entrada, aegis extensions migrated from deleted `Glueful\Exceptions\*` bridge classes to modern `Glueful\Http\Exceptions\*` namespaces.
- Notes: No breaking changes. Static API (`logError`, `setTestMode`, `getTestResponse`) preserved as delegation wrappers. `setContext()` is now a no-op.

### 1.29.0 — Capella (Released 2026-02-07)
- **Leaf Worker Mode**: `queue:work process` action for direct in-process job execution. Spawned workers no longer recursively invoke the manager.
- **ProcessManager Wiring**: Config key normalization (`max_workers` / `max_workers_global`), new `stop()` API, manager-routed stop path in WorkCommand.
- **AutoScaleCommand Config**: Normalized config propagation to ProcessManager and AutoScaler with env-driven defaults.
- **Status Runtime Fix**: `getStatus()` now includes worker runtime for display formatting.
- **Distributed Lock Fix**: Lock key changed from host-scoped to shared queue-scoped for correct multi-host coordination.
- **Queue Presets**: Seven env-driven queue configurations (`critical`, `maintenance`, `default`, `high`, `emails`, `reports`, `notifications`) with per-queue autoscale toggle.
- **Schedule Queue Env Vars**: Hardcoded queue names in `schedule.php` replaced with `SCHEDULE_QUEUE_*` env vars.
- Notes: No breaking changes. Auto-scaling default-off for all presets.

### 1.28.3 — Bellatrix (Patch, Released 2026-02-07)
- **CLI `-q` Shortcut Fix**: Removed `-q` shortcut from `--queue` option in `WorkCommand`, `ServerCommand`, and `MaintenanceCommand` to avoid collision with Symfony Console's reserved `--quiet` (`-q`).
- Notes: Fixes `LogicException: An option with shortcut "q" already exists` when running queue commands. No breaking changes.

### 1.28.2 — Bellatrix (Patch, Released 2026-02-07)
- **Container Self-Registration**: `ContainerFactory::create()` registers the container under `ContainerInterface` for autowiring into CLI commands.
- **Migration Command DI**: `RunCommand`, `StatusCommand`, `RollbackCommand` accept container/context via constructor, receiving the booted container with extension migration paths.
- **PostgreSQL Schema-Safe Introspection**: All `PostgreSQLSqlGenerator` introspection queries now use `current_schema()` instead of hardcoding `public`. Covers `information_schema`, `pg_constraint`, `pg_index`, and foreign key lookups with proper `pg_namespace` joins.
- Notes: Fixes `migrate:run/status/rollback` not discovering extension migrations. PostgreSQL introspection now works correctly with non-`public` schemas. No breaking changes.

### 1.28.1 — Bellatrix (Patch, Released 2026-02-06)
- **Router Group Stack Fix**: `Router::group()` now uses `try/finally` to always clean up `groupStack` and `middlewareStack`, preventing prefix leakage across extensions when exceptions occur inside group callbacks.
- **Cache-Aware Route Registration**: Router allows extensions to overwrite routes pre-loaded from cache instead of throwing duplicate route errors. Handles both static and dynamic routes.
- Notes: Stability patch. No breaking changes. Fixes cascading route failures when extensions are used with route caching.

### 1.28.0 — Bellatrix (Released 2026-02-05)
- **Route Caching Support**: Refactored controllers to use cacheable route syntax.
  - ResourceController methods renamed to RESTful conventions (index, show, store, update, destroy).
  - Removed wrapper methods pattern for cleaner, more maintainable code.
  - All routes now use `[Controller::class, 'method']` syntax instead of closures.
  - Methods accept `Request` directly and extract parameters from attributes.
- **Route Caching Infrastructure**: Added validation tools for cache compatibility.
  - RouteCompiler: `validateHandlers()`, `hasClosures()`, `getClosureRoutes()` for detecting non-cacheable routes.
  - RouteCache: `cacheContainsClosures()` auto-invalidates cache and logs warnings when closures detected.
  - Helps developers identify routes needing conversion to controller syntax.
- **Breaking Changes**: Method signatures changed for ResourceController extensions.
- Notes: Enables route caching for improved performance. Use `route:debug` to find closure-based routes.

### 1.27.0 — Avior (Released 2026-02-04)
- **New CLI Commands**: `doctor`, `env:sync`, `route:debug`, `route:cache:clear`, `route:cache:status`, `cache:inspect`, `test:watch`, `dev:server`.
- **Database Transaction Callbacks**: `afterCommit()` and `afterRollback()` on Connection class.
- **Route Cache Improvements**: Signature-based invalidation with SHA-256 hash.
- **Extensions Enable/Disable**: Commands now edit config directly with `--dry-run` and `--backup` options.
- Notes: No breaking changes. DX improvements for development workflow.

### 1.26.0 — Atria (Released 2026-01-31)
- **Extension Discovery Fixes**: Improved reliability of Composer-based extension discovery.
  - `PackageManifest` now falls back to `installed.json` when `installed.php` lacks provider metadata.
  - Composer's `installed.php` may omit the `extra` field; `installed.json` has complete package data.
- **ExtensionManager Lazy Discovery**: CLI commands that create their own container now auto-discover extensions.
  - `getProviders()` triggers discovery if not yet run, fixing empty provider lists in CLI tools.
- **Discovery Efficiency**: Added `$discovered` flag to ensure discovery runs exactly once.
  - Prevents redundant discovery when zero extensions are legitimately installed.
- Notes: No breaking changes. Fixes edge cases in extension discovery for CLI and documentation generation.

### 1.25.0 — Ankaa (Released 2026-01-31)
- **Multi-File Route Discovery**: RouteManifest enhanced with automatic app route file discovery.
  - All `*.php` files in the application's `routes/` directory are auto-discovered.
  - Alphabetical loading order for deterministic behavior.
  - Exclusion patterns: files starting with underscore (`_helpers.php`) are excluded as partials.
  - Double-load prevention tracks loaded files to avoid duplicate route registration.
- **Route Loading Priority**: Application routes load first (highest priority), framework routes act as fallback.
- **Domain-Driven Organization**: Enables splitting large route files into domain-specific files (e.g., `identity.php`, `social.php`, `engagement.php`).
- Notes: No breaking changes. Backward compatible with single `routes/api.php` files.

### 1.24.0 — Alpheratz (Released 2026-01-31)
- **Encryption Service**: Comprehensive AES-256-GCM encryption for strings, files, and database fields.
  - `EncryptionService` with authenticated encryption (random nonce, 16-byte auth tag).
  - Self-identifying output format: `$glueful$v1$<key_id>$<nonce>$<ciphertext>$<tag>`.
  - Key ID in output enables O(1) key lookup during rotation (no trial decryption).
  - `encrypt()`, `decrypt()`, `encryptBinary()`, `decryptBinary()`, `isEncrypted()` methods.
  - `encryptFile()`, `decryptFile()` for file encryption.
- **AAD (Additional Authenticated Data)**: Context binding prevents cross-field attacks.
  - Encrypting with `aad: 'user.ssn'` requires same AAD for decryption.
  - Prevents copying encrypted data between different fields.
- **Key Rotation Support**: Seamless migration to new encryption keys.
  - `encryption.previous_keys` config array for old keys.
  - O(1) key lookup via key ID embedded in ciphertext.
  - Old data decrypts with previous keys; new data uses current key.
- **Exception Classes**: `InvalidKeyException`, `KeyNotFoundException` for clear error handling.
- **Base64 Key Support**: Keys can use `base64:` prefix for safe env file storage.
- **CLI Commands**: `encryption:test`, `encryption:file`, `encryption:rotate`.
  - `encryption:test` verifies encryption is working correctly.
  - `encryption:file encrypt/decrypt /path/to/file` for file operations.
  - `encryption:rotate --table=users --columns=ssn` for key rotation.
- **Test Coverage**: 32 tests covering core encryption, AAD, key validation, binary handling, rotation, files.
- Notes: No breaking changes. Encryption is opt-in and additive. Uses `APP_KEY` by default.

### 1.23.0 — Aldebaran (Released 2026-01-31)
- **Blob Visibility Support**: Per-blob `public`/`private` visibility controls.
  - Upload requests accept `visibility` parameter (`public` or `private`).
  - Defaults to configured `uploads.default_visibility` (private by default).
  - Public blobs accessible without auth (unless global access is `private`).
  - Private blobs require authentication or valid signed URL.
  - Database schema updated with `visibility` column and index.
- **Signed URL Support**: HMAC-based temporary access URLs for private blobs.
  - New `SignedUrl` helper class for URL signing and validation.
  - Time-limited access with customizable TTL (default 1 hour, max 7 days).
  - New endpoint `POST /blobs/{uuid}/signed-url` for generating signed URLs.
  - Automatic signature validation on blob retrieval.
  - Falls back to `APP_KEY` if no dedicated secret configured.
- **Test Coverage**: Comprehensive unit tests for blob and signed URL functionality.
  - `SignedUrlTest` with 17 tests covering generation, validation, expiration, tampering.
  - `UploadControllerTest` with 38 tests covering resize, caching, access control, visibility.
- **Configuration**: New uploads config options for visibility and signed URLs.
- Notes: No breaking changes. Enhances blob storage system with secure temporary access.

### 1.22.0 — Achernar (Released 2026-01-30)
- **Console Command Auto-Discovery**: Zero-config command registration via `#[AsCommand]` attribute scanning.
  - Commands auto-discovered from `src/Console/Commands/` directory.
  - Production caching for fast startup (auto-generated on first run).
  - New `commands:cache` CLI command for cache management.
- **Global State Removal**: Major refactoring to replace `$GLOBALS` with explicit `ApplicationContext` dependency injection.
  - All helper functions (`config()`, `app()`, `base_path()`, etc.) now require `ApplicationContext` as first parameter.
  - New `QueueContextHolder` class replaces deprecated static trait properties (PHP 8.3 compatibility).
  - PHPStan `banned_code` rule added to prevent future `$GLOBALS` usage.
- **Service Provider Updates**: `register()` and `boot()` methods now receive `ApplicationContext` parameter.
  - `ServiceProvider` interface updated with context parameter.
  - `BaseExtension::boot()` receives context for proper DI access.
- **Authentication Refactoring**: Auth services updated for explicit context dependency.
  - `AuthenticationService`, `SessionStore`, `TokenManager` refactored for context injection.
  - `ResolvesSessionStore` trait simplified with default `getContext()` method.
- **Code Quality**: Fixed PHPStan errors, PHPCS line length violations (25+ files), PHP 8.3 compatibility.
- **Test Suite**: Updated tests to properly initialize `ApplicationContext` for Router/RouteCache.
- Notes: **Breaking change** for extensions using old helper signatures. See migration guide in CHANGELOG.

### 1.21.0 — Mira (Released 2026-01-24)
- **File Uploader Refactoring**: Complete architecture overhaul of file upload system.
  - New `ThumbnailGenerator` class for dedicated thumbnail creation with ImageProcessor (Intervention Image).
  - New `MediaMetadataExtractor` class using getID3 for pure PHP metadata extraction.
  - New `MediaMetadata` readonly value object for type-safe metadata representation.
  - New `uploadMedia()` method on FileUploader for media files with automatic thumbnails.
- **Pure PHP Media Processing**: Removed ffprobe shell_exec dependency.
  - All metadata extraction now uses getID3 library (pure PHP, no external binaries).
  - Supports images, audio, and video file metadata extraction.
- **Enhanced Configuration**: New uploader configuration section in `config/filesystem.php`.
  - Global thumbnail enable/disable toggle (`THUMBNAIL_ENABLED`).
  - Configurable thumbnail dimensions, quality, and subdirectory.
  - Configurable supported formats for thumbnail generation.
- **Documentation**: Added storage adapter installation instructions for S3, GCS, Azure, SFTP, FTP.
- Notes: Minor release. No breaking changes. New `james-heinrich/getid3` dependency added.

### 1.20.0 — Regulus (Released 2026-01-24)
- **Framework Simplification**: Removed unused subsystems for a leaner, more maintainable codebase.
- **Resource Routes**: Added `/data` prefix to generic CRUD routes (`/api/v1/data/{table}`) to avoid conflicts with custom application routes.
- **Async System Removed**: Entire Fiber-based async subsystem removed (~30 files) as it was unused in practice.
- **Rate Limiting Consolidated**: Basic rate limiting system removed; enhanced system (`EnhancedRateLimiterMiddleware`) retained with all advanced features.
- **Configuration Cleanup**: Removed unused environment variables (`ENABLE_AUDIT`) and duplicate pagination settings.
- Notes: Minor release. Resource route URLs changed from `/api/v1/{table}` to `/api/v1/data/{table}`.

### 1.19.2 — Canopus (Released 2026-01-24)
- **ValidationException Consolidation**: Unified validation exceptions from 3 classes to 1.
  - Removed `Glueful\Exceptions\ValidationException` (legacy).
  - Removed `Glueful\Uploader\ValidationException` (empty class).
  - All code now uses `Glueful\Validation\ValidationException` with static factory methods.
- **Database Query Building**: Improved SQL parsing and cross-database compatibility.
  - `PaginationBuilder` regex improvements for ORDER BY, LIMIT, and complex query detection (UNION, CTEs, window functions).
  - `QueryValidator` now supports `schema.table` format in table name validation.
  - `WhereClause` expanded operators (IS, IS NOT, BETWEEN, REGEXP, ILIKE) with injection protection.
- **Bug Fixes**: PHPStan and Intelephense warnings in Handler.php, variable shadowing in ValidationHelper.
- Notes: Patch release. No breaking changes for most users. See CHANGELOG for migration if using legacy ValidationException directly.

### 1.19.1 — Canopus (Released 2026-01-22)
- **Simplified Configuration**: Consolidate URL and version environment variables.
  - Single `BASE_URL` for all URL derivation (removed `API_BASE_URL`).
  - Single `API_VERSION` as integer (removed `API_VERSION_FULL`, `API_DEFAULT_VERSION`).
  - Cleaner deployment configuration with fewer variables to manage.
- Notes: Patch release. Update `.env` files to use simplified format.

### 1.19.0 — Canopus (Released 2026-01-22)
- **Search & Filtering DSL**: Comprehensive URL query parameter syntax for filtering, sorting, and search.
  - Filter syntax: `filter[field][operator]=value` (14 operators with aliases).
  - Sorting: `sort=-created_at,name` (multi-column with direction).
  - Full-text search: `search=query&search_fields=title,body`.
  - `QueryFilter` base class with field whitelisting and custom filter methods.
  - PHP 8 attributes: `#[Filterable]`, `#[Searchable]`, `#[Sortable]`.
  - `FilterMiddleware` for automatic query parameter parsing.
  - `OperatorRegistry` for extensible operator management.
- **Search Engine Adapters**: Pluggable search backends.
  - `DatabaseAdapter` - SQL LIKE queries (default, no setup required).
  - `ElasticsearchAdapter` - Full Elasticsearch integration (optional).
  - `MeilisearchAdapter` - Full Meilisearch integration (optional).
  - `SearchResult` value object with pagination.
  - Auto-migration for `search_index_log` table.
- **Searchable Trait**: ORM model integration for search engines.
  - `makeSearchable()`, `removeFromSearch()`, `Model::search()`.
  - Auto-indexing on model save (configurable).
- **CLI**: `scaffold:filter` command for generating QueryFilter classes.
- Notes: No breaking changes. Search adapters are optional (database works out of the box). Completes Priority 3 Phase 3 (Search & Filtering DSL).

### 1.18.0 — Hadar (Released 2026-01-22)
- **Webhooks System**: Comprehensive webhook infrastructure with event-driven integrations.
  - Event-based subscriptions with wildcard matching (`user.*`, `*`).
  - HMAC-SHA256 signatures in Stripe-style format (`t=timestamp,v1=signature`).
  - Reliable delivery via queue with exponential backoff retry (1m, 5m, 30m, 2h, 12h).
  - Auto-migration for database tables (follows `DatabaseLogHandler` pattern).
  - `WebhookSubscription` and `WebhookDelivery` ORM models.
  - `DispatchesWebhooks` trait and `#[Webhookable]` attribute for event integration.
  - REST API for subscription management (`WebhookController`).
  - CLI commands: `webhook:list`, `webhook:test`, `webhook:retry`.
  - Static `Webhook` facade for easy dispatching.
- Notes: No breaking changes. Auto-migration creates tables on first use. Completes Priority 3 Phase 3 (first feature).

### 1.17.0 — Alnitak (Released 2026-01-22)
- **Rate Limiting Enhancements**: Comprehensive rate limiting system with per-route limits, tiered access, and multiple algorithms.
  - New `#[RateLimit]` attribute for per-route rate limiting (IS_REPEATABLE for multi-window).
  - New `#[RateLimitCost]` attribute for operation cost multipliers.
  - Tiered rate limiting with configurable tiers (anonymous, free, pro, enterprise).
  - Multiple algorithms: Fixed Window, Sliding Window, Token Bucket.
  - IETF-compliant rate limit headers (`RateLimit-*`, `X-RateLimit-*`).
  - Cost-based limiting for expensive operations.
  - `RateLimitManager` for orchestrating limiters, tiers, and attributes.
  - `EnhancedRateLimiterMiddleware` for automatic rate limit enforcement.
  - Pluggable storage via `StorageInterface` with Cache and Memory backends.
- Notes: No breaking changes. Backward compatible with existing `RateLimiterMiddleware`. Completes Priority 3 Phase 2.

### 1.16.0 — Meissa (Released 2026-01-22)
- **API Versioning Strategy**: Comprehensive API versioning system with multiple resolution strategies.
  - New `#[Version]` attribute for declaring API version requirements on routes.
  - New `#[Deprecated]` attribute for marking deprecated API versions with messages.
  - New `#[Sunset]` attribute for specifying sunset dates on deprecated endpoints.
  - Multiple version resolvers: URL prefix (`/v1/`), Header (`X-API-Version`), Query parameter, Accept header.
  - `VersionManager` for managing version resolution strategies.
  - `VersionNegotiationMiddleware` for automatic version detection.
  - `ApiVersion` value object for version comparisons and constraints.
  - Deprecation warnings via response headers.
  - Sunset headers for scheduled API retirement.
- Notes: No breaking changes. Versioning is opt-in and additive. Completes Priority 3 Phase 1.

### 1.15.0 — Rigel (Released 2026-01-22)
- **Real-Time Development Server**: Enhanced development server with file watching and integrated services.
  - New `FileWatcher` class for automatic file change detection with polling strategy.
  - New `RequestLogger` class for colorized HTTP request logging with timing.
  - New `LogEntry` class for structured request log entries.
  - `serve --watch` option for auto-restart on code changes.
  - `serve --queue` option for integrated queue worker.
  - Port auto-selection when preferred port is in use.
  - Colorized output with method and status code highlighting.
  - Configurable poll interval with `--poll-interval`.
- Notes: No breaking changes. Completes Priority 2 developer experience features.

### 1.14.0 — Bellatrix (Released 2026-01-22)
- **Interactive CLI Wizards**: Enhanced developer experience for console commands.
  - New `Prompter` class with fluent API for CLI prompts (ask, confirm, choice, multiChoice, suggest).
  - New `ProgressBar` wrapper with enhanced formats and auto-progress iteration.
  - New `Spinner` class with multiple animation styles for indeterminate progress.
  - `BaseCommand` enhanced with interactive helper methods.
  - Scaffold commands updated for interactive mode (prompts for arguments when not provided).
  - Full `--no-interaction` support for CI/CD environments.
- Notes: No breaking changes. Continues Priority 2 developer experience improvements.

### 1.13.0 — Saiph (Released 2026-01-22)
- **Enhanced Scaffold Commands**: Complete scaffold command system for rapid development.
  - `scaffold:middleware` - Generate route middleware classes implementing `RouteMiddleware`.
  - `scaffold:job` - Generate queue job classes with queue, retry, and timeout options.
  - `scaffold:rule` - Generate validation rule classes with parameter and implicit support.
  - `scaffold:test` - Generate PHPUnit test classes for unit and feature testing.
- **Database Factories & Seeders**: Complete test data generation and database seeding system.
  - `Factory` base class with states, sequences, relationships, and lifecycle callbacks.
  - `FakerBridge` for optional Faker integration with availability checking.
  - `Seeder` base class with dependency ordering, transactions, and truncation helpers.
  - `HasFactory` trait for ORM models enabling `Model::factory()` syntax.
  - `db:seed` command with production environment protection.
  - `scaffold:factory` and `scaffold:seeder` commands for generating classes.
- **Documentation**: New `docs/FACTORIES.md` with comprehensive usage guide.
- Notes: No breaking changes. Factories require `fakerphp/faker` as dev dependency. Completes Priority 2 features for v1.13.0.

### 1.12.0 — Mintaka (Released 2026-01-21)
- **API Resource Transformers**: Complete JSON transformation layer for consistent, well-structured API responses.
  - `JsonResource` base class for transforming arrays and simple objects.
  - `ModelResource` class with ORM-specific helpers for model transformation.
  - `ResourceCollection` for handling multiple items with metadata support.
  - `PaginatedResourceResponse` for pagination with link generation.
  - Conditional attributes: `when()`, `mergeWhen()`, `whenHas()`, `whenNotNull()`.
  - Relationship helpers: `whenLoaded()`, `whenCounted()`, `whenPivotLoaded()`.
  - `MissingValue` sentinel pattern for clean attribute omission.
  - Pagination support for both QueryBuilder flat format and ORM meta format.
- **Console Commands**: New `scaffold:resource` command to generate API resource classes.
  - `--model` option for ModelResource with ORM-specific helpers.
  - `--collection` option for ResourceCollection with metadata support.
  - `--force` option to overwrite existing files.
  - `--path` option for custom output directory.
- Notes: No breaking changes; API Resources are opt-in and additive. Completes Priority 1 features.

### 1.11.0 — Alnilam (Released 2026-01-21)
- **ORM / Active Record**: Complete Active Record implementation built on QueryBuilder.
  - New `Model` base class with CRUD operations, mass assignment protection, and attribute handling.
  - New `Builder` class wrapping QueryBuilder with model-aware queries, eager loading, and scope support.
  - New `Collection` class for model results with rich iteration, filtering, and transformation methods.
  - Relations: `HasOne`, `HasMany`, `BelongsTo`, `BelongsToMany`, `HasOneThrough`, `HasManyThrough`, `Pivot`.
  - Traits: `HasAttributes`, `HasEvents`, `HasRelationships`, `HasTimestamps`, `HasGlobalScopes`, `SoftDeletes`.
  - Custom casts: `AsJson`, `AsArrayObject`, `AsCollection`, `AsDateTime`, `AsEncryptedString`, `AsEnum`, `Attribute`.
  - Model lifecycle events: `ModelCreating`, `ModelCreated`, `ModelUpdating`, `ModelUpdated`, `ModelSaving`, `ModelSaved`, `ModelDeleting`, `ModelDeleted`, `ModelRetrieved`.
  - Contracts: `ModelInterface`, `CastsAttributes`, `Scope`, `SoftDeletable`.
  - New `ORMProvider` service provider for DI container registration.
- **Console Commands**: Reorganized CLI commands to `scaffold:*` namespace.
  - New `scaffold:model` command to generate ORM model classes.
  - Renamed `make:request` → `scaffold:request`, `generate:controller` → `scaffold:controller`.
- **CI**: GitHub Actions jobs now run sequentially for better debugging.
- Notes: No breaking changes; ORM is opt-in and additive. Existing QueryBuilder code continues to work.

### 1.10.0 — Elnath (Released 2026-01-21)
- **Exception Handler**: Centralized exception handling with typed HTTP exceptions (4xx Client, 5xx Server, Domain).
  - New `ExceptionHandlerInterface` contract and `RenderableException` interface.
  - New `HttpException` base class with status codes and context.
  - New `Handler` class with environment-aware error responses.
  - New `ExceptionMiddleware` for automatic exception-to-response conversion.
  - Client exceptions: `BadRequestException`, `UnauthorizedException`, `ForbiddenException`, `NotFoundException`, `MethodNotAllowedException`, `ConflictException`, `UnprocessableEntityException`, `TooManyRequestsException`.
  - Server exceptions: `InternalServerException`, `ServiceUnavailableException`, `GatewayTimeoutException`.
  - Domain exceptions: `ModelNotFoundException`, `AuthenticationException`, `AuthorizationException`, `TokenExpiredException`.
- **Request Validation**: Declarative validation with PHP 8 attributes and FormRequest classes.
  - New `#[Validate]` attribute for inline validation on controller methods.
  - New `FormRequest` base class with authorization, custom messages, and data preparation hooks.
  - New `ValidatedRequest` wrapper for type-safe validated data access.
  - New `ValidationMiddleware` for automatic request validation in the pipeline.
  - New `RuleParser` for Laravel-style string rule syntax (`'required|email|max:255'`).
  - New `'validate'` middleware alias for route-level validation.
  - New validation rules: `Confirmed`, `Date`, `Before`, `After`, `Url`, `Uuid`, `Json`, `Exists`, `Nullable`, `Sometimes`, `File`, `Image`, `Dimensions`.
  - New `make:request` CLI command to generate FormRequest classes.
- Notes: No breaking changes; both features are opt-in and additive.

### 1.9.2 — Deneb (Released 2026-01-20)
- Documentation: New `ResourceRouteExpander` class that automatically expands `{resource}` routes to table-specific endpoints with full database schemas.
- Documentation: OpenAPI 3.1.0 support with JSON Schema draft 2020-12 alignment (nullable type arrays, SPDX license identifiers, jsonSchemaDialect).
- Documentation: Default OpenAPI version changed to 3.1.0; output file renamed from `swagger.json` to `openapi.json`.
- Documentation: Scalar UI improvements with `hideClientButton` and `showDeveloperTools` configuration options.
- Documentation: Tags in sidebar now sorted alphabetically; resource tags renamed to "Table - {name}".
- Database: Fixed `SchemaBuilder::getTableColumns()` returning empty arrays due to incorrect `array_is_list()` check.
- Removed: `TableDefinitionGenerator` class - resource routes now expand directly from database schemas.

### 1.9.1 — Castor (Released 2026-01-19)
- Documentation: Major refactor of OpenAPI documentation system with new `OpenApiGenerator` and `DocumentationUIGenerator` classes.
- Documentation: New `--ui` option for `generate:openapi` command supporting Scalar, Swagger UI, and Redoc.
- Documentation: New `config/documentation.php` centralizing all documentation settings.
- Documentation: `CommentsDocGenerator` now uses `phpDocumentor/ReflectionDocBlock` for robust PHPDoc parsing.
- Documentation: Extension route discovery now uses `ExtensionManager::getProviders()` for Composer-installed packages.
- Validation: New `Numeric` and `Regex` validation rules.
- Dependencies: Updated Symfony packages to ^7.4; added `phpdocumentor/reflection-docblock`.
- Console: Fixed `Application` to use `add()` instead of undefined `addCommand()` for Symfony Console compatibility.

### 1.9.0 — Betelgeuse (Released 2026-01-17)
- **Breaking**: Minimum PHP version raised to 8.3 (from 8.2). CI now tests PHP 8.3 and 8.4.
- Console: Renamed `Application::addCommand(string)` to `Application::registerCommandClass(string)` to resolve Symfony Console 7.3 compatibility.
- Routing: `RouteManifest::load()` now prevents double‑loading routes, eliminating duplicate route warnings in CLI commands.
- Security: Fixed PHPStan strict boolean check in `CSRFMiddleware` for cookie token validation.
- Tests: Added missing PSR‑4 namespace declarations to async test files.

### 1.8.1 — Vega (Released 2025-11-23)
- Security: Extend `Utils::validatePassword()` so policies can require lowercase characters alongside existing uppercase, numeric, and special-character toggles.
- Async I/O: Harden `async_stream()` helper to accept already-wrapped `AsyncStream`/`BufferedAsyncStream` instances or raw resources, always normalizing to a canonical async transport before applying buffered wrappers and config defaults. This fixes static analysis complaints around BufferedAsyncStream return types.

### 1.8.0 — Spica (Released 2025-11-13)
- Events (Auth): First-class hooks to enrich session cache and login responses
  - `SessionCachedEvent` dispatched after cache write in `SessionCacheManager::storeSession()`
  - `LoginResponseBuildingEvent` and `LoginResponseBuiltEvent` around `AuthController::login()` return
- DX: Documented event usage with setter-based mutation (no by-ref), paths under `src/...`, and listener examples in `docs/SESSION_EVENTS_PROPOSAL.md`
- Guidance: Encourage app data under `context.*` to avoid field collisions; events are synchronous so heavy work should be queued

### 1.7.4 — Arcturus (Released 2025-10-28)
- Authentication: Minimal, configurable account‑status gate enforced in core login and refresh flows via `security.auth.allowed_login_statuses` (default `['active']`). Silent failure to avoid account enumeration; policy is intentionally lean to let apps extend.

### 1.0.0 — Aurora (Released 2025-09-20)
- **First stable release** of the split Glueful Framework package with comprehensive features.
- **Comprehensive permissions and authorization system**.
- Core overhauls: custom DI container (replacing Symfony DI); PSR‑14 event dispatcher.
- Storage: migration to Flysystem with updated configuration model.
- Config & Validation: array‑schema configuration and rules‑based validation system.
- Next‑Gen Router with fast static/dynamic matching and attribute route loader.
- Alias support for services and improved provider bootstrapping.
- Security enhancements with expanded CLI commands and hardened endpoints.
- Cleanup: removal of LDAP/SAML auth and legacy config/serialization modules.
- Docs: database and storage guides refreshed; comprehensive cookbook.

### 1.1.0 — Polaris (Released 2025-09-22)
- **Testing infrastructure**: TestCase base class for application testing with framework state reset support.
- **Event system enhancements**: Comprehensive documentation covering all framework events with complete examples.
- **Event abstraction layer**: BaseEvent class providing PSR-14 compliant abstraction with enhanced features.
- **Documentation improvements**: Complete event listener registration patterns and best practices.
- Guides navigation and testing utilities for robust application development.

### 1.2.0 — Vega (Released 2025-09-23)
- **Tasks/Jobs Architecture**: Complete separation of business logic (Tasks) from queue execution (Jobs).
- **Task Management System**: Comprehensive task classes for cache maintenance, database backup, log cleanup, notification retry, and session cleanup.
- **Enhanced Console Commands**: New `cache:maintenance` command and improved console application structure.
- **Testing Infrastructure**: Complete integration test coverage with enhanced container management and reliability.
- **Code Quality**: Fixed PHP CodeSniffer violations and improved test state management.
- **Migration**: Removed legacy `src/Cron/` classes in favor of the new architecture.

### 1.3.0 — Deneb (Released 2025-10-06)
- HTTP: Strategy‑based retry support using Symfony `RetryableHttpClient` and `GenericRetryStrategy`.
- API Client Builder: `retries(...)`, `buildWithRetries()`, and `getRetryConfig()` for fluent retry configuration.
- Presets: sensible retry defaults for payments and external service integrations.
- Notes: No breaking changes; improves resilience and clarity for outbound HTTP.

### 1.3.1 — Altair (Released 2025-10-10)
- Console: `install` command runs truly non‑interactive in CI when `--quiet`, `--no-interaction`, or `--force` are provided (skips env confirmation prompt).
- DX: clean PHPStan signal by removing redundant `method_exists()` check on `InputInterface::isInteractive()`.

### 1.4.0 — Rigel (Released 2025-10-11)
- Sessions: Introduce SessionStoreInterface and default SessionStore for a unified, testable session lifecycle (create/update/revoke/lookup/health).
- TTL Policy: Canonical TTL helpers on the store (provider + remember‑me aware); TokenManager defers TTLs to the store.
- DI & Resolver: Add SessionStoreResolver and ResolvesSessionStore trait; providers and managers consistently resolve the store via DI.
- Caching: Safe cache keys for token mappings (hashed tokens; sanitized prefixes) to avoid backend key restrictions.
- Removals: TokenStorageService and TokenStorageInterface removed after migration; deprecated paths eliminated.
- Analytics: Store‑first listing with fallbacks; reduced cache‑shape coupling.

### 1.4.1 — Rigel (Patch) (Released 2025-10-11)
- Install UX: SQLite‑first install flow; other engines are skipped during install and can be configured post‑install.
- Non‑interactive defaults: `migrate:run` invoked with `--force` (and `--no-interaction`/`--quiet` when install runs with `--quiet`).
- Cache clear: `cache:clear` during install runs with `--force` and respects quiet mode flags to avoid prompts.
- Health checks: database connection probe removed from install (SQLite does not require a network connection; migrations surface real issues).
- DX: removed redundant comparison that triggered a PHPStan strict‑comparison warning.

### 1.4.2 — Rigel (Patch) (Released 2025-10-11)
- Dev ergonomics: fix PSR‑4 autoloading for tests by aligning test namespaces with `Glueful\Tests\…` to remove Composer warnings.
- Documentation: synced release notes to reflect 1.4.1 install flow improvements; no runtime behavior changes.

### 1.5.0 — Orion (Released 2025-10-13)
- Notifications: introduce shared DI provider for `ChannelManager` and `NotificationDispatcher` (NotificationsProvider).
- Email flows: EmailVerification and queue job SendNotification use DI‑first dispatcher/channel resolution with safe fallbacks.
- Safety: remove hard `ExtensionManager` prechecks in email verification/password reset; rely on dispatcher/channel availability.
- Diagnostics: soft logging when email channel is unavailable or when no channels succeeded (verification/password reset).
- Config: align retry configuration source to `email-notification.retry`.

### 1.6.0 — Sirius (Released 2025-10-13)
- Router: content negotiation helpers; ETag/conditional middleware patterns.
- DI: compiled container optimizations; `services.json` manifest + map/codegen helpers.
- Config: DSN parsing utilities (DB, Redis) and environment validation helpers.
- Security: CSP builder configuration + presets; refined admin/allow‑lists.
- Extensions: OpenTelemetry exporter planned as optional extension (not core).

### 1.7.0 — Procyon (Released 2025-10-18)
- Async/Concurrency: Introduce fiber‑based scheduler with `spawn`, `all`, `race`, `sleep`.
- Async HTTP: `CurlMultiHttpClient` with cooperative polling, streaming, pooling, and retry knobs.
- Async I/O: `AsyncStream` and `BufferedAsyncStream` for non‑blocking reads/writes and convenience helpers.
- Cancellation: Cooperative `CancellationToken` across scheduler, I/O, and HTTP.
- Promise: Lightweight chainable wrapper for Tasks with `then/catch/finally`, `all/race`.
- Metrics: Expanded instrumentation hooks (suspend/resume, queue depth, resource‑limit signals).
- Config & DI: Central `config/async.php`; `AsyncProvider` wires `Scheduler`, `HttpClient`, and metrics; `AsyncMiddleware` alias `async`.
- Notes: Defaults are backward‑compatible; resource limits off unless configured.

### 1.7.1 — Canopus (Released 2025-10-21)
- Extensions: Call `ExtensionManager::discover()` before `::boot()` during framework initialization to ensure providers are instantiated and booted at runtime.
- Migrations: Extension migrations registered via `loadMigrationsFrom()` are now visible to `migrate:status`/`migrate:run`.
- CLI: `extensions:why` and `extensions:list` reflect the final provider list after boot, improving diagnostics.

### 1.7.2 — Antares (Released 2025-10-21)
- Extensions: `ServiceProvider::loadRoutesFrom()` made idempotent and exception‑safe to avoid duplicate route registration and to keep boot resilient in production.
- CLI: `serve` command reclassifies PHP built‑in server access/startup lines as normal output to reduce false error noise.

### 1.7.3 — Pollux (Released 2025-10-21)
- Database: QueryBuilder normalizes 2‑argument `where($column, $value)` / `orWhere($column, $value)` to `=` operator, fixing a `TypeError` and improving portability of boolean/int filters across PostgreSQL/MySQL/SQLite.
- DX: Further cleanup of dev‑server STDERR log classification; access/lifecycle lines are treated as info while preserving real error visibility.

### 1.8 (Minor)
- Queue/workers: improved autoscaling rules; per‑queue budgets; graceful drain; health endpoints.

### 2.0 (Major; tentative)
- Planned only if a fundamental contract change is justified (e.g., core interface shifts). Otherwise continue 1.x with incremental improvements.

## Cross‑Repository Goals
- API Skeleton (glueful/api‑skeleton)
  - Smoke tests and deploy templates; default Redis queue; Docker/Helm examples.
- Docs (glueful/docs)
  - Publish and maintain cookbook sections (setup, routing, DI DSL, uploads, security, observability) alongside releases.

## Contribution & Coordination
- Issues/PRs: use labels `roadmap`, `epic`, `rfc`, `good‑first‑issue`.
- Proposals: open a Discussion or RFC issue outlining goals, scope, risks, and acceptance criteria.
- For potentially breaking changes, follow `BREAKING_CHANGE_PROCESS.md`.
- CI required checks (for PR merge):
  - "PHP CI / Lint & Style"
  - "PHP CI / PHPUnit (PHP 8.3)" (and PHP 8.4 optional)
  - "PHP CI / PHPStan"
  - "PHP CI / Security Audit" (optional)

This document is intentionally concise; detailed designs will be tracked per issue/RFC.

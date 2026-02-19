# CHANGELOG

All notable changes to the Glueful framework will be documented in this file.

The format is based on Keep a Changelog, and this project adheres to Semantic Versioning.

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

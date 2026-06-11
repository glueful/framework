# Framework Security & Correctness Review

> # ✅ FULLY RESOLVED (2026-06-11)
> **Every finding in this document is fixed, documented, or addressed** -- all four P1s, the entire P2 set, the Container hardening pair (S1/S2), the S2b alias footgun, the lower-severity container notes, and the two final follow-ups (Router object-handler reflection key; `FlysystemStorage::exists()`/`delete()` PathGuard). Each change is TDD'd; full suites green (Unit 1149 / Integration 145), PHPStan level 6 + phpcs clean. CHANGELOG `[Unreleased]` covers all of it. The detail below is retained as the audit trail; individual findings carry inline resolution notes.

**Repo:** `glueful/framework`
**Branch/commit:** `dev` @ `e9b222a` (post-1.54.0 "Okab")
**Date:** 2026-06-11
**Method:** Five parallel read-only subsystem reviews (Routing, Container/DI, Auth/Permissions, Database, Storage/Crypto/Queue), with the two highest-impact findings independently verified against source.

---

## Health baseline: green

- 1106 unit + 142 integration tests pass
- PHPStan level 6 clean
- phpcs (PSR-12) clean
- 885 source files reviewed across the subsystems below

The findings below are **latent issues a passing suite does not catch** -- fail-open paths, state/correctness bugs, and load-time-vs-runtime validation gaps.

---

## Resolution status (updated 2026-06-11)

**All four P1s, the entire P2 set, and the Container hardening pair (S1/S2) are fixed** (each TDD'd; full suites green -- Unit 1147 / Integration 144; PHPStan level 6 + phpcs clean) in the working tree on `dev`, not yet committed. CHANGELOG `[Unreleased]` covers each. Only the lower-severity P3s remain.

### P1 (all fixed)

| # | P1 finding | Status |
|---|------------|--------|
| 1 | `#[RequiresPermission]`/`#[RequiresRole]` never enforced | **FIXED** -- Router populates `handler_meta`; `AttributeRouteLoader` auto-attaches `gate_permissions` for method- AND class-level attributes |
| 2 | Signed-URL hardcoded-secret fallback | **FIXED** -- `SignedUrl::resolveSecretKey()` fails closed (throws) when no secret is configured |
| 3a | Soft-delete column cache shared across connections | **FIXED** -- cache namespaced per `Connection` instance (+ column) |
| 3b | Connection-pool reuse with no reset | **FIXED** -- `release()` rolls back the raw PDO's open transaction + clears session state (`DISCARD ALL`/`RESET CONNECTION`) |
| 3c | `getConditionsArray()` duplicate-column collapse | **FIXED + extended** -- repeated columns now correctly apply BOTH predicates (range UPDATE/DELETE), not just refuse |
| 4 | `storeContent()` bypasses PathGuard | **FIXED** -- routed through new PathGuard-validated `StorageManager::put()` |

### P2 (all fixed)

| Item | Status |
|------|--------|
| `#[RequireScope]` passes for non-API-key (JWT) requests | **FIXED** -- absent `api_key_scopes` attribute now denies (vs present-but-empty = unrestricted key) |
| Unverified-JWT claims fallback | **FIXED** -- removed; claims only from signature-verifying `JWTService::decode()` |
| SQLi sinks lacking allow-lists | **FIXED** -- `SqlOperators` allow-list on JOIN op/type, HAVING op, ORM `has()` op; JSON path grammar-validated; `wrapIdentifier()` doubles embedded quotes (MySQL/PG) |
| SecureSerializer namespace breadth | **FIXED** -- namespace auto-allow now excludes classes with `__wakeup`/`__destruct`/`__unserialize` (gadget shapes) |
| API key via query string | **FIXED** -- gated behind `security.api_keys.allow_query_param` (default off) |
| CSRF rate-limit fail-open | **FIXED (config-gated)** -- default fail-open preserved (availability); opt into fail-closed via `security.csrf.rate_limit_fail_closed` |
| Signed-URL not host-bound | **DOCUMENTED** -- per-environment signing secret required (deliberately not host-bound: breaks proxies / invalidates live URLs; root cause is `APP_KEY` reuse). Note in `SignedUrl` + `config/uploads.php` |

### Container hardening (S1/S2 -- fixed)

| # | Finding | Status |
|---|---------|--------|
| S1 | Extension load failures `error_log`-only and swallowed | **FIXED** -- rethrow (naming provider+phase) outside prod; record (`ContainerFactory::failedProviders()`) + WARNING-log in prod |
| S2 | Interface/abstract-keyed service fatals at resolution, not load | **FIXED** -- `DefaultServicesLoader` rejects at load, naming the id (also surfaces the inverted-`alias` footgun) |

**Nothing open.** The two final follow-ups are now fixed: the `Router::getReflection()` object-handler key derives the class instead of stringifying the object (`RouterTest::testGetReflectionHandlesObjectHandler`), and `FlysystemStorage::exists()`/`delete()` route through new PathGuard-validated `StorageManager::fileExists()`/`delete()` (`FlysystemStorageTest::testExistsAndDeleteRejectUnsafePaths`). The container P3s and the S2b alias footgun were fixed/documented earlier in this round. Scope notes on the fixes: #3c was completed as a *feature* (range support) rather than a fail-closed refusal; #3b adds the session reset on top of the transaction rollback; CSRF and the signed-URL host concern were resolved conservatively (config-gated / documented) to avoid availability and proxy regressions.

---

## Verdict on the uncommitted change: SAFE TO COMMIT (committed-equivalent -- now landed in the working tree)

The `handler_meta` Router fix (`src/Routing/Router.php`, `tests/Unit/Routing/RouterTest.php`) verified clean on all counts:

- Handles every handler form (`[Class::class, 'method']`, `'Class::method'` string, invokable class, already-instantiated object); closures return `null` and do not crash.
- Sets `handler_meta` (line ~650) **before** the middleware pipeline is built/run.
- No hot-path reflection cost (string/array inspection + one `class_exists`).
- The regression test (`RouterTest.php:256`) proves deny -> 403 through the **real** dispatch pipeline + real `GateAttributeMiddleware`, mocking only the `PermissionManager::can()` boundary.
- HEAD (duplicated to GET), auto-OPTIONS preflight (returns before set -- correct), and explicit OPTIONS routes all behave correctly.

---

## P1 -- confirmed, address before the next tag

### 1. `#[RequiresPermission]` / `#[RequiresRole]` are never enforced (fail-open by default) -- ✅ FIXED

> **Resolved:** Router now derives `handler_meta` after route match and sets it before the pipeline; `AttributeRouteLoader::processGateAttributes()` auto-attaches `gate_permissions` when either attribute is present on the method or the handler class. Regression tests in `AttributeRouteLoaderTest` (method, role, class-level, negative) + `RouterTest` (deny -> 403 end-to-end).

**Verified directly.** `src/Routing/AttributeRouteLoader.php` auto-attaches `require_scope` middleware for `#[RequireScope]` (`processRequireScopeAttributes`, lines 385-400) but has **no equivalent** for `RequiresPermission`/`RequiresRole`. The `gate_permissions` alias appears nowhere except its own definition (`src/Container/Providers/CoreProvider.php:660`).

Consequence: a controller method annotated `#[RequiresPermission('write:posts')]` runs with **zero enforcement** unless the developer *also* manually adds `->middleware('gate_permissions')`. The `handler_meta` fix made the middleware work *when present* -- but it is never auto-present for the attribute.

This retroactively validates the decision (subscriptions / managed-plan-catalog) to use a custom extension-owned guard calling `PermissionManager::can()` directly instead of relying on the attribute. The attribute is currently a trap.

**Fix:** mirror `processRequireScopeAttributes` -- scan for `RequiresPermission`/`RequiresRole` in `AttributeRouteLoader` and auto-attach `gate_permissions`.

### 2. Signed-URL secret silently falls back to a hardcoded constant -- ✅ FIXED

> **Resolved:** `resolveSecretKey()` now throws when no secret is found in config or env (resolution order: `uploads.signed_urls.secret` -> `app.key` -> `SIGNED_URL_SECRET` -> `APP_KEY` -> throw). Both the generate and validate paths fail closed. Tests in `SignedUrlTest` pin the throw and that env-provided construction still works.

`src/Support/SignedUrl.php:169` -- `resolveSecretKey()` returns the literal `'glueful-default-signing-key'` when both `SIGNED_URL_SECRET` and `APP_KEY` are absent from the environment.

This HMAC gates **private blob access** (`UploadController::signedUrl()`, `checkBlobAccess()`/`hasValidSignature()`). On a misconfigured deployment the secret becomes a publicly known constant -> an attacker forges `expires`+`signature` for any blob UUID and bypasses auth on private files. Worsened because the bare constructor path reads only env superglobals while `make()` reads `app.key` config -- the two key sources can diverge.

**Fix:** fail closed (throw) when no secret is configured; reconcile the env-vs-config key source.

### 3. Database state/correctness bugs causing wrong-row writes -- ✅ ALL FIXED

Not classic string-SQLi -- silent wrong-row writes, which are worse because they pass tests.

- **Process-global soft-delete column cache** -- `src/Database/Features/SoftDeleteHandler.php:218` (`static $columnCache = []`), driving the soft-vs-hard delete decision at `src/Database/QueryBuilder.php:719`. Keyed only by table name (no connection/database/tenant/schema). In multi-tenant or multi-DB processes, tenant A's "`documents` has `deleted_at`" answer applies to tenant B: B's rows get **hard-deleted irreversibly**, or soft-deleted rows **leak into reads** (the `WHERE deleted_at IS NULL` guard is skipped). The PG branch also omits `table_schema`. This is the known cross-DB test-poisoning hazard, confirmed as a **runtime** hazard. **✅ FIXED:** cache key is now namespaced per `Connection` instance (monotonic id + table + configured column); a single connection still amortizes the schema lookup. Test: `SoftDeleteCacheIsolationTest`.
- **Connection-pool reuse with no reset** -- `src/Database/ConnectionPool.php:234` (`release()`). No rollback of open transactions, no `DISCARD ALL`/session/temp-table reset on checkout; the TransactionManager holds the raw PDO and bypasses the wrapper's `inTransaction` flag. A borrower that leaves an open transaction returns it live -> the next borrower's `commit()` commits the prior write, or its writes are rolled back, or it reads uncommitted cross-tenant rows. Opt-in (`DB_POOLING_ENABLED=false` default) but unsafe for any multi-tenant deploy that enables it. **✅ FIXED:** `release()` rolls back any transaction on the raw PDO and issues a driver-appropriate session reset (`DISCARD ALL` / `RESET CONNECTION`, no-op elsewhere, disabled gracefully if unsupported); a connection that cannot be cleaned is retired. Tests: `ConnectionPoolReleaseTest`.
- **`getConditionsArray()` duplicate-column collapse** -- `src/Database/Query/WhereClause.php:378-509`, consumed by Update/DeleteBuilder. Reparses conditions into a map keyed by column name, so two predicates on the same column silently overwrite: `->where('id','>',100)->where('id','<',200)->delete()` becomes `DELETE ... WHERE id < 200`. **Deletes/updates far more rows than intended**, reachable through ordinary request-bound filter code. The scariest of the three -- no pooling or multi-tenancy required. **✅ FIXED + extended:** repeated columns fold into a `__multi` list and both Update/DeleteBuilder emit each predicate AND-joined -- so a range now applies BOTH bounds (a feature) rather than collapsing. Tests: `QualifiedColumnWriteTest` (range DELETE + UPDATE affect only matching rows).

### 4. `FlysystemStorage::storeContent()` bypasses PathGuard -- ✅ FIXED

`src/Uploader/Storage/FlysystemStorage.php:42-54` -- `storeContent()` writes directly via `$this->storage->disk(...)->write(...)` instead of routing through `StorageManager::putStream()`, which is where `PathGuard::validate()` runs. A user-influenced `$destinationPath` skips `..`/absolute/null-byte validation. Mitigated on the local Flysystem adapter (rejects escape), real on cloud drivers -- an explicit hole in the "every write routes through PathGuard" invariant. *(`store()` at line 22 is fine -- it uses `putStream()`.)*

> **Resolved:** added a PathGuard-validated `StorageManager::put(path, contents, disk)` (the missing string-content primitive alongside `putStream`/`putJson`) and routed `storeContent()` through it. Note from implementation: Flysystem's own normalizer already rejects `..` for all adapters, so the genuinely-uncovered case is **absolute paths** (Flysystem relativizes them; PathGuard rejects) -- that is what the regression test pins (`FlysystemStorageTest::testStoreContentRejectsUnsafePath`).

---

## P2 -- real, mostly developer-facing or config-dependent -- ✅ ALL RESOLVED

> **Resolved:** every item below is fixed (RequireScope bypass, unverified-JWT fallback, the SQLi operator/identifier sinks, SecureSerializer gadget gate, API-key query gate, CSRF config-gated fail-closed) or addressed by documentation (signed-URL per-environment secret). See the Resolution status table above for the per-item mechanism. Original findings retained below for context.

- **`#[RequireScope]` passes unconditionally for non-API-key (e.g. JWT) requests** -- `src/Routing/Middleware/RequireScopeMiddleware.php:38` reads granted scopes from the `api_key_scopes` attribute (only set by `ApiKeyAuthenticationProvider`). For a JWT request it is empty, and `ApiKeyService::scopeSatisfies([], $required)` returns `true` (empty = full access). So `#[RequireScope('admin:posts')]` is satisfied by any JWT-authenticated user. *Fix: distinguish "no scopes attribute present" (deny on a scoped route) from "key grants all scopes."*
- **Unverified-JWT fallback feeds `jwt.claims`** -- `src/Permissions/Middleware/AuthToRequestAttributesMiddleware.php:151-163` base64-decodes the JWT payload with **no signature verification** as a fallback; those claims flow into `ScopeVoter`. Latent (the `class_exists('\Glueful\Auth\JWTService')` guard is always true in-framework) but one deletion from a forge-the-scope escalation. *Fix: remove the unverified fallback.*
- **SQLi sinks lacking allowlists** (all developer-facing parameters; safe under documented use; inject only if an app forwards request input):
  - ORM `has()`/`whereHas()` operator -> `whereRaw` (`src/Database/ORM/Builder.php:848`)
  - JSON `$path` interpolated raw in `whereJsonContains` (`WhereClause.php:181,230`) -- reads as a *safe* parameterized method; the most surprising
  - JOIN operator/type not allowlisted (`JoinClause.php:141`); `having()` operator not allowlisted (`QueryModifiers.php:244`)
  - `wrapIdentifier()` does not double the quote char (`MySQLDriver`/`PostgreSQLDriver`) -- backstopped by `QueryValidator` except on the `where()`-column path, which skips the validator (`QueryBuilder.php:176`)
- **SecureSerializer auto-allows entire namespaces** -- `src/Security/SecureSerializer.php:397-431` auto-allows `Glueful\Models\*`, `\DTOs\*`, `\Entities\*`, `\Extensions\*\Models\*`/`\DTOs\*` for `unserialize`, independent of the explicit allowlist. No live gadget today (no magic methods in those namespaces), but any future model with `__wakeup`/`__destruct` silently becomes a cache/queue deserialization gadget. The gate logic itself (wildcard/`O:`/`C:` extraction, fail-closed, never `allowed_classes => true`) is correct; only the breadth is the issue. *Fix: narrow to an explicit list.*
- **API key accepted via query string** -- `ApiKeyAuthenticationProvider.php:156` (`?api_key=`). Leaks into access logs, proxies, browser history, `Referer`. *Fix: header-only (`X-API-Key`), or gate the query path behind config like JWT does.*
- **CSRF anonymous-session token bound to a spoofable fingerprint** (IP + UA + Accept headers), and `checkRateLimit()` fails open when cache is unavailable (`CSRFMiddleware.php:635`). Lower impact for bearer-token APIs (origin validation is the real protection) than for cookie-session apps.
- **Signed-URL signature does not bind host/scheme** -- enables cross-environment replay if `APP_KEY` is reused across staging/prod (a known anti-pattern).

---

## Systemic: the Container hardening pair (highest leverage)

Both the Container and Extensions reviews independently confirmed the same root cause behind the recurring extension-wiring failures this program hit (subscriptions, import-export, flags, i18n). **The Container/Extensions boundary defers all validation to runtime and swallows everything in between.** Both fixes push detection to load time and make non-prod fail loud.

### S1. Extension provider load failures are `error_log`-only and swallowed -- ✅ FIXED

> **Resolved:** `ContainerFactory` now routes the `defs()`/`services()`/`tags()` catches through a shared handler -- rethrow (wrapped, naming provider + phase) outside production; record into `ContainerFactory::failedProviders()` + WARNING-log in production while continuing boot. Test: `ProviderLoadFailureTest`.

`src/Container/Bootstrap/ContainerFactory.php:207-209` (typed `defs()`) and `:223-225` (DSL `services()`). When a provider's contribution throws -- including the `InvalidArgumentException`s `DefaultServicesLoader` raises for malformed specs -- the **entire provider** is dropped and boot continues with no env gating and no structured log. Real consequence seen this program: an entitlement-checker extension vanished and core's allow-all default served instead (paywall silently open).

**Recommendation:** gate the catch on environment -- rethrow (wrapped, naming the provider class) in non-prod; in prod upgrade `error_log` to a WARNING-level structured log and accumulate failed providers into an exposed registry so `extensions:diagnose` / a health endpoint can surface partial boot. The model already exists in-tree: `ServiceProvider::loadRoutesFrom()` (`src/Extensions/ServiceProvider.php:124-131`) rethrows when `APP_ENV !== 'production'`. The inconsistency is itself the smell. Apply the same treatment to the `applyProviderTags` catch (`:277-280`).

### S2. Interface/abstract-keyed service with no `class`/`factory` fatals at first resolution, not at load -- ✅ FIXED

> **Resolved:** `DefaultServicesLoader::load()` now rejects a service whose resolved class is an interface or abstract (with no `class`/`factory`/`autowire`) at load time, naming the id. The full suite (1147 unit) stays green -- no existing provider used the pattern -- confirming the guard only catches genuine mis-declarations. Test: `NonInstantiableServiceGuardTest`.

`src/Container/Loader/DefaultServicesLoader.php:43-49` (class inference) -> `:89-95` (emits `new $class(...)`). When a service id is an interface/abstract and no explicit `class`/`factory`/`autowire` is given, `$class` is inferred as the id; the definition loads green and only fatals with "Cannot instantiate interface" at first resolution -- possibly in production, possibly on a cold path. Three extensions shipped this bug green this program.

**Recommendation:** add a load-time guard in `DefaultServicesLoader::load()` after class inference:

```php
if (interface_exists($class) || (class_exists($class) && (new \ReflectionClass($class))->isAbstract())) {
    throw new \InvalidArgumentException($this->ctx($providerClass,
        "Service '$id' resolves to non-instantiable '$class' with no 'class', 'factory', or 'alias'. " .
        "Bind it to a concrete class (['class' => Concrete::class]) or a factory."));
}
```

Throw at load time in **all** environments (it is never a valid declaration), naming the id and inferred class.

### S2b. Inverted `'alias'` DSL footgun (related) -- ✅ FIXED

`DefaultServicesLoader::collectAliases()` (`:195-211`) reads `Id => ['alias' => X]` as "create alias X pointing at Id" -- the **opposite** of the intuitive `Interface => ['alias' => Concrete]` (which leaves `Interface` bound to `new Interface()`, the S2 fatal). This bricked two extensions this program.

> **Resolved:** the S2 load-time guard now rejects the interface-bound-to-`new Interface()` outcome, and `collectAliases()` carries an explicit doc comment spelling out the direction (alias points AT `$id`; bind interfaces on the concrete entry).

### Lower-severity container notes -- ✅ ALL ADDRESSED

- ~~`array_replace` extension-over-core merge re-pins `ApplicationContext` after merge (airtight for that key) but the protected surface is three scattered statements, not a named `RESERVED_KEYS` set.~~ **FIXED** -- consolidated into `ContainerFactory::RESERVED_KEYS` (+ `reservedKeys()` accessor) and a `pinReservedDefinitions()` helper.
- ~~A tag with zero contributing services has no `TaggedIteratorDefinition`, so `$c->get('some.tag')` throws `NotFoundException` on a fresh install rather than returning `[]` -- consumers must `has()`-check.~~ **DOCUMENTED** -- inline note at the tag-collection loop; consumers of optional extension tags `has()`-check.
- ~~Prod container compile failure silently degrades to the runtime container with no signal (`ContainerFactory.php:84-87`).~~ **FIXED** -- now logs at WARNING before the runtime-container fallback.

---

## Confirmed solid (context for the findings)

- **Encryption** (`src/Encryption/EncryptionService.php`): correct AES-256-GCM -- random 12-byte nonce per encrypt, tag verified on decrypt, AAD bound, O(1) key-id rotation lookup, strict base64, hardcoded cipher (no downgrade). No padding-oracle, no obvious timing leak.
- **JWT** (`src/Auth/JWTService.php`): algorithm pinned to the configured HS variant and the token `alg` must match exactly -- no `alg=none`, no HS/RS confusion; `hash_equals` signature check; `exp` enforced. JWT auth additionally requires a server-side session.
- **API key** (`src/Auth/ApiKey/`): SHA-256 + `hash_equals` constant-time compare, single-track table lookup (no legacy fallback), revocation/expiry/IP-allowlist enforced, generic error messages.
- **PermissionManager::can()** is fail-closed at the core: voterless / abstain-only / empty tallies all resolve to DENY in every strategy branch. *(The gap is the route-attribute wiring layer -- P1 #1, not the decision core.)*
- **Production error envelope** (`src/Http/Exceptions/Handler.php`): default messages when debug off; stack/file/line only under debug. `ValidationException -> 422` correctly mapped. No secret/trace leak in prod.
- **Queue DB payloads** use `json_encode`/`json_decode` -- no PHP `unserialize` of DB payloads (no object injection via the database queue).
- **Query-builder identifier guard** holds on its validated paths (two-layer: `QueryValidator` char-block + `wrapIdentifier`).
- **Route cache integrity**: sha256 signature over source files + `hash_equals` + atomic temp-file rename; rejects cached closures.

Note: **LDAP/SAML auth providers do not exist in core** (only JWT, API key, JWT-session) despite the CLAUDE.md mention -- they presumably live in extensions.

---

## Suggested remediation batching

The **"1.54.1 -- security + hardening"** batch -- now complete:

1. ~~**P1 #1** -- auto-attach `gate_permissions` for `#[RequiresPermission]`/`#[RequiresRole]`.~~ **DONE.**
2. ~~**P1 #2** -- signed-URL fail-closed on missing secret.~~ **DONE.**
3. ~~**S1 + S2** -- container load-time guard + loud extension-load failures (highest leverage; closes the recurring ecosystem bug class).~~ **DONE.**
4. ~~**P2** -- remove the unverified-JWT fallback; gate API-key query param.~~ **DONE** (entire P2 set: RequireScope bypass, JWT fallback, API-key query gate, SQLi allow-lists, SecureSerializer gadget gate, CSRF config-gated fail-closed, signed-URL key-reuse note).

DB pass (was deferred -- also complete):

5. ~~**P1 #3** -- soft-delete cache keying, connection-pool reset, `getConditionsArray()` duplicate-column collapse.~~ **DONE** (incl. range UPDATE/DELETE support + pool session reset).
6. ~~**P1 #4 / P2 SQLi sinks** -- PathGuard on `storeContent()`; operator/identifier allow-lists on the developer-facing query methods (`has()`/JSON `$path`/JOIN/`having`).~~ **DONE.**

**Nothing remaining.** Net: **all four P1s, the full P2 set, S1/S2, the container P3s, and the two final follow-ups (Router object-handler key, `FlysystemStorage` read/delete PathGuard) are closed.** The entire review is resolved.

---

*Read-only review -- no source was modified by the review itself. The P1, P2, and S1/S2 fixes (and CHANGELOG `[Unreleased]` entries) were applied in subsequent working sessions on `dev`; the status sections were added/updated 2026-06-11. Findings cite `file:line` against `e9b222a`; line numbers may drift with the fixes and subsequent edits.*

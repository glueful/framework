# Types-First DTO I/O — Phase D: Message-Aware Responses + Reference Adoption

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Make typed response DTOs preserve custom envelope messages (a small additive `HasResponseMessage` contract), then adopt the typed-DTO I/O convention across the framework's route-wired controllers as worked reference examples — migrating only where the current envelope/message/status can be preserved **byte-identically**, and documenting every other route-wired method as an explicit "left manual + reason" boundary.

**Architecture:** Task 1 adds `HasResponseMessage` so a `ResponseData`/`CollectionResponse`/`PaginatedResponse` can carry its own message (defaulting to today's strings, so nothing existing changes). Task 2 records a complete route-method inventory with a per-method decision. Tasks 3–7 are behavior-preserving migrations driven by **characterization tests** (capture the exact current response first; prove it stays identical after). Task 8 documents the convention boundary.

**Tech Stack:** PHP 8.3+, Glueful framework (NOT Laravel), PHPUnit, PSR-12. Builds on Phases A–C2.

**Why this revision (vs. a naive "migrate everything"):**
- Today's `ResponseData` auto-envelope **hard-codes the message** (`'Success'`/`'Created successfully'`), but every real endpoint uses a custom message — so response-DTO migration is impossible byte-identically *until* Task 1 lands.
- `RequestDataHydrator` binds JSON keys to constructor params by **exact name** (no snake↔camel) — so request DTOs MUST use snake_case property names (`refresh_token`, `is_active`).
- Most route-wired methods **cannot** become DTOs (polymorphic table bodies, multipart/base64 input, binary/stream serving, header-based auth, free-form blobs, bare non-envelope probes). The inventory (Task 2) makes each decision explicit.
- `WebhookController` exists but its routes are **NOT registered** (`RouteManifest.php`), so it's not a route-wired reference until wired — handled as an explicit, flaggable opt-in (Task 7).

**Conventions for every task:** branch `dev`; **NO `Co-Authored-By`**; never stage `CLAUDE.md`. Per-task gates: targeted `phpunit` + the named suite green; `vendor/bin/phpcs <changed files>` clean; `vendor/bin/phpstan analyse <changed files> --level=6 --no-progress` no NEW errors (ignore the PHPStan 2.x banner). Line numbers are from HEAD — verify before editing.

---

## Task 1: `HasResponseMessage` — message-aware typed responses

**Files:**
- Create: `src/Http/Contracts/HasResponseMessage.php`
- Modify: `src/Routing/Router.php` — `normalizeResponse()` (the `PaginatedResponse`/`CollectionResponse`/`ResponseData` branches, ~lines 1087–1125)
- Test: `tests/Unit/Routing/ResponseMessageTest.php`

**Verified current branches (post-C2):**
```php
if ($result instanceof \Glueful\Http\Responses\PaginatedResponse) {
    return ApiResponse::paginated($this->serializeResponseItems($result->items), $result->total, $result->page, $result->perPage);
}
if ($result instanceof \Glueful\Http\Responses\CollectionResponse) {
    $data = $this->serializeResponseItems($result->items);
    return match ($successStatus) {
        200 => ApiResponse::success($data),
        201 => ApiResponse::created($data),
        default => new ApiResponse(['success' => true, 'message' => 'Success', 'data' => $data], $successStatus),
    };
}
if ($result instanceof \Glueful\Http\Contracts\ResponseData) {
    $data = (new \Glueful\Serialization\ResponseDataSerializer())->toArray($result);
    return match ($successStatus) {
        200 => ApiResponse::success($data),
        201 => ApiResponse::created($data),
        default => new ApiResponse(['success' => true, 'message' => 'Success', 'data' => $data], $successStatus),
    };
}
```
`ApiResponse` is `Glueful\Http\Response`. Signatures: `success(mixed $data, string $message = 'Success', ?SerializationContext = null)`, `created(mixed $data, string $message = 'Created successfully', …)`, `paginated(array $items, int $total, int $page, int $perPage, ?SerializationContext = null, string $message = 'Data retrieved successfully')`.

- [x] **Step 1: Write the failing test** — `tests/Unit/Routing/ResponseMessageTest.php`. Build a Router (mirror `tests/Unit/Routing/ResponseDataNormalizationTest.php`'s container/dispatch harness). Fixtures + cases:
```php
// A plain ResponseData (no HasResponseMessage) — message stays the default.
final class RmPlainData implements \Glueful\Http\Contracts\ResponseData
{
    public function __construct(public int $id)
    {
    }
}
// A message-aware ResponseData — carries a custom message via a PRIVATE promoted
// property (private => NOT serialized into `data` by ResponseDataSerializer).
final class RmMessagedData implements
    \Glueful\Http\Contracts\ResponseData,
    \Glueful\Http\Contracts\HasResponseMessage
{
    public function __construct(
        public int $id,
        private string $message = 'Custom message',
    ) {
    }
    public function responseMessage(): string
    {
        return $this->message;
    }
}
```
Controller methods returning each (one at 200, one with `#[ResponseStatus(201)]`), plus a `CollectionResponse` and `PaginatedResponse` wrapping message-aware items if you extend those (see Step 3). Assertions:
- `RmPlainData` → body `message === 'Success'` (200) / `'Created successfully'` (201) — **unchanged default**.
- `RmMessagedData` → body `message === 'Custom message'`; and `data === ['id' => …]` (NO `message` key inside `data` — proves the private message isn't serialized).
- Default-behavior regression: an existing `ResponseData` (no interface) is byte-identical to before.
Run → FAIL (message-aware cases get `'Success'`).

- [x] **Step 2: Create the contract** — `src/Http/Contracts/HasResponseMessage.php`:
```php
<?php

declare(strict_types=1);

namespace Glueful\Http\Contracts;

/**
 * Opt-in companion to {@see ResponseData}: a returned response object that also
 * implements this interface supplies its own envelope `message`, replacing the
 * default ('Success' / 'Created successfully' / 'Data retrieved successfully').
 * Implementing classes typically store the message in a PRIVATE property so it is
 * not serialized into the `data` payload by {@see \Glueful\Serialization\ResponseDataSerializer}.
 */
interface HasResponseMessage
{
    public function responseMessage(): string;
}
```

- [x] **Step 3: Thread the message in `normalizeResponse()`** — read it once and pass it through each branch, defaulting to the existing strings:
```php
        $message = $result instanceof \Glueful\Http\Contracts\HasResponseMessage
            ? $result->responseMessage()
            : null;

        if ($result instanceof \Glueful\Http\Responses\PaginatedResponse) {
            return ApiResponse::paginated(
                $this->serializeResponseItems($result->items),
                $result->total,
                $result->page,
                $result->perPage,
                null,
                $message ?? 'Data retrieved successfully',
            );
        }
        if ($result instanceof \Glueful\Http\Responses\CollectionResponse) {
            $data = $this->serializeResponseItems($result->items);
            return match ($successStatus) {
                200 => ApiResponse::success($data, $message ?? 'Success'),
                201 => ApiResponse::created($data, $message ?? 'Created successfully'),
                default => new ApiResponse(['success' => true, 'message' => $message ?? 'Success', 'data' => $data], $successStatus),
            };
        }
        if ($result instanceof \Glueful\Http\Contracts\ResponseData) {
            $data = (new \Glueful\Serialization\ResponseDataSerializer())->toArray($result);
            return match ($successStatus) {
                200 => ApiResponse::success($data, $message ?? 'Success'),
                201 => ApiResponse::created($data, $message ?? 'Created successfully'),
                default => new ApiResponse(['success' => true, 'message' => $message ?? 'Success', 'data' => $data], $successStatus),
            };
        }
```
(Compute `$message` AFTER the `Response`/`string` passthroughs but before these three branches.) Note: the reflect generator already documents `message` as `{type: string}` with no fixed value, so NO generator change is needed — a custom message doesn't alter the schema.

- [x] **Step 4: Run → pass.**

- [x] **Step 5: Regression gate** — FULL `tests/Unit/Routing` + `tests/Unit/Serialization` suites green (Task 1 must leave every existing default-message response identical — quote counts). `vendor/bin/phpcs src/Http/Contracts/HasResponseMessage.php src/Routing/Router.php`; `vendor/bin/phpstan analyse src/Http/Contracts/HasResponseMessage.php src/Routing/Router.php --level=6 --no-progress`.

- [x] **Step 6: CHANGELOG + commit** — under `## [Unreleased] → ### Added`: "`Glueful\Http\Contracts\HasResponseMessage` — a `ResponseData`/`CollectionResponse`/`PaginatedResponse` may now supply its own envelope `message` (defaults unchanged when not implemented)."
```bash
git add src/Http/Contracts/HasResponseMessage.php src/Routing/Router.php tests/Unit/Routing/ResponseMessageTest.php CHANGELOG.md
git commit -m "Add HasResponseMessage so typed responses can carry a custom message"
```

---

## Task 2: Route-method inventory (the survey)

**Files:** Create `docs/implementation-plans/phase-d-controller-inventory.md` (the authoritative per-method decision table; referenced by the migration tasks). No code.

- [x] **Step 1: Record the inventory** verbatim below (verified against HEAD). Each route-wired method gets exactly one decision. `Req`=request→`RequestData`, `Resp`=response→`ResponseData`, `Page`=→`PaginatedResponse`, `—`=leave manual (+reason).

**AuthController** (`src/Controllers/AuthController.php`, routes `routes/auth.php`):
| Method | Decision | Notes |
|---|---|---|
| `login` | `Req` only | body `username,password,remember?,provider?`; response polymorphic (2FA/shaper) → stays manual |
| `refreshToken` | `Req` + `Resp` | body `refresh_token`; response `{access_token,refresh_token,token_type,expires_in,user}` msg "Token refreshed successfully" |
| `logout` | — | no body (Authorization header) |
| `validateToken` | — | no body (header) |
| `refreshPermissions` | — | no body (header) |
| `csrfToken` | — | no body; utility |

**HealthController** (`src/Controllers/HealthController.php`, routes `routes/health.php`):
| Method | Decision | Notes |
|---|---|---|
| `readiness` | `Resp` | envelope `{status,timestamp,checks}` msg "Service is ready" / 503 path stays `Response::error` |
| `database` | `Resp` | envelope `{status,message,driver,migrations_applied,connectivity_test}` |
| `liveness`,`startup` | — | **bare** `new Response(['status'=>'ok'])` — NOT the envelope; reshaping would break |
| `index`,`cache`,`detailed`,`queue`,`middleware`,`responseApi` | — (optional later) | envelope + stable, but free-form/large; out of scope for the worked set |

**ResourceController** (`src/Controllers/ResourceController.php`, routes `routes/resource.php`):
| Method | Decision | Notes |
|---|---|---|
| `index` | `Page` | `successWithMeta` is the FLAT pagination shape (matches `PaginatedResponse`) |
| `show` | `Resp` | single record; but data is dynamic table columns → see Step-2 caveat |
| `destroy` | `Resp` | stable `{affected,success,message}` |
| `store`,`update`,`updateBulk`,`destroyBulk` | — | **polymorphic** (arbitrary table columns) + password-hash branch |

**UploadController** (`src/Controllers/UploadController.php`, routes `routes/blobs.php`):
| Method | Decision | Notes |
|---|---|---|
| `info` | `Resp` | `{uuid,url,mime_type,size,created_at,native_url?}` msg "Blob metadata" |
| `signedUrl` | `Resp` | `{uuid,signed_url,expires_in,expires_at,native_url?}` msg "Signed URL generated" |
| `delete` | `Resp` | `{uuid}` msg "Blob deleted" |
| `upload` | — | **multipart + base64** (not a JSON body) |
| `show` | — | **binary/stream** (`BinaryFileResponse`/`StreamedResponse`) |

**DocsController** (`routes/docs.php`): `index`, `openapi` → — (binary file serving).

**WebhookController** (`src/Api/Webhooks/Http/Controllers/WebhookController.php`): **routes NOT registered** — see Task 7. If wired: `createSubscription`/`updateSubscription`→`Req`; `getSubscription`/`deleteSubscription`/`rotateSecret`/`testSubscription`/`getSubscriptionStats`/`getDelivery`/`retryDelivery`→`Resp`; `listSubscriptions`/`listDeliveries`→ — (NESTED `{data:{subscriptions,pagination}}`, not the flat `CollectionResponse` shape).

**Not route-wired (documented, not migrated):** `ConfigController` (admin/CLI), `MetricsController` (internal), `ExtensionsController` (no registered routes found).

- [x] **Step 2: Caveat for `Resp` on dynamic single-records** (`ResourceController::show`): its `data` is arbitrary table columns, so a fixed-property `ResponseData` can't mirror it. Mark `show` as **— (dynamic columns)** unless a generic passthrough DTO is used; prefer documenting it as a boundary. (Recorded here so Task 4 doesn't attempt it.)

- [x] **Step 3: Commit** `git add docs/implementation-plans/phase-d-controller-inventory.md && git commit -m "Add Phase D route-method inventory with per-method migration decisions"`

---

## Migration recipe (Tasks 3–7)

Each migration is the same TDD sequence (a behavior-preserving change):
1. **Characterization test FIRST:** dispatch the real route against the **unmodified** controller; assert the EXACT current body (status + decoded JSON incl. the custom `message`). Run → it PASSES (captures the contract).
2. **Create DTO(s):** `RequestData` with **snake_case** promoted public props (exact JSON keys) + `#[Rule]` from the current manual checks; and/or a `ResponseData` whose **public typed props mirror the current `data` keys exactly**, implementing `HasResponseMessage` with a **private `$message`** promoted prop returning the endpoint's current message string.
3. **Migrate the method:** accept the `RequestData` param (coexists with `{id}` route args — verified in `Router::resolveMethodParameters()`); return the `ResponseData`/`PaginatedResponse`; preserve status (`#[ResponseStatus(201)]` where 201).
4. **GREEN + 422:** characterization test passes byte-identically; ADD a test that an invalid body now yields **422** (was a manual **400**) — the one intentional change; update any existing test asserting the old 400 and report it.
5. Gates + commit.

Example message-carrying `ResponseData`:
```php
final class BlobDeletedData implements
    \Glueful\Http\Contracts\ResponseData,
    \Glueful\Http\Contracts\HasResponseMessage
{
    public function __construct(
        public readonly string $uuid,
        private readonly string $message = 'Blob deleted',
    ) {
    }
    public function responseMessage(): string
    {
        return $this->message;
    }
}
```

---

## Task 3: Auth — `RefreshTokenData` (request) + typed refresh response

**Files:** `src/Auth/DTOs/RefreshTokenData.php` (or `Glueful\Controllers\DTOs` — match the controller's namespace neighborhood; verify), `src/Auth/DTOs/RefreshedTokenData.php`; modify `AuthController::refreshToken` (~363) and `login` (~122, request only); test `tests/Integration/ReferenceAdoption/AuthApiTest.php`.

- [x] **Step 1: Characterization tests** for `POST /auth/login` (valid creds, via the in-memory user-provider seam in `tests/Integration/Auth/AuthenticationServiceSeamTest.php`) and `POST /auth/refresh-token` (valid token) — assert current body+status. Run → PASS.
- [x] **Step 2: DTOs** —
```php
final class RefreshTokenData implements \Glueful\Validation\Contracts\RequestData
{
    public function __construct(
        #[\Glueful\Validation\Attributes\Rule('required|string')]
        public readonly string $refresh_token,
    ) {
    }
}
```
`LoginData` (request only) with snake_case `username,password,provider?,remember?` (`#[Rule('required|string')]` on username/password, `string`/`boolean` on the optionals). `RefreshedTokenData` (`ResponseData`+`HasResponseMessage`, private `$message='Token refreshed successfully'`) mirroring `{access_token,refresh_token,token_type,expires_in,user}` — VERIFY the exact keys/`user` shape from the controller before finalizing; if `user` is a nested dynamic array, type it as `array<string,mixed> $user` (serializer passes arrays through).
- [x] **Step 3: Migrate** `login(LoginData $input)` (keep the 2FA/shaper RESPONSE untouched — request only) and `refreshToken(RefreshTokenData $input): RefreshedTokenData`.
- [x] **Step 4: GREEN + 422** (missing password / missing refresh_token → 422). **Run the entire `tests/Integration/Auth` suite** (auth hot path) + update any test asserting the old error status.
- [x] **Step 5: Gates + commit** `git commit -m "Adopt typed request DTOs in AuthController login/refresh (reference example)"`

---

## Task 4: Upload — typed responses for `info` / `signedUrl` / `delete`

**Files:** `src/Controllers/DTOs/BlobInfoData.php`, `SignedUrlData.php`, `BlobDeletedData.php`; modify `UploadController::info`/`signedUrl`/`delete`; test `tests/Integration/ReferenceAdoption/UploadResponseTest.php`.

- [x] **Step 1: Characterization tests** for `GET /blobs/{uuid}/info`, `POST /blobs/{uuid}/signed-url`, `DELETE /blobs/{uuid}` (seed a blob via existing upload-test helpers) — assert current bodies+messages+statuses. Run → PASS.
- [x] **Step 2: DTOs** — three `ResponseData`+`HasResponseMessage` classes mirroring the exact keys (`BlobInfoData`: `{uuid,url,mime_type,size,created_at,native_url?}` msg "Blob metadata"; `SignedUrlData`: `{uuid,signed_url,expires_in,expires_at,native_url?}` msg "Signed URL generated"; `BlobDeletedData`: `{uuid}` msg "Blob deleted"). Nullable `native_url` via a nullable prop (serializer includes nulls / skips uninitialized — verify which, and match the current body: if the current body OMITS `native_url` when absent, only set it when present and make the prop uninitialized-when-absent or use a custom `toArray()`).
- [x] **Step 3: Migrate** the three methods to return the DTOs (no `#[ResponseStatus]` — all 200).
- [x] **Step 4: GREEN** (these are read/delete — no request body, so no 422 to add; just byte-identical).
- [x] **Step 5: Gates + commit** `git commit -m "Adopt typed response DTOs for UploadController info/signedUrl/delete (reference example)"`

---

## Task 5: Resource — `index` → `PaginatedResponse`; `destroy` → typed response

**Files:** `src/Controllers/DTOs/ResourceDeletedData.php`; modify `ResourceController::index`/`destroy`; test `tests/Integration/ReferenceAdoption/ResourceApiTest.php`.

- [x] **Step 1: Characterization tests** for `GET /data/{table}` (seed a small table or stub the repository as existing resource tests do) and `DELETE /data/{table}/{uuid}` — assert current `successWithMeta` body + the destroy `{affected,success,message}` body. Run → PASS.
- [x] **Step 2: Decide `index`** — compare `successWithMeta`'s exact keys to `PaginatedResponse`'s flat envelope (`current_page,per_page,total,total_pages,has_next_page,has_previous_page`). If identical → migrate `index` to `return new PaginatedResponse($rows, $page, $perPage, $total)` (items are dynamic arrays — passed through) and add a `HasResponseMessage` wrapper only if `successWithMeta`'s message differs from `PaginatedResponse`'s default. If the keys DIFFER (e.g. `successWithMeta` lacks `has_next_page`/`has_previous_page`) → **do NOT reshape**; leave `index` manual and record why (the characterization test stays as the as-is contract). Pick based on the actual keys.
- [x] **Step 3:** `destroy` → `ResourceDeletedData` (`ResponseData`+`HasResponseMessage`, `{affected:int,success:bool,message:string}`-mirroring... note the data itself contains a `message` key — keep it as a public prop in `data` AND set the envelope message via `responseMessage()`; they're distinct). Keep characterization green.
- [x] **Step 4: Gates + commit** `git commit -m "Adopt PaginatedResponse/typed response in ResourceController index/destroy (reference example)"`

---

## Task 6: Health — `readiness` + `database` typed responses

**Files:** `src/Controllers/DTOs/ReadinessData.php`, `DatabaseHealthData.php`; modify `HealthController::readiness`/`database`; test `tests/Integration/ReferenceAdoption/HealthDtoTest.php`.

- [x] **Step 1: Characterization tests** for `GET /health/ready` (both 200 and the 503 path) and `GET /health/database` — assert current bodies+messages+statuses. Run → PASS. (Do NOT touch `liveness`/`startup` — they return bare non-envelope responses; reshaping breaks them. Record that in the test docblock.)
- [x] **Step 2: DTOs** mirroring the envelope `data` exactly, `HasResponseMessage` with the current messages ("Service is ready" / "Database health check completed"). The 503 error path stays `Response::error(...)` (a Symfony Response passthrough — only the success path returns the DTO).
- [x] **Step 3: Migrate** the success path of each to return the DTO; keep the 503 branch as `Response::error(...)`. Characterization tests green.
- [x] **Step 4: Gates + commit** `git commit -m "Adopt typed responses for health readiness/database (reference example)"`

---

## Deferred (NOT in Phase D): WebhookController adoption

`WebhookController`'s routes are **not registered** today. Wiring them creates new public endpoints with auth expectations, an OpenAPI surface, and compatibility implications — a product/API-surface decision, not reference-adoption cleanup. It is therefore **out of scope for Phase D** and deferred to its own spec (which would: register the webhook routes, then adopt `CreateWebhookSubscriptionData`/`UpdateWebhookSubscriptionData` request DTOs + a `WebhookSubscriptionData` response DTO, snake_case props, `formatSubscription()`-mirroring shape). Phase D's reference story is complete via Tasks 3–6.

---

## Task 8: Wrap-up — boundary docs + CHANGELOG + full suite

**Files:** `docs/RESPONSE_DTOS.md`, `docs/REQUEST_DTOS.md`, `CHANGELOG.md`.

- [x] **Step 1: Docs** — a "Reference adoption & the convention boundary" section: document `HasResponseMessage` (with the private-`$message` pattern), the migrated worked examples (auth refresh, upload info/signed/delete, resource index/destroy, health readiness/database), and — citing the Task 2 inventory — **where the convention deliberately does NOT apply and why** (polymorphic table bodies, multipart/base64, binary/stream, header-only auth, bare probes, free-form blobs, unregistered/admin controllers). This boundary IS the deliverable.
- [x] **Step 2: CHANGELOG** — `### Changed`: migrated endpoints' validation error status moved **400→422** (call out exactly which); `### Added`: the typed-DTO reference examples + boundary guide.
- [x] **Step 3: Full suite** `vendor/bin/phpunit tests/Unit tests/Integration` green; `composer phpcs` clean. Quote totals.
- [x] **Step 4: Commit** `git commit -m "Document typed-DTO reference adoption + convention boundary (Phase D wrap-up)"`

---

## Self-Review

**Reviewer defects fixed:** (P1 messages) Task 1 `HasResponseMessage` makes response-DTO migration byte-identical; the recipe uses it via a private `$message`. (P1 snake_case) all `RequestData` snippets use exact-name snake_case props (`refresh_token`, `is_active`). (P1 "all methods") Task 2 is a COMPLETE per-method inventory with an explicit decision (migrate or leave-manual+reason) for every route-wired method; the framing is "survey + targeted migrations," not "migrate everything." (P2 matrix/Task1) a single inventory (Task 2) is the source of truth — webhook lists are `—` (nested shape), no contradiction. (P2 vague Health/Extensions) decisions are concrete in the inventory (bare `liveness`/`startup` stay bare; `readiness`/`database` migrate; Extensions not route-wired).

**Byte-identical preservation is now real** because (a) Task 1 lands first, (b) every migration writes a characterization test BEFORE changing code, (c) the only intentional change (400→422 on invalid bodies) is isolated, tested, and CHANGELOG'd.

**Honest scope:** the route-wired surface is dominated by response-DTO opportunities; the genuinely fitting set (Tasks 3–6) is migrated as worked examples, and every non-fitting method is documented as a boundary rather than force-fit. `WebhookController` is explicitly **deferred to its own spec** — wiring its currently-unregistered routes is an API-surface decision, not adoption cleanup.

**Residual verification (flagged inline):** exact `data` keys of `refreshToken`/`formatSubscription`/blob responses + the `user` sub-shape; whether `successWithMeta` matches `PaginatedResponse`'s keys (Task 5 decides from the characterization test); nullable-key presence semantics (`native_url`, `secret`) — match the current body exactly (custom `toArray()` if needed); current line numbers.

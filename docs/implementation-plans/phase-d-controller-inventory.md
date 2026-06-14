# Phase D — Route-Method Inventory & Migration Decisions

Authoritative per-method decision table for the typed-DTO reference adoption (Phase D). One decision per **route-wired** controller action. Verified against HEAD at planning time — re-verify line numbers before editing.

**Legend:** `Req` = migrate request body → `RequestData`; `Resp` = migrate response → `ResponseData` (+ `HasResponseMessage` to preserve the custom message); `Page` = migrate list → `PaginatedResponse`; `—` = leave manual (+ reason). Migrated endpoints are characterization-tested (response byte-identical) except the deliberate **400→422** error-path change when a typed `RequestData` replaces manual body validation.

---

## AuthController — `src/Controllers/AuthController.php` (routes `routes/auth.php`)

| Method | Line | Request body | Current response (msg / status) | Decision |
|---|---|---|---|---|
| `login` | ~122 | `username,password,remember?,provider?` | `LoginResponseShaper` shape (polymorphic: 2FA challenge vs full session) / 200 | **`Req` only** — response is polymorphic, stays manual |
| `refreshToken` | ~363 | `refresh_token` | `{access_token,refresh_token,token_type,expires_in,user}` / "Token refreshed successfully" / 200 | **`Req` + `Resp`** |
| `logout` | ~216 | none (Authorization header) | `Response::success(null, 'Logged out successfully')` / 200 | **—** header-based, no JSON body |
| `validateToken` | ~298 | none (header) | `{user,is_valid}` / "Token is valid" / 200 | **—** header-based |
| `refreshPermissions` | ~261 | none (header) | `{access_token,refresh_token,permissions,updated_at}` / 200 | **—** header-based |
| `csrfToken` | ~243 | none | token data / "CSRF token retrieved successfully" / 200 | **—** utility |

## HealthController — `src/Controllers/HealthController.php` (routes `routes/health.php`)

| Method | Line | Response | Decision |
|---|---|---|---|
| `readiness` | ~146 | envelope `{status,timestamp,checks}` / "Service is ready" / 200; 503 path `Response::error` | **`Resp`** (success path; 503 stays `Response::error`) |
| `database` | ~85 | envelope `{status,message,driver,migrations_applied,connectivity_test}` / 200; 503 path | **`Resp`** (success path) |
| `liveness` | ~134 | **bare** `new Response(['status'=>'ok'])` / 200 | **—** bare non-envelope; reshaping would break |
| `startup` | ~192 | **bare** `new Response(['status'=>'started'])` / 200 | **—** bare non-envelope |
| `index` | ~40 | envelope, large/dynamic checks blob | **—** (out of worked set; free-form) |
| `cache` | ~109 | envelope, stable | **—** (out of worked set; representative covered by `database`) |
| `detailed`,`middleware`,`responseApi`,`queue` | — | envelope, large/dynamic | **—** (out of worked set; free-form/large) |

## ResourceController — `src/Controllers/ResourceController.php` (routes `routes/resource.php`)

| Method | Line | Request / response | Decision |
|---|---|---|---|
| `index` | ~87 | query params; `successWithMeta(data, meta)` flat pagination / "Resource list retrieved successfully" / 200 | **`Page`** *if* `successWithMeta` keys match `PaginatedResponse`'s flat envelope (decide from characterization test; else leave manual) |
| `destroy` | ~286 | path param; `{affected,success,message}` / "Resource deleted successfully" / 200 | **`Resp`** |
| `show` | ~140 | path param; single record = **dynamic table columns** / 200 | **—** dynamic columns (can't statically type) |
| `store` | ~182 | **polymorphic** body (arbitrary columns) + password-hash branch; 201 | **—** polymorphic |
| `update` | ~227 | **polymorphic** body; 200 | **—** polymorphic |
| `updateBulk`,`destroyBulk` | ~490/478 | polymorphic bulk | **—** polymorphic |

## UploadController — `src/Controllers/UploadController.php` (routes `routes/blobs.php`)

| Method | Line | Request / response | Decision |
|---|---|---|---|
| `info` | ~135 | path param; `{uuid,url,mime_type,size,created_at,native_url?}` / "Blob metadata" / 200 | **`Resp`** |
| `signedUrl` | ~210 | query `ttl?`; `{uuid,signed_url,expires_in,expires_at,native_url?}` / "Signed URL generated" / 200 | **`Resp`** |
| `delete` | ~255 | path param; `{uuid}` / "Blob deleted" / 200 | **`Resp`** |
| `upload` | ~55 | **multipart + base64** (not JSON body); `Response::created` / "Upload successful" / 201 | **—** multipart/base64 input (RequestData is JSON-only) |
| `show` | ~160 | **binary/stream** (`BinaryFileResponse`/`StreamedResponse`) | **—** file serving |

## DocsController — `src/Controllers/DocsController.php` (routes `routes/docs.php`)

| Method | Response | Decision |
|---|---|---|
| `index`, `openapi` | `BinaryFileResponse` (file serving) | **—** binary file serving |

---

## Not route-wired (documented, NOT migrated)

- **`WebhookController`** (`src/Api/Webhooks/Http/Controllers/WebhookController.php`) — routes are **not registered** in `RouteManifest`. Wiring them is a public-API-surface decision deferred to its own spec (not adoption cleanup). When wired, its create/update would be `Req` and most singular reads `Resp`; `listSubscriptions`/`listDeliveries` are `—` (nested `{data:{subscriptions,pagination}}`, not the flat `CollectionResponse` shape).
- **`ConfigController`** — admin/CLI, not route-wired.
- **`MetricsController`** — internal, free-form metric blobs.
- **`ExtensionsController`** — no registered routes found.

---

## Phase D worked-migration set (Tasks 3–6)

- **Task 3 (Auth):** `refreshToken` `Req`+`Resp`; `login` `Req` only.
- **Task 4 (Upload):** `info`, `signedUrl`, `delete` → `Resp`.
- **Task 5 (Resource):** `index` → `Page` (if shapes match); `destroy` → `Resp`.
- **Task 6 (Health):** `readiness`, `database` → `Resp` (success path).

Everything marked `—` above is the documented convention boundary (Task 8 wrap-up): `RequestData` is JSON-body-only and types a fixed field set, so polymorphic, multipart, binary, header-based, bare-probe, and free-form endpoints stay manual by design.

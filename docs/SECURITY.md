# Security: SQL Injection & XSS

This document describes how Glueful defends against SQL injection and cross-site scripting (XSS), what is protected automatically, and the few places where you remain responsible.

## TL;DR

| Threat | Default protection | Where you can still go wrong |
|--------|-------------------|------------------------------|
| SQL injection | Parameterized queries + identifier validation — safe by default | The `*Raw()` query-builder methods (see below) |
| XSS | JSON responses served as `application/json` + CSP/security headers + CSRF — safe by default for JSON APIs | Hand-rolled HTML output; trusting `strip_tags` for rich HTML |

---

## SQL Injection

### The query builder is safe by default

Every value flowing through the query builder is sent to the database as a **bound PDO parameter**, never interpolated into the SQL string. The query text contains `?` placeholders and the values travel separately.

```php
$connection->table('users')
    ->where('email', $request->input('email'))   // bound as a parameter
    ->whereIn('role', $roles)                     // each value bound
    ->get();
```

- Statements are prepared and executed in `QueryExecutor::executeStatement()` (`src/Database/Execution/QueryExecutor.php`): bindings are flattened via `ParameterBinder::flattenBindings()` and passed to `PDOStatement::execute($params)` after `PDO::prepare()`. Values are bound by PDO, never concatenated into the SQL text.
- `where`, `whereIn`, `having`, `insert`, `update`, `delete` — all use placeholders for values. You do **not** need to escape input passed to them.

### Identifiers are quoted and validated

Table and column names cannot be bound as PDO parameters, so the framework quotes and validates them instead:

- **Per-driver quoting** via `wrapIdentifier()` — backticks for MySQL (`src/Database/Driver/MySQLDriver.php`), double quotes for PostgreSQL and SQLite.
- **Identifier validation** in `QueryValidator` (`src/Database/Features/QueryValidator.php`). The strictness differs by identifier type:
  - **Table names** (`validateTableName`): rejects `;`, `'`, `"`, `` ` ``, enforces the pattern `[a-zA-Z_][a-zA-Z0-9_]*`, applies a length limit, and (in strict mode) rejects reserved SQL keywords.
  - **Column names** (`validateColumnName`): rejects `;`, `'`, `"`, `` ` ``, and (in strict mode) rejects reserved SQL keywords — but does **not** enforce the full character pattern, and deliberately skips wildcards (`*`) and aggregate expressions like `COUNT(...)`. It is a character/keyword filter, not a strict allowlist.

Still, **do not pass raw user input as a column or table name.** Map it through an allowlist first:

```php
// UNSAFE — user controls the column name
$users = $connection->table('users')->orderBy($request->input('sort'))->get();

// SAFE — validate against a known set
$allowed = ['created_at', 'name', 'email'];
$sort = in_array($request->input('sort'), $allowed, true)
    ? $request->input('sort')
    : 'created_at';
$users = $connection->table('users')->orderBy($sort)->get();
```

### Strict mode (on by default)

`QueryValidator` runs in strict mode by default (`$strictMode = true`). In addition to identifier checks, strict mode:

- Blocks `UPDATE` and `DELETE` statements that have **no `WHERE` clause** (prevents accidental full-table writes).
- Warns on `LIMIT` values over 1000.
- Validates data types of bound values.

Disable it only with a deliberate reason via `setStrictMode(false)`.

### Raw methods — your responsibility

Raw methods exist for expressions the builder can't model. They split into two groups:

**Accept a bindings array — safe when you use it:**

```php
// selectRaw / whereRaw / havingRaw / executeRaw / executeRawFirst
$query->selectRaw('(price * ?) AS total', [$rate]);            // value bound — safe
$query->whereRaw('age > ? AND status = ?', [$age, $status]);   // values bound — safe
$query->havingRaw('COUNT(*) > ?', [$min]);
$connection->executeRaw('SELECT * FROM users WHERE id = ?', [$id]);
```

Never concatenate user input into the SQL string of these methods — pass it through the bindings array instead:

```php
// UNSAFE
$query->whereRaw("age > {$request->input('age')}");

// SAFE
$query->whereRaw('age > ?', [$request->input('age')]);
```

**Take a raw string with NO bindings parameter — never pass user input:**

```php
// orderByRaw(string $expression) has no place to bind values.
// Use only with trusted, static SQL.
$query->orderByRaw('FIELD(status, "active", "pending", "closed")');    // OK — static

// UNSAFE — there is no safe way to interpolate user input here.
// Restructure with a bound where()/having(), or map input through an allowlist.
```

> **Note:** `selectRaw` accepts a bindings array (use `?` placeholders for dynamic values). `orderByRaw` does not — if you need a dynamic value there, it belongs in a bound `where()`/`having()` clause, or must be validated against an allowlist before use. In all cases, bindings cover *values* only, never identifiers, operators, directions, or SQL fragments.

---

## XSS (Cross-Site Scripting)

Glueful is a JSON API framework, so the primary XSS defense is that responses are serialized as JSON and served as `application/json` (not rendered as HTML).

### JSON responses are encoded automatically

API responses are serialized with `json_encode()` and served with a `application/json` content type. A browser does not execute markup in a JSON response delivered with that content type, so JSON API responses are safe in normal use.

- `src/Http/Response.php` encodes with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` (`Response.php:80`).
- API resources serialize through `JsonResource::toJson()` (`src/Http/Resources/JsonResource.php`).

**Important caveat:** PHP's `json_encode()` does **not** escape `<`, `>`, or `&` by default (that requires the `JSON_HEX_TAG` / `JSON_HEX_AMP` / `JSON_HEX_APOS` / `JSON_HEX_QUOT` flags, which are not set here). So a JSON value is **not** safe to drop into an HTML context as-is. The protection comes from the `application/json` content type, not from HTML-escaping the values. If you embed an API value into an HTML page (server-rendered template, or `innerHTML` on the client), you must escape it yourself for that context.

### Security headers & CSP

`SecurityHeadersMiddleware` (`src/Routing/Middleware/SecurityHeadersMiddleware.php`) sets defense-in-depth headers:

- **Content-Security-Policy** with strict / moderate / relaxed profiles, plus optional per-request nonces. Stricter defaults are applied automatically in production.
- **X-Content-Type-Options: nosniff**
- **X-Frame-Options** and **X-XSS-Protection**

Static assets get their own hardened header set via `src/Security/SecurityHeaders.php`. Configure behavior in `config/security.php` (e.g. `CSP_HEADER`, `X-Frame-Options`, HSTS in production).

### CSRF protection

`CSRFMiddleware` (`src/Routing/Middleware/CSRFMiddleware.php`) provides 128-bit tokens, constant-time comparison, double-submit-cookie support, origin/referer validation, and token rotation. Tokens emitted into HTML are escaped with `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.

### Input sanitization

The `Sanitize` validation rule (`src/Validation/Rules/Sanitize.php`) supports `strip_tags`, `trim`, and case operations, and is applied in DTOs (e.g. `UserDTO`, `EmailDTO`). `ValidationHelper::sanitizeString()` strips tags by default.

> `strip_tags()` removes tags but does **not** sanitize attributes or allow a safe subset of HTML. It is not a substitute for a real HTML sanitizer if you accept and re-render rich text.

### If you generate HTML yourself

This is outside the framework's typical path, so you own it:

- Escape any dynamic value with `htmlspecialchars($value, ENT_QUOTES, 'UTF-8')` before placing it in HTML.
- For HTML attributes, escape with `ENT_QUOTES` and quote the attribute.
- For rich user-submitted HTML, use a dedicated sanitizer (e.g. HTMLPurifier) — `strip_tags` is not enough.
- Never echo request input (`$_GET`, `$_POST`, `$_REQUEST`) directly. The vulnerability scanner (`src/Security/VulnerabilityScanner.php`) flags this pattern.

---

## Checklist

- [ ] Pass user values to `where()`, `whereIn()`, `whereRaw('… ?', [$v])` — never string-concatenate them into SQL.
- [ ] Validate any user-controlled column/table/sort name against your own allowlist — the built-in column check is a character/keyword filter, not a strict allowlist.
- [ ] Pass dynamic values in `selectRaw()` via its bindings array (`?` placeholders); never pass user input to `orderByRaw()` (no bindings available).
- [ ] Keep `QueryValidator` strict mode enabled.
- [ ] Return data as JSON (served as `application/json`) rather than hand-built HTML; if you embed JSON values in HTML, escape them for that context.
- [ ] If you must emit HTML, escape with `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.
- [ ] Keep `SecurityHeadersMiddleware` and `CSRFMiddleware` enabled; review `config/security.php` for production.

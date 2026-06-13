# Security Notes: Accepted Trade-offs & Known Limitations

This file records the security trade-offs that were **reviewed and accepted** during
the June 2026 security hardening pass, so future audits don't re-discover them and
the reasoning isn't lost. Each entry says what the limitation is, where it lives,
why it was accepted, and what would close it if the threat model changes.

These are *limitations*, not vulnerabilities: each was weighed against its
exploitability and the cost/regression risk of closing it. If you are deploying
the framework in an environment where one of these assumptions doesn't hold,
the "What would close it" column is the work item.

> For SQL injection and XSS guidance see [SECURITY.md](SECURITY.md).
> For behavioral changes shipped by the hardening pass itself (CORS fail-closed,
> queue payload signing, gated deserialization, SSRF-guarded fetches, log
> redaction), see the CHANGELOG `[Unreleased]` section.

---

## Log redaction

### Multipart request bodies are not scrubbed

- **Where:** `Glueful\Routing\Middleware\RequestResponseLoggingMiddleware::sanitizeBody()`
- **What:** When body logging is enabled (`log_bodies=true`, off by default), JSON
  and `application/x-www-form-urlencoded` bodies are parsed and redacted by
  parameter name; `multipart/form-data` bodies are not parsed and pass through
  raw (truncated at the body size limit, 10 KB by default).
- **Why accepted:** Body logging is off by default; multipart payloads are
  predominantly file uploads, which truncation reduces to a binary prefix; a
  correct multipart parser in the logging hot path is disproportionate to the
  exposure.
- **What would close it:** Parse `multipart/form-data` part headers and redact
  text parts whose field names match `SensitiveParamRedactor` patterns, or
  refuse to log multipart bodies at all.

### Secrets in URL path segments are not redacted

- **Where:** `Glueful\Support\SensitiveParamRedactor::sanitizeUrl()` and every
  caller (request/response logging, exception reporting, auth access logs,
  security-violation listener).
- **What:** Redaction is keyed on parameter *names*, so it covers query strings
  and form/JSON fields. A secret embedded in the path itself —
  `/password-reset/{token}`, `/verify/abc123` — is logged verbatim (including
  the middleware's separate raw `path` field).
- **Why accepted:** Path segments carry no name to match on; heuristic
  entropy-based redaction produces false positives that destroy log usability.
  The framework's own routes don't put bearer-grade secrets in paths.
- **What would close it:** Application-level discipline (prefer one-time POST
  bodies over tokenized GET paths), or a per-route opt-in that masks named
  route parameters (e.g. any param named `token`) before logging.

### Dormant raw-URL logging surfaces

- **Where:** `Glueful\Logging\LogManager::logApiRequest()` (no in-framework
  callers); `Glueful\Http\RequestUserContext::getRequestMetadata()` (stores raw
  `REQUEST_URI` as request metadata, not a log emission).
- **What:** These public APIs would record unredacted URLs if a consumer wires
  them up.
- **Why accepted:** Neither is called by the framework itself; redacting
  metadata that consumers may need intact is a behavioral decision best made at
  the emission point.
- **What would close it:** Route their URL fields through
  `SensitiveParamRedactor` (do this if `logApiRequest()` ever gains a caller).

---

## SSRF / outbound request validation

### PHP's IP filter flags leave fringe ranges open

- **Where:** `Glueful\Http\Client::assertSafeFetchUrl()` and
  `Glueful\Services\ImageSecurityValidator` — both rely on
  `FILTER_VALIDATE_IP` with `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE`.
- **What:** PHP's flags do not reject every non-public range. Known gaps:
  IPv4 CGNAT `100.64.0.0/10`, `192.0.0.0/24`, multicast `224.0.0.0/4`;
  IPv6 NAT64 `64:ff9b::/96` and site-local `fec0::/10`.
- **Why accepted:** The high-value targets (RFC 1918, loopback, link-local /
  cloud metadata `169.254.169.254`) *are* rejected, and resolved-IP pinning
  removes the rebinding window. The residual ranges only matter for
  deployments that actually route them to internal services (CGNAT
  infrastructure, NAT64 gateways).
- **What would close it:** Replace the filter-flag check with an explicit CIDR
  denylist covering the IANA special-purpose registries (IPv4 and IPv6), shared
  by `Client` and `ImageSecurityValidator`.

### Scheme-relative URLs bypass the image validator's external check

- **Where:** `Glueful\Services\ImageSecurityValidator::isExternalUrl()`
- **What:** A scheme-relative URL (`//host/img.png`) parses with no scheme and
  is treated as a local path, skipping host resolution. The media extension's
  own per-hop `assertHostIsPublic()` still covers the actual fetch path.
- **Why accepted:** Core never fetches URLs itself — the only fetcher (the
  `glueful/media` extension) independently re-validates every hop, so the gap
  is in defense-in-depth, not in the exploitable path.
- **What would close it:** Treat any URL with a `host` component as external
  regardless of scheme presence.

---

## Authentication / cryptography

### No JWT clock-skew leeway

- **Where:** `Glueful\Auth\JWTService::decode()`
- **What:** `nbf` and `iat` are rejected even one second in the future, and
  `exp` must be an integer (a float NumericDate — legal per RFC 7519 — is
  rejected).
- **Why accepted:** Every token the framework validates is issued by the same
  application, so issuer and verifier share a clock; strictness costs nothing
  and rejects more forgeries.
- **What would close it:** If externally issued HS256 tokens ever become a
  supported input, add a small configurable leeway (e.g. 30–60 s) to the
  `nbf`/`iat`/`exp` comparisons and accept float NumericDates.

### Weak-key rejection covers only the all-zero key

- **Where:** `Glueful\Encryption\EncryptionService::resolveAndValidateKey()`
- **What:** Key validation requires 32 bytes and rejects the literal all-zero
  key; other low-entropy keys (repeated bytes, ASCII passphrases) are accepted.
- **Why accepted:** Entropy estimation on a 32-byte string is unreliable and a
  strict check would reject legitimately random keys; the all-zero key is the
  one canonical "forgot to configure it" failure worth catching.
- **What would close it:** Nothing fully; documentation (`APP_KEY` must be
  generated, e.g. `base64:` + 32 random bytes) is the real control.

### Encrypted-file envelopes ignore data after the final chunk

- **Where:** `Glueful\Encryption\EncryptionService::decryptStreamTo()`
- **What:** Decryption stops at the secretstream `TAG_FINAL` chunk; bytes
  appended after it are ignored rather than rejected. Truncation and
  reordering *are* detected (final-tag check + secretstream ratchet).
- **Why accepted:** Trailing junk cannot alter the decrypted plaintext or its
  integrity; rejecting it adds a read-to-EOF cost for no confidentiality gain.
- **What would close it:** After the final chunk, attempt one more read and
  fail if it returns data.

---

## Queue / scheduler payload signing

### Signing is inert without `APP_KEY`

- **Where:** `Glueful\Queue\QueuePayloadSigner::shouldSign()`
- **What:** With no `app.key` / `APP_KEY` configured, queue and scheduler
  payloads are neither signed nor required to be signed — only the
  `JobInterface` handler gate applies.
- **Why accepted:** There is no key to sign with; failing closed would break
  every keyless development setup. Production deployments are expected to set
  `APP_KEY` (it also drives encryption).
- **What would close it:** Set `APP_KEY` in production — this is an operator
  checklist item, not a code change.

### `is_enabled` and row scheduling state are outside the signature

- **Where:** `scheduled_jobs` table; envelope binding in
  `QueuePayloadSigner::encodeScheduledParameters()`
- **What:** The signed envelope binds handler class, parameters, row `name`,
  and cron `schedule` — so handler swaps, row cloning, and re-scheduling break
  the HMAC. Toggling `is_enabled` or editing `next_run` directly in the table
  is not signature-protected (re-enabling a disabled job runs it on its
  *signed* schedule with its *signed* parameters).
- **Why accepted:** A signature cannot attest to mutable runtime state, and an
  attacker with database write access has largely won already; the binding
  narrows the blast radius to "run the job as legitimately registered."
- **What would close it:** Treat scheduler enable/disable as a signed
  operation (re-sign the envelope on toggle), at the cost of every legitimate
  toggle path needing the signing key.

---

## Uploads / content scanning

### The upload hazard scan reads a bounded window with literal patterns

- **Where:** `Glueful\Uploader\FileUploader::isFileHazardous()`
- **What:** The content scan inspects the first 64 KB of an upload and matches
  case-sensitive literal patterns (so `<?PHP` or `<SCRIPT>` variants placed
  past the window or in a different case are not flagged). The scan can also
  be disabled via `filesystem.security.scan_uploads`.
- **Why accepted:** The scan is a heuristic tripwire, not the security
  boundary — the real controls are the extension/MIME allowlist, finfo-detected
  MIME enforcement, `nosniff` + `Content-Disposition: attachment` on serving,
  and never executing uploads. A bigger window or regex scan adds cost to every
  upload for a control that motivated attackers bypass anyway.
- **What would close it:** Case-insensitive matching over the full file (or
  integrate a real scanner, e.g. ClamAV) — only worth it if uploads are served
  from an origin where the serving-side headers don't apply.

### Exotic stored filenames can fail blob downloads

- **Where:** `Glueful\Controllers\UploadController::contentDispositionFor()`
- **What:** Symfony's `HeaderUtils::makeDisposition()` throws for filenames
  containing `%` or non-ASCII bytes, so a blob row with such a stored basename
  would 500 on download instead of serving.
- **Why accepted:** Framework-generated filenames are ASCII
  (`time_hex.ext`); only manually inserted rows hit this, and failing loudly
  on malformed metadata is safer than improvising a header.
- **What would close it:** Catch the exception and fall back to a sanitized
  ASCII filename with the original as the RFC 5987 `filename*` parameter.

---

## Routing / authorization

### Permission attributes on interfaces are not inherited

- **Where:** `Glueful\Routing\AttributeRouteLoader::hasGateAttributes()`,
  `Glueful\Permissions\Middleware\GateAttributeMiddleware::attributeInstances()`,
  `Glueful\Routing\Router::handlerHasGateAttributes()`
- **What:** `#[RequiresPermission]` / `#[RequiresRole]` are collected from the
  controller class, its parent classes, and (via reflection on the composing
  class) trait methods — but not from interfaces a controller implements, nor
  from class-level attributes on traits.
- **Why accepted:** PHP attributes are not conventionally inherited through
  interfaces; treating an interface as a gate source would surprise more than
  it protects. Parent-class inheritance (the actual fail-open risk) is covered.
- **What would close it:** Walk `class_implements()` in the same three
  collection sites — do this only if a contract-driven authorization
  convention is adopted deliberately.

---

## Operator checklist (config-dependent protections)

Several protections shipped by the hardening pass are only active when the
deployment provides their configuration:

| Setting | Protects | Without it |
| --- | --- | --- |
| `APP_KEY` | Queue/scheduler payload signing, encryption | Signing inert (handler gate still applies) |
| `TRUSTED_PROXIES` | Real client IPs behind load balancers | Rate limiting collapses to one bucket per proxy; IP logs show the proxy |
| `CORS_ALLOWED_ORIGINS` | Cross-origin access control | Standalone CORS handler denies all cross-origin requests (fail-closed) |
| `TOKEN_ALLOW_QUERY_PARAM` (default off) | Keeps bearer tokens out of URLs/logs | Enabling it re-opens query-string token exposure |
| `QUEUE_REQUIRE_SIGNED_PAYLOADS` (default on) | Rejects unsigned queue rows | Disable only temporarily while draining pre-signing payloads |

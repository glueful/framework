# Developer Experience & Architecture Review (Glueful Framework)

Date: 2026-01-29
Scope: Developer experience and architecture (high-level review)

## Findings

- Medium: CORS behavior/config is split across two implementations with different config keys, which can lead to inconsistent runtime behavior and developer confusion. Router reads `config('cors')` while `Http\Cors` reads `config('security.cors.*')`, and the security config defines CORS under `security.cors`. If `config/cors.php` is not present, Router falls back to defaults, ignoring `security.cors`. `src/Routing/Router.php:387-415`, `src/Http/Cors.php:90-131`, `config/security.php:60-79`.
- Medium: Extension service registration guidance appears fragmented across docs/implementation plans vs runtime base class. The runtime `Glueful\Extensions\ServiceProvider` only defines `services()` for compile-time DI, while docs describe `defs()` and DSL-based registration patterns that are marked draft. This doc drift can mislead extension authors. `src/Extensions/ServiceProvider.php:23-41`, `docs/implementation_plans/EXTENSIONS_CONTAINER_INTEGRATION.md:13-56`, `docs/service_registration_recommendations.md:1-33`.
- Medium: Heavy reliance on global state during boot (e.g., `$GLOBALS['container']`, `$GLOBALS['config_loader']`, `$GLOBALS['framework_booting']`) makes multi-app embedding, parallel tests, and long-running processes harder to reason about. This is an architectural DX risk for advanced integrations. `src/Framework.php:86-143`, `src/Framework.php:168-208`.
- Low: Route prefixing for application routes is manual and easy to miss. The API URL guide expects application routes to wrap with `api_prefix()`, but this is not enforced in the router, so forgetting it produces inconsistent public URLs. `docs/API_URLS.md:53-76`.
- Low: Extension static asset serving defines its own security headers independently of the security headers middleware, which can cause drift in defaults or duplication when apps also attach `security_headers` middleware. `src/Extensions/ServiceProvider.php:133-205`.

## Status updates

- CORS split: addressed by consolidating `Http\Cors` to read `config('cors')` (fallback to `security.cors` if needed). `src/Http/Cors.php`, `config/cors.php`.
- Extension service registration doc drift: addressed by marking DSL/defs docs as proposals and clarifying current runtime support. `docs/implementation_plans/EXTENSIONS_CONTAINER_INTEGRATION.md`, `docs/service_registration_recommendations.md`.
- Route prefix DX warning: dev‑mode warning added for unprefixed app routes (opt‑in via `app.warn_unprefixed_routes`). `src/Routing/Router.php`, `src/Routing/RouteManifest.php`, `config/app.php`.
- Extension static asset headers drift: addressed by centralizing defaults in a shared helper. `src/Security/SecurityHeaders.php`, `src/Extensions/ServiceProvider.php`.

## Analysis & recommendations

1) CORS implementation split (Medium)
- Valid concern. There are multiple sources of truth today (Router via `config/cors.php`, `Http\Cors` via `security.cors`, plus Router defaults).
- Recommendation: consolidate on a single source. Lowest-risk path is to have `Http\Cors` read `config('cors')`, then consider deprecating `Http\Cors` if it’s redundant.

2) Extension service registration doc drift (Medium)
- Valid concern. Docs describe patterns that do not exist in runtime code.
- Recommendation: align docs with the current `services()` implementation and clearly mark any future DSL/defs ideas as proposed.

3) Global state during boot (Medium)
- Architectural debt that impacts multi-app embedding, parallel tests, and long-running workers.
- Recommendation: document as a known limitation now; plan refactor (application instance owning container/config) for a major version.

4) Route prefixing not enforced (Low)
- DX issue rather than security. Easy to forget `api_prefix()` in app routes.
- Recommendation: add a dev-mode warning when routes are outside the expected prefix. Avoid auto-prefixing unless you want a breaking change.

5) Extension static asset security headers (Low)
- Risk of drift/duplication with middleware defaults.
- Recommendation: extract shared header defaults to a helper and reuse in extension asset handling and middleware.

## Summary

| Finding | Severity | Effort | Recommendation |
|---|---|---|---|
| CORS split | Medium | Medium | Consolidate on `config/cors.php` as source of truth |
| Doc drift | Medium | Low | Update docs to match runtime (`services()`) |
| Global state | Medium | High | Document limitation; defer refactor |
| Route prefix | Low | Low | Add dev-mode warning |
| Asset headers | Low | Low | Extract shared helper for defaults |

## Open questions / assumptions

- Do you intend to keep both CORS paths (Router-level + `Http\Cors`) or consolidate into one? If both remain, do you want a clear precedence in docs and config?
- Should extension authoring docs be trimmed to only supported APIs (e.g., `services()`), moving `defs()/DSL` to a separate “planned” section?

## Testing gaps

- Verify Router CORS behavior when only `security.cors.*` is configured (no `config/cors.php`).
- Extension author workflow documentation vs actual runtime support (services/defs/DSL).

## Short overview (strengths)

- Clear boot phases and separation of Framework vs Application responsibilities.
- Extension architecture is cleanly scoped with compile-time DI + runtime hooks.
- Docs for ORM/resources/config URL structure are solid and example-rich.

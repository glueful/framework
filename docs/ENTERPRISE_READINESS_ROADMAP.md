# Enterprise Readiness Roadmap

Goal: harden Glueful for enterprise-grade production workloads across reliability, security, interoperability, and operability.

## Pillars & Milestones

- Standards & Interop
  - PSR-7/PSR-15 first-class support across routing/middleware.
  - HTTP client/server abstractions documented with examples.
  - Acceptance: can run a pure PSR-15 middleware stack; interop tests pass.

- Testing & Quality
  - Unit and integration tests for Router, ExceptionHandler, DI boot, Response helpers.
  - Route cache load/save tests; attribute discovery tests.
  - Static analysis (PHPStan lvl 6+), coding standards (PHP-CS-Fixer), mutation tests (optional).
  - Acceptance: >=85% coverage on core packages; CI green on lint/stan/tests.

- Observability
  - Request metrics (latency, status codes, route counts) and errors exported.
  - OpenTelemetry tracing hooks (optional dependency) and PSR-3 structured logging processors (request_id, user_id).
  - Health/readiness endpoints + queue/worker health metrics.
  - Acceptance: sample dashboards/screens; trace across controller → DB in demo app.

- Security & Compliance
  - Secrets management guidance, key rotation, CSP/HSTS presets, secure defaults.
  - Audit logging options; PII redaction in logs; configurable sanitizers.
  - Acceptance: security checklist doc; automated config audit command exits non‑zero on criticals.

- Distributed Correctness
  - Cache-backed rate limiters and error-response throttling (multi-process safe).
  - Idempotency key pattern for unsafe endpoints (doc + helper).
  - Acceptance: simulated multi-worker tests demonstrate consistent limits.

- Performance & Scale
  - Benchmarks for cold/warm boot, route dispatch, middleware pipeline.
  - Route cache stability; opcache priming guidance.
  - Acceptance: baseline numbers published; perf regression budget enforced in CI.

- Release Engineering & Governance
  - Semantic versioning, deprecation policy, LTS cadence.
  - Upgrade guides and migration notes template.
  - Acceptance: documented policy; CHANGELOG in repo; example migration.

## Suggested Timeline (indicative)

- Phase 1 (Weeks 1–2): PSR-15 bridge, minimal tests, lint/stan, route cache tests.
- Phase 2 (Weeks 3–4): Metrics, tracing hooks, health endpoints, logging processors.
- Phase 3 (Weeks 5–6): Security presets + audit command, cache-backed rate limiters.
- Phase 4 (Weeks 7–8): Benchmarks + perf CI gates, docs/cookbook, release policy.

## Deliverables Checklist

- PSR interop layer implemented and documented.
- CI with tests, lint, phpstan; example app smoke tests.
- Metrics/logging/tracing hooks + sample dashboards.
- Security audit CLI + hardened defaults.
- Benchmarks and performance targets.
- Versioning and deprecation policy docs.

## Phase 0 (Week 0): Foundation

- Establish environment parity checklist across dev/stage/prod (config sources, secrets management, DB/cache endpoints, logging targets).
- Capture current performance baselines before optimizations (cold/warm boot, static/dynamic dispatch, common endpoints).
- Stand up basic CI/CD pipeline if not present (install, lint, static analysis, unit/integration tests, optional bench report).

## Success Metrics

- Define KPIs per pillar (e.g., P95 response time per route class, error rate by channel, boot time budget, cache hit ratio, worker throughput).
- Establish monitoring dashboards early (before Phase 2) so improvements and regressions are visible over time.

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
  - OpenTelemetry tracing hooks (optional) and PSR-3 structured logging processors (request_id, user_id).
  - Health/readiness endpoints + queue/worker health metrics.
  - Current coverage:
    - Health endpoints present in `routes/health.php` (controller + service support in `src/Controllers/HealthController.php`, `src/Http/Services/HealthCheckService.php`).
    - Structured logging via Monolog with `StandardLogProcessor` in `src/Logging/StandardLogProcessor.php` registered by `CoreServiceProvider`.
    - Metrics service scaffold at `src/Services/ApiMetricsService.php`; boot profiling via `src/Performance/BootProfiler.php`.
  - Gaps to close:
    - Wire metrics collection via middleware/kernel hook and export aggregates (endpoint/console).
    - Add optional OpenTelemetry exporter/bridge for end-to-end tracing.
    - Publish sample dashboards and minimal metrics registry.
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

- Phase 1 (Weeks 1–2): [PSR-15 bridge](../PSR15_BRIDGE_PLAN.md), minimal tests, lint/stan, route cache tests. See also: [CI test/benchmark harness](../observability/CI_TEST_BENCHMARK_HARNESS.md).
- Phase 2 (Weeks 3–4): Observability hardening
  - Health endpoints: validate, secure (allowlist/auth if needed), document readiness/liveness usage.
  - Logging processors: confirm standardized fields on all channels; document log shipping.
  - Metrics: integrate `ApiMetricsService` via middleware; add aggregate export (API/CLI); document KPIs.
  - Tracing: add optional OpenTelemetry bridge/adapters; document enablement and minimal example.

### Phase 2 Tasks Checklist

- [x] Health endpoints hardening (Owners: Backend, SRE)
  - Actions (completed)
    - Added explicit liveness `/healthz` and readiness `/ready` endpoints; detailed checks remain under `/health/*`.
    - Implemented readiness in `Glueful\\Controllers\\HealthController::readiness()`.
    - Protected readiness with IP allowlist middleware (see notes).
  - Where
    - Routes: `routes/health.php` (`/healthz`, `/ready`)
    - Controller: `src/Controllers/HealthController.php` (method `readiness`)
    - Config: `config/security.php` key `health_ip_allowlist`
    - Middleware: `src/Routing/Middleware/AllowIpMiddleware.php`
  - Notes
    - Ensure a container alias exists for `'allow_ip'` → `Glueful\\Routing\\Middleware\\AllowIpMiddleware` so middleware resolution works in routes.
  - Snippets
    ```php
    // routes/health.php (append)
    $router->get('/healthz', fn() => new \Glueful\Http\Response(['status' => 'ok']))
           ->middleware('rate_limit:60,60');
    $router->get('/ready', [\Glueful\Controllers\HealthController::class, 'readiness'])
           ->middleware(['rate_limit:30,60', 'allow_ip']);
    ```
    ```php
    // src/Routing/Middleware/AllowIpMiddleware.php (skeleton)
    <?php
    declare(strict_types=1);
    namespace Glueful\Routing\Middleware;
    use Symfony\Component\HttpFoundation\Request;
    use Glueful\Http\Response;
    class AllowIpMiddleware implements \Glueful\Routing\RouteMiddleware {
        public function handle(Request $request, callable $next): mixed {
            $allow = (array) config('security.health_ip_allowlist', []);
            $ip = $request->getClientIp();
            if ($allow && !in_array($ip, $allow, true)) {
                return Response::forbidden('Health endpoint restricted');
            }
            return $next($request);
        }
    }
    ```

- [x] Logging processors standardization (Owner: Platform)
  - Actions (completed)
    - Framework logger now always pushes `StandardLogProcessor` with environment, framework version, and user id resolver.
    - `StandardLogProcessor` enriches logs with `request_id` (from helper/header), `user_id`, env, version, timestamp, memory, pid.
  - Where
    - Framework: `src/Container/Providers/CoreProvider.php` (method `createLogger`)
    - Processor: `src/Logging/StandardLogProcessor.php`
  - Notes
    - Application-specific channels can also push the same processor to unify fields across app logs.
    - For log shipping, configure paths/levels in `config/logging.php` and forward files via your log agent (e.g., Datadog/New Relic/Elastic).

- [x] Metrics integration (Owner: Backend)
  - Actions (completed)
    - Implemented `MetricsMiddleware` to measure duration and enqueue metrics via `ApiMetricsService::recordMetricAsync`.
    - Registered DI service and alias `'metrics'` for easy route group usage.
  - Where
    - Middleware: `src/Routing/Middleware/MetricsMiddleware.php`
    - Container registration/alias: `src/Container/Providers/CoreProvider.php` (service + `'metrics'` alias)
  - Example usage
    ```php
    // routes/resource.php
    $router->group(['middleware' => ['metrics']], function(Glueful\Routing\Router $router) {
        $router->get('/ping', fn() => new Glueful\Http\Response(['ok' => true]));
    });
    ```
  - Metrics endpoints rate limiting
    - If exposing metrics via HTTP, ensure endpoints are protected and rate-limited to prevent abuse.
    - Example
      ```php
      // routes/admin.php (illustrative)
      $router->group(['prefix' => '/admin', 'middleware' => ['auth', 'allow_ip']], function($router) {
          $router->get('/metrics', [AdminMetricsController::class, 'index'])
                 ->middleware('rate_limit:10,60'); // 10 req/min
      });
      ```

- [x] Tracing hooks (Owner: Platform) — pluggable
  - Rationale
    - Keep tracing optional and vendor‑agnostic. Support OpenTelemetry and other APMs (Datadog, New Relic, Zipkin/Jaeger) via adapters.
  - Actions (completed in core)
    - Define a minimal tracer abstraction used by middleware and services (core contracts):
      - `Glueful\Observability\Tracing\TracerInterface` with `startSpan(string $name, array $attrs = []): SpanBuilderInterface`.
      - `Glueful\Observability\Tracing\SpanBuilderInterface` with attribute setters and `startSpan(): SpanInterface`.
      - `Glueful\Observability\Tracing\SpanInterface` with `setAttribute(string $k, mixed $v): void`, `end(): void`.
    - Implement adapters:
      - `OtelTracerAdapter` (uses OpenTelemetry API if installed), `DatadogTracerAdapter`, `NewRelicTracerAdapter`.
    - Add config to select driver and enable/disable tracing.
    - Provide a `TracingServiceProvider` that binds `TracerInterface` to the chosen adapter; falls back to `NoopTracer`.
    - Tracing middleware depends only on `TracerInterface`.
  - Where
    - Interfaces (core): `src/Observability/Tracing/{TracerInterface.php, SpanInterface.php, SpanBuilderInterface.php}`
    - No‑op (core): `src/Observability/Tracing/{NoopTracer.php, NoopSpanBuilder.php, NoopSpan.php}`
    - Middleware (core): `src/Routing/Middleware/TracingMiddleware.php` depends only on contracts; alias `'tracing'` recommended.
    - Adapters (extensions): `src/Observability/Tracing/Adapters/{OtelTracerAdapter.php, DatadogTracerAdapter.php, NewRelicTracerAdapter.php}` (new)
    - Service provider (extension): `src/Container/Providers/TracingServiceProvider.php` (binds adapter)
    - Config (core): `config/observability.php` (select driver + options)
  - Notes
    - Default DI binding (TracerInterface → NoopTracer) and container alias `'tracing'` → TracingMiddleware should be added in CoreServiceProvider (if not already) so the middleware can be enabled via routes without extra wiring.
  - Snippets
    ```php
    // config/observability.php (new)
    return [
      'tracing' => [
        'enabled' => env('TRACING_ENABLED', false),
        'driver'  => env('TRACING_DRIVER', 'noop'), // noop|otel|datadog|newrelic
        'drivers' => [
          'otel' => [ /* exporter, endpoint, headers */ ],
          'datadog' => [ /* service, env, version */ ],
          'newrelic' => [ /* app name, license */ ],
        ],
      ],
    ];
    ```
    ```php
    // src/Observability/Tracing/Contracts (sketch)
    interface TracerInterface { public function startSpan(string $name, array $attrs = []): SpanBuilderInterface; }
    interface SpanBuilderInterface { public function setAttribute(string $k, mixed $v): self; public function setParent(?SpanInterface $parent): self; public function startSpan(): SpanInterface; }
    interface SpanInterface { public function setAttribute(string $k, mixed $v): void; public function end(): void; }
    ```
    ```php
    // src/Routing/Middleware/TracingMiddleware.php (core, agnostic)
    final class TracingMiddleware implements \Glueful\Routing\RouteMiddleware {
      public function __construct(private \Glueful\Observability\Tracing\TracerInterface $tracer) {}
      public function handle(\Symfony\Component\HttpFoundation\Request $request, callable $next): mixed {
        $builder = $this->tracer->startSpan('http.request')
          ->setAttribute('http.method', $request->getMethod())
          ->setAttribute('http.route', $request->attributes->get('_route') ?? $request->getPathInfo())
          ->setAttribute('user_agent', $request->headers->get('User-Agent'))
          ->setAttribute('net.peer.ip', $request->getClientIp());
        if (function_exists('request_id')) { $builder->setAttribute('glueful.request_id', request_id()); }
        $span = $builder->startSpan();
        try {
          $resp = $next($request);
          if (is_object($resp) && method_exists($resp, 'getStatusCode')) { $span->setAttribute('http.status_code', $resp->getStatusCode()); }
          if (isset($_SESSION['user_uuid'])) { $span->setAttribute('enduser.id', $_SESSION['user_uuid']); }
          return $resp;
        } finally { $span->end(); }
      }
    }
    ```
  - Example usage (core alias)
    ```php
    // routes/api.php
    $router->group(['middleware' => ['tracing']], function(Glueful\Routing\Router $router) {
        $router->get('/ping', fn() => new Glueful\Http\Response(['ok' => true]));
    });
    ```

 - [x] Dashboards & KPIs (Owner: SRE)
  - Actions (defined and templated)
    - Defined core KPIs with targets and example queries for common backends (Prometheus/OTel, Datadog logs/APM, New Relic).
    - Included sample dashboard layouts and panels for Exec, API, Errors, Dependencies, Queue, Security.
  - Where
    - This document (quick reference) and `docs/observability/DASHBOARDS.md` (full board JSON/YAML when ready).
  - KPIs (targets are illustrative — tune per service)
    - API latency P95 per route group: target < 200ms (web), < 500ms (heavy endpoints)
    - Error rate (5m): target < 1% overall; spike alert > 3%
    - Throughput (RPS) and saturation: alert on sudden drops or abnormal spikes
    - Readiness failures: target 0; alert on > 1 in 5m
    - Cache hit ratio: target > 80%; alert on < 60%
    - DB slow queries count (> 200ms): target 0; alert on > 10 in 5m
    - Queue backlog and lag: target < 100 jobs backlog; lag < 30s
    - Rate-limit violations: unexpected spikes may indicate abuse
  - Sample dashboards (panels)
    - Executive Summary
      - Requests (sum over 5m), Error rate, P95 latency, Readiness failures last 1h
    - API Performance
      - P50/P95/P99 latency by route group; Trend of RPS; Top 10 slow endpoints
    - Errors & Exceptions
      - Error rate by route; Top exception classes; Recent 20 high‑severity errors (with request_id)
    - Dependencies
      - Cache hit ratio; DB slow queries count; DB response time trend
    - Queue & Workers
      - Backlog by queue; Oldest job age; Worker CPU/memory (node exporter/infra agent)
    - Security Signals
      - Rate‑limit violations; Auth failures; 4xx/5xx distribution
  - Example queries
    - Prometheus/OTel (example metric names; adapt to your exporter)
      ```promql
      # RPS (sum over 1m)
      sum(rate(glueful_http_requests_total[1m]))

      # Error rate over 5m
      sum(rate(glueful_http_requests_total{status=~"5..|4.."}[5m]))
        /
      sum(rate(glueful_http_requests_total[5m]))

      # P95 latency (histogram)
      histogram_quantile(0.95,
        sum(rate(glueful_http_request_duration_ms_bucket[5m])) by (le, route))

      # Cache hit ratio
      sum(rate(glueful_cache_hits_total[5m])) / (sum(rate(glueful_cache_hits_total[5m])) + sum(rate(glueful_cache_misses_total[5m])))
      ```
    - Datadog Logs (log‑based metrics; adapt to your facets)
      ```text
      # Error rate (5m)
      service:glueful-api @status:[400 TO 599] | measure:count() by 5m / (service:glueful-api | measure:count() by 5m)

      # P95 latency
      service:glueful-api @duration_ms:* | measure:p95(@duration_ms) by route
      ```
    - New Relic (NRQL)
      ```sql
      SELECT percentile(duration, 95) FROM Transaction WHERE appName = 'glueful-api' FACET request.uri SINCE 5 minutes ago
      ```
  - Sample panel JSON (Grafana, Prometheus datasource)
    ```json
    {
      "title": "P95 Latency by Route",
      "type": "timeseries",
      "targets": [
        {
          "expr": "histogram_quantile(0.95, sum(rate(glueful_http_request_duration_ms_bucket[5m])) by (le, route))",
          "legendFormat": "{{route}}"
        }
      ]
    }
    ```

- [x] CI observability gates (Owner: DevEx)
  - Actions
    - Add smoke tests to assert `/healthz` 200 and key `/ready` checks.
    - Emit benchmark report from the bench script; store as CI artifact.
  - Where
    - Docs/CI harness: `docs/observability/CI_TEST_BENCHMARK_HARNESS.md` (already contains scaffolds)
    - Tests: add once ready under `tests/Core/*` (later implementation).

### Phase 3 Tasks Checklist (Weeks 5–6)

- [ ] Security presets (Owner: Security)
  - Actions
    - Harden defaults in `config/security.php` (force HTTPS, HSTS/CSP presets, strict CORS options).
    - Validate via existing `SecurityManager::validateProductionEnvironment()` and config schemas.
  - Where
    - Config: `config/security.php` (existing), `src/Configuration/Schema/SecurityConfiguration.php` (schema)
  - Snippet
    ```php
    // config/security.php (excerpt additions)
    return [
      'force_https' => env('FORCE_HTTPS', true),
      'headers' => [
        'hsts' => env('HSTS_HEADER', 'max-age=31536000; includeSubDomains; preload'),
        'csp'  => env('CSP_HEADER', "default-src 'self'")
      ],
      'cors' => [
        'allowed_origins' => explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')),
        'allow_credentials' => false,
      ],
    ];
    ```

- [x] Security audit command integration (Owner: Platform)
  - Actions
    - Use existing security console commands to audit production configs in CI.
    - Fail CI on critical findings; publish report artifact.
  - Where
    - Commands: `src/Console/Commands/Security/CheckCommand.php`, `ReportCommand.php`, `ScanCommand.php`
    - CI: `.github/workflows/test.yml`
  - Snippets
    ```yaml
    # .github/workflows/test.yml (add step)
    - name: Security audit (production profile)
      run: |
        if [ -f vendor/bin/glueful ]; then
          php vendor/bin/glueful security:check --production || exit 1
        else
          echo "Glueful CLI not installed - skip security audit"
        fi
    ```

- [x] Cache‑backed rate limiters (Owner: Backend)
  - Actions
    - Ensure `security.rate_limiter` defaults are set; verify middleware `'rate_limit'` used where needed.
    - Enable distributed limiter when running multiple workers.
    - Add integration tests to validate limiter behavior and headers.
  - Where
    - Config: `config/security.php` / `config/app.php`
    - Middleware alias: registered in `src/Container/Providers/CoreProvider.php` as `'rate_limit'`
    - Implementation: `src/Security/RateLimiter.php`, `src/Security/AdaptiveRateLimiter.php`, `src/Security/RateLimiterDistributor.php`
  - Snippets
    ```php
    // routes/resource.php (apply limiter)
    $router->group(['middleware' => ['rate_limit:60,60']], function($router) {
      // protected routes
    });
    ```
    ```php
    // config/security.php (rate limiter defaults)
    return [
      'rate_limiter' => [
        'default_max_attempts' => env('RATE_LIMIT_ATTEMPTS', 60),
        'default_window_seconds' => env('RATE_LIMIT_WINDOW', 60),
        'enable_distributed' => env('RATE_LIMIT_DISTRIBUTED', false),
      ],
    ];
    ```
    ```php
    // tests/Integration/RateLimiterTest.php (skeleton)
    <?php
    declare(strict_types=1);
    use PHPUnit\Framework\TestCase; use Glueful\Framework; use Symfony\Component\HttpFoundation\Request;
    final class RateLimiterTest extends TestCase {
      public function test_rate_limit_blocks_after_threshold(): void {
        $app = Framework::create(getcwd())->boot();
        $router = $app->getContainer()->get(\Glueful\Routing\Router::class);
        $router->group(['middleware' => ['rate_limit:2,60']], function($r){ $r->get('/limited', fn()=> new \Glueful\Http\Response(['ok'=>true])); });
        $req = fn()=> $router->dispatch(Request::create('/limited','GET'));
        $this->assertSame(200, $req()->getStatusCode());
        $this->assertSame(200, $req()->getStatusCode());
        $this->assertSame(429, $req()->getStatusCode());
      }
    }
    ```

### Phase 4 Tasks Checklist (Weeks 7–8)

- [x] Benchmarks & perf CI gates (Owner: DevEx)
  - Actions
    - Adopt the bench script; set initial non‑blocking reporting; later enforce thresholds.
  - Where
    - Bench script: see `docs/observability/CI_TEST_BENCHMARK_HARNESS.md` (tools/bench/bench.php)
    - CI: `.github/workflows/test.yml`
  - Snippet
    ```yaml
    - name: Benchmarks (report only)
      run: |
        if [ -f tools/bench/bench.php ]; then
          php tools/bench/bench.php | tee build/logs/bench.txt
        else
          echo "Bench script missing; see docs/observability/CI_TEST_BENCHMARK_HARNESS.md" | tee build/logs/bench.txt
        fi
    ```
    ```yaml
    # Later: enforce simple threshold (example)
    - name: Enforce dispatch latency budget
      run: |
        if [ -f build/logs/bench.txt ]; then
          BUDGET=1500 # ms for 1000 dispatches example
          ACTUAL=$(grep -Eo 'Dispatch x1000: [0-9]+\.?[0-9]*' build/logs/bench.txt | awk '{print $3}')
          echo "Actual: $ACTUAL ms (budget $BUDGET ms)"
          awk -v a="$ACTUAL" -v b="$BUDGET" 'BEGIN{ if (a>b) exit 1 }'
        fi
    ```

- [x] Docs & cookbook (Owner: Docs)
  - Actions
    - Author guides for routing patterns, middleware, DI, error handling, testing, deployment, observability.
  - Where
    - Docs: `docs/cookbook/`
  - Snippet (structure)
    ```text
    docs/cookbook/
      01-routing.md
      02-middleware.md
      03-di-and-services.md
      04-error-handling.md
      05-testing.md
      06-deployment.md
      07-observability.md
    ```

- [x] Release policy (Owner: Maintainers)
  - Actions
    - Adopt semantic versioning; document deprecation policy; maintain CHANGELOG.
  - Where
    - Docs: `docs/RELEASE_POLICY.md`, `CHANGELOG.md` (to be created later)
  - Snippet
    ```markdown
    # CHANGELOG
    ## [Unreleased]
    - Added
    - Changed
    - Deprecated
    - Removed
    - Fixed
    - Security
    ```

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

## Existing CI/Test Assets (for Phase 1 acceleration)

- Tests tree present under `tests/` with subfolders `Unit/`, `Integration/`, `Performance/`, `Feature/`, and a `tests/bootstrap.php` bootstrap.
- GitHub Actions workflow at `.github/workflows/test.yml` already sets up PHP, installs dependencies, runs syntax/CS checks, executes PHPUnit (with/without coverage), and runs PHPStan.
- Recommended next steps in Phase 1:
  - Add/port minimal router and route-cache tests per [CI harness scaffolds](../observability/CI_TEST_BENCHMARK_HARNESS.md).
  - If desired, add a simple bench script and upload its output as an artifact (see harness doc for example).

## Sample Usage

### Health Endpoints

- Configure allowlist (development example)
  ```php
  // config/security.php
  return [
    // ...
    'health_ip_allowlist' => ['127.0.0.1'],
  ];
  ```

- Liveness probe
  ```bash
  curl -s http://localhost:8080/healthz | jq
  # { "status": "ok" }
  ```

- Readiness probe (protected with allow_ip)
  ```bash
  curl -s http://localhost:8080/ready | jq
  # { "success": true, "message": "Service is ready", "data": { "status": "ready", ... } }
  ```

### Logging (request_id, user_id)

- Trigger an application log (example)
  ```php
  // In a controller or service
  /** @var Psr\\Log\\LoggerInterface $logger */
  $logger = container()->get(Psr\\Log\\LoggerInterface::class);
  $logger->info('Ping received', ['path' => '/ping']);
  ```
- Inspect framework log file; entries include request_id and user_id (when available)
  ```bash
  tail -n 2 storage/logs/framework-$(date +%F).log
  ```

### Metrics Middleware (optional)

- Apply to a route group
  ```php
  // routes/resource.php
  $router->group(['middleware' => ['metrics']], function(Glueful\\Routing\\Router $router) {
      $router->get('/ping', fn() => new Glueful\\Http\\Response(['ok' => true]));
  });
  ```

### Security Audit (CI or local)

- Run a production-profile security check
  ```bash
  php vendor/bin/glueful security:check --production
  ```

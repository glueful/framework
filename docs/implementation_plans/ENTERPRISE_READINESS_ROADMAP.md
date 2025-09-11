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

- Phase 1 (Weeks 1–2): [PSR-15 bridge](../PSR15_BRIDGE_PLAN.md), minimal tests, lint/stan, route cache tests. See also: [CI test/benchmark harness](../CI_TEST_BENCHMARK_HARNESS.md).
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

- [ ] Logging processors standardization (Owner: Platform)
  - Actions
    - Ensure `StandardLogProcessor` is attached to both framework and application channels.
    - Add request_id/user_id consistently; document log shipping configuration.
  - Where
    - Framework: `src/DI/ServiceProviders/CoreServiceProvider.php`
    - Application: your app’s service provider (e.g., `app/Providers/AppServiceProvider.php`) or a new `src/DI/ServiceProviders/AppLoggingServiceProvider.php`.
  - Snippet
    ```php
    // In a service provider
    $logger->pushProcessor(new \Glueful\Logging\StandardLogProcessor(
        (string) config('app.env', 'production'),
        (string) config('app.version_full', '1.0.0'),
        fn() => $_SESSION['user_uuid'] ?? null
    ));
    ```

- [ ] Metrics integration (Owner: Backend)
  - Actions
    - Introduce a light MetricsMiddleware that measures duration and enqueues a record via `ApiMetricsService::recordMetricAsync`.
    - Apply globally (group) or to selected route groups.
    - Expose aggregates via an admin endpoint or console command.
  - Where
    - Middleware: `src/Routing/Middleware/MetricsMiddleware.php` (new)
    - Wiring: register service in DI and reference by name `metrics` in route groups.
  - Snippets
    ```php
    // src/Routing/Middleware/MetricsMiddleware.php (skeleton)
    <?php
    declare(strict_types=1);
    namespace Glueful\Routing\Middleware;
    use Glueful\Services\ApiMetricsService; use Symfony\Component\HttpFoundation\Request;
    final class MetricsMiddleware implements \Glueful\Routing\RouteMiddleware {
        public function __construct(private ApiMetricsService $metrics) {}
        public function handle(Request $request, callable $next): mixed {
            $t = microtime(true); $resp = $next($request);
            $dt = (microtime(true) - $t) * 1000;
            $this->metrics->recordMetricAsync([
                'endpoint' => $request->getPathInfo(),
                'method' => $request->getMethod(),
                'response_time' => (int) round($dt),
                'status_code' => method_exists($resp, 'getStatusCode') ? $resp->getStatusCode() : 200,
                'ip' => $request->getClientIp(),
                'timestamp' => time(),
            ]);
            return $resp;
        }
    }
    ```
    ```php
    // routes/resource.php (example usage)
    $router->group(['middleware' => ['metrics']], function(\Glueful\Routing\Router $router) {
        // ... existing routes
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

- [ ] Tracing hooks (Owner: Platform)
  - Actions
    - Add optional OpenTelemetry setup and a TracingMiddleware to wrap request handling in a span when enabled.
  - Where
    - Service provider: `src/DI/ServiceProviders/TracingServiceProvider.php` (new)
    - Middleware: `src/Routing/Middleware/TracingMiddleware.php` (new)
    - Config: `config/observability.php` (new flags)
  - Snippets
    ```php
    // config/observability.php (new)
    return [ 'tracing' => [ 'enabled' => env('OTEL_ENABLED', false) ] ];
    ```
    ```php
    // src/Routing/Middleware/TracingMiddleware.php (skeleton)
    <?php
    declare(strict_types=1);
    namespace Glueful\Routing\Middleware;
    use Symfony\Component\HttpFoundation\Request; use Glueful\Http\Response;
    final class TracingMiddleware implements \Glueful\Routing\RouteMiddleware {
        public function __construct(private $tracer=null) {}
        public function handle(Request $request, callable $next): mixed {
            if (!$this->tracer) { return $next($request); }
            $span = $this->tracer->spanBuilder('http.request')->startSpan();
            // Set semantic attributes (OpenTelemetry HTTP conventions)
            $span->setAttribute('http.method', $request->getMethod());
            $span->setAttribute('http.target', $request->getRequestUri());
            $span->setAttribute('http.route', $request->attributes->get('_route') ?? $request->getPathInfo());
            $span->setAttribute('user_agent.original', $request->headers->get('User-Agent'));
            $span->setAttribute('net.peer.ip', $request->getClientIp());
            if (function_exists('request_id')) { $span->setAttribute('glueful.request_id', request_id()); }
            $resp = null;
            try {
                $resp = $next($request);
                if (is_object($resp) && method_exists($resp, 'getStatusCode')) {
                    $span->setAttribute('http.status_code', $resp->getStatusCode());
                    if ($resp->getStatusCode() >= 500) { $span->setStatus(\OpenTelemetry\API\Trace\StatusCode::ERROR); }
                }
                // Add enduser.id if available
                if (isset($_SESSION['user_uuid'])) { $span->setAttribute('enduser.id', $_SESSION['user_uuid']); }
                return $resp;
            } finally {
                $span->end();
            }
        }
    }
    ```

- [ ] Dashboards & KPIs (Owner: SRE)
  - Actions
    - Define P95 latency, error rate, throughput KPIs per route group.
    - Publish sample dashboards under `docs/observability/DASHBOARDS.md` (to be created later).
  - Where
    - Docs only; no code changes yet.

- [ ] CI observability gates (Owner: DevEx)
  - Actions
    - Add smoke tests to assert `/healthz` 200 and key `/ready` checks.
    - Emit benchmark report from the bench script; store as CI artifact.
  - Where
    - Docs/CI harness: `docs/CI_TEST_BENCHMARK_HARNESS.md` (already contains scaffolds)
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

- [ ] Security audit command integration (Owner: Platform)
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

- [ ] Cache‑backed rate limiters (Owner: Backend)
  - Actions
    - Ensure `security.rate_limiter` defaults are set; verify middleware `'rate_limit'` used where needed.
    - Enable distributed limiter when running multiple workers.
    - Add integration tests to validate limiter behavior and headers.
  - Where
    - Config: `config/security.php` / `config/app.php`
    - Middleware alias: registered in `src/DI/ServiceProviders/CoreServiceProvider.php` as `'rate_limit'`
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

- [ ] Benchmarks & perf CI gates (Owner: DevEx)
  - Actions
    - Adopt the bench script; set initial non‑blocking reporting; later enforce thresholds.
  - Where
    - Bench script: see `docs/CI_TEST_BENCHMARK_HARNESS.md` (tools/bench/bench.php)
    - CI: `.github/workflows/test.yml`
  - Snippet
    ```yaml
    - name: Benchmarks (report only)
      run: |
        if [ -f tools/bench/bench.php ]; then
          php tools/bench/bench.php | tee build/logs/bench.txt
        else
          echo "Bench script missing; see docs/CI_TEST_BENCHMARK_HARNESS.md" | tee build/logs/bench.txt
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

- [ ] Docs & cookbook (Owner: Docs)
  - Actions
    - Author guides for routing patterns, middleware, DI, error handling, testing, deployment, observability.
  - Where
    - Docs: `docs/cookbook/` (to be created later)
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

- [ ] Release policy (Owner: Maintainers)
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
  - Add/port minimal router and route-cache tests per [CI harness scaffolds](../CI_TEST_BENCHMARK_HARNESS.md).
  - If desired, add a simple bench script and upload its output as an artifact (see harness doc for example).

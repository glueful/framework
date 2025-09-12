# Observability

Integrate metrics, logs, tracing, and benchmarks.

## Metrics

- Apply `metrics` middleware to measure request latency and counts.
- Export aggregates with `ApiMetricsService` (see `docs/API_METRICS.md`).

## Logging

- Framework logger includes `StandardLogProcessor` (request_id, user_id, env, version).
- Configure channels in `config/logging.php`. See `docs/LOGGING_SYSTEM.md`.

## Tracing

- Use `tracing` middleware; bind a tracer adapter if desired.
- See `docs/observability/PHASE_2_OBSERVABILITY_OVERVIEW.md`.

## Benchmarks in CI

- Harness: `docs/observability/CI_TEST_BENCHMARK_HARNESS.md`.
- Workflow examples in `.github/workflows/test.yml` (report + optional budget enforcement).

## Dashboards

- Examples and guidance: `docs/observability/DASHBOARDS.md`.


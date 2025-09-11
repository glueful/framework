# Glueful Observability Dashboards

This document provides starter dashboards and queries for monitoring Glueful APIs. Import panels into Grafana (Prometheus/OTel datasource) or adapt the queries for Datadog/New Relic.

## Prerequisites

- Metrics exported to Prometheus/OTel (or your APM). Suggested metric names used below:
  - glueful_http_requests_total (counter, labels: route, method, status)
  - glueful_http_request_duration_ms_bucket (histogram)
  - glueful_cache_hits_total / glueful_cache_misses_total (counters)
  - glueful_db_slow_queries_total (counter)
- Logs include request_id and user_id (already standardized by framework).

## KPIs (quick reference)

- P95 latency per route group: target < 200ms (web), < 500ms (heavy)
- Error rate (5m): target < 1% overall; spike alert > 3%
- Throughput (RPS): track trend and saturation
- Readiness failures: target 0; alert on > 1 in 5m
- Cache hit ratio: target > 80%; alert on < 60%
- DB slow queries (>200ms): target 0; alert on > 10 in 5m
- Queue backlog/lag: backlog < 100; lag < 30s

---

## Grafana (Prometheus/OTel) – Panels

Copy-paste panels below into a Grafana dashboard JSON or add as individual panels.

### P95 Latency by Route (Timeseries)

```json
{
  "title": "P95 Latency by Route",
  "type": "timeseries",
  "targets": [
    {
      "expr": "histogram_quantile(0.95, sum(rate(glueful_http_request_duration_ms_bucket[5m])) by (le, route))",
      "legendFormat": "{{route}}"
    }
  ],
  "fieldConfig": {
    "defaults": { "unit": "ms" },
    "overrides": []
  }
}
```

### Error Rate (5m) – Overall (Stat)

```json
{
  "title": "Error Rate (5m)",
  "type": "stat",
  "targets": [
    {
      "expr": "sum(rate(glueful_http_requests_total{status=~\"4..|5..\"}[5m])) / sum(rate(glueful_http_requests_total[5m]))"
    }
  ],
  "fieldConfig": {
    "defaults": { "unit": "percentunit" }
  }
}
```

### Requests per Second (RPS) – Overall (Timeseries)

```json
{
  "title": "RPS (overall)",
  "type": "timeseries",
  "targets": [
    { "expr": "sum(rate(glueful_http_requests_total[1m]))" }
  ]
}
```

### Cache Hit Ratio (Stat)

```json
{
  "title": "Cache Hit Ratio (5m)",
  "type": "stat",
  "targets": [
    { "expr": "sum(rate(glueful_cache_hits_total[5m])) / (sum(rate(glueful_cache_hits_total[5m])) + sum(rate(glueful_cache_misses_total[5m])))" }
  ],
  "fieldConfig": { "defaults": { "unit": "percentunit" } }
}
```

### DB Slow Queries (5m) – Stat

```json
{
  "title": "DB Slow Queries (5m)",
  "type": "stat",
  "targets": [
    { "expr": "sum(rate(glueful_db_slow_queries_total[5m]))" }
  ]
}
```

---

## Prometheus/OTel Query Snippets

- RPS (sum over 1m)
```promql
sum(rate(glueful_http_requests_total[1m]))
```

- Error rate over 5m
```promql
sum(rate(glueful_http_requests_total{status=~"4..|5.."}[5m]))
  /
sum(rate(glueful_http_requests_total[5m]))
```

- P95 latency (histogram)
```promql
histogram_quantile(0.95, sum(rate(glueful_http_request_duration_ms_bucket[5m])) by (le, route))
```

- Cache hit ratio
```promql
sum(rate(glueful_cache_hits_total[5m]))
/
(sum(rate(glueful_cache_hits_total[5m])) + sum(rate(glueful_cache_misses_total[5m])))
```

---

## Datadog Examples

- Error rate (5m)
```
service:glueful-api @status:[400 TO 599] | measure:count() by 5m /
(service:glueful-api | measure:count() by 5m)
```

- P95 latency by route
```
service:glueful-api @duration_ms:* | measure:p95(@duration_ms) by route
```

- Readiness failures (count)
```
service:glueful-api route:/ready @status:503 | measure:count() by 5m
```

---

## New Relic (NRQL) Examples

- P95 latency by URI (5m)
```sql
SELECT percentile(duration, 95)
FROM Transaction
WHERE appName = 'glueful-api'
FACET request.uri
SINCE 5 minutes ago
```

- Error rate (5m)
```sql
SELECT filter(count(*), WHERE httpResponseCode LIKE '4%%' OR httpResponseCode LIKE '5%%')
  / count(*)
FROM Transaction
WHERE appName = 'glueful-api'
SINCE 5 minutes ago
```

---

## Tips & Notes

- Always include request_id in logs and traces to correlate across dashboards.
- For route grouping, consider adding a route_group label (e.g., auth, resources) to metrics.
- Tune alert thresholds per service; start with suggested targets and adjust with baseline.
- Keep dashboards scoped and fast; build separate boards for Exec, API, Errors, Dependencies, Workers.


# Glueful Framework – API Review

## Summary

A modern, modular API framework built atop Symfony components and a Symfony DI core, with a strong routing layer, consistent response envelope, centralized error handling, and good attention to performance and security.

## Strengths

- Robust routing: static/dynamic bucketing, HEAD/OPTIONS/CORS handling, attribute discovery, route caching with opcache warmup.
- Solid DI layer: Symfony DI wrapped with runtime overrides and better error messages; lazy services for heavy dependencies.
- Consistent responses: unified JSON envelope with helpers for success, pagination, and errors.
- Centralized exceptions: rich context collection, rate-limited error responses, framework/app classification.
- Security-minded: environment validation for production, rate limiting building blocks, security headers and CORS helpers.
- Performance practices: boot profiler, lazy initialization, route pipeline caching, reflection caching.

## Architecture

- Clear separation by domain: Routing, Http, DI, Cache, Database, Queue, Serialization, Validation, Security, Events, Console.
- Config layering via helpers and a cache-backed loader with framework/app precedence.
- Framework boot consolidates environment, configuration, container build, core service init, HTTP setup, and deferred background tasks.

## Routing

### Features

- Fast matching: static O(1) map + first-segment buckets for dynamic routes.
- Correct HTTP semantics: HEAD→GET mapping, OPTIONS preflight responses with centralized CORS headers.
- Attribute routes: class/method attributes for clean controllers; group prefixes, middleware stacks, and route naming/constraints.
- Route caching: compiler emits PHP arrays describing handlers + metadata, with reconstruction and opcache warmup.

### Suggestions

- Provide a CLI to list routes and verify cache validity as part of CI.
- Add optional PSR-7/PSR-15 adapters for broader middleware ecosystem compatibility (there’s a PSR-15 hook; consider widening its use).

## Error Handling & Logging

- Global exception handler: converts PHP errors, captures fatal shutdowns, consistent JSON error body, and channel-based logging.
- Good context capture with optimized/lightweight modes and caching; rate-limits error responses to reduce log spam.

### Suggestions

- Push production environment warnings to logger once logging is available in boot (in addition to early error_log).
- Consider structured logging processors (e.g., request ID, user ID) registered centrally to avoid per-log context duplication.

## Security

- Production configuration validation (APP_KEY/JWT_KEY/CORS/HTTPS/HSTS/CSP), centralized CORS builder, secure serializer, rate limiting primitives.

### Suggestions

- Move error-response rate limiting store from static in-memory to a cache-backed implementation for multi-process consistency.
- Document CORS best practices and deprecate any helper variants that can mislead (centralize on the router-driven approach).

## Performance

- Lazy service registry, container build timing, route middleware pipeline caching, reflection cache, route cache TTL in dev, opcache compile in prod.

### Suggestions

- Add quick synthetic benchmarks for routing and container boot to guard against regressions.
- Optionally expose BootProfiler results via a dev-only endpoint/CLI for visibility.

## Developer Experience

- Clean helpers (app(), container(), config(), response(), request_id()) and coherent console commands.
- Attribute-driven routing and a standardized response layer make controller authoring straightforward.

### Suggestions

- Add a minimal cookbook: routing patterns, middleware, DI tips, error handling, testing.
- Provide skeletons for controllers/middleware via console generators.

## Database & Services

- Homegrown DB layer with drivers, pooling, query builders, schema builders, migrations, and query tooling; plus Cache, Queue, Lock, Serialization, Validation.

### Suggestions

- Document DB capabilities and guarantees (transactions, pagination consistency, soft deletes).
- Consider optional integration points for popular ORMs if teams prefer them.

## Gaps/Risks

- Global state usage (GLOBALS/config paths, container) is pragmatic but can complicate testing; continue improving seams for dependency injection in helpers.
- Middleware signature is framework-specific; PSR interfaces exist but aren’t first-class throughout.
- Class discovery from files (attribute loader) uses simple parsing; can miss edge cases; acceptable if documented.
- No visible test suite here; recommend adding unit + integration tests for router, exception handler, DI wiring, and boot.

## Recommendations

### Short term

- Add route list/verify commands to CI; add a smoke test that boots Framework, loads routes, dispatches a few paths.
- Promote Response::success/created/error usage in docs to keep response envelope consistent.
- Centralize request ID propagation across logs and responses (done in code; document usage).

### Medium term

- Offer PSR-7/PSR-15 bridging and examples; consider a dedicated middleware pipeline that can natively host PSR middleware.
- Replace ad-hoc global lookups in hot paths with injected services where practical, and improve test seams.
- Strengthen attribute discovery (composer class map-based) if teams lean heavily on attributes.

### Long term

- Establish a public extension API and versioning policy; add stability guarantees around routing/DI contracts.
- Publish example project templates and performance baselines.

---

Overall: This is a thoughtfully designed API framework with strong fundamentals—routing, DI, responses, exceptions, and security—paired with pragmatic performance optimizations. With a bit more polish on interop (PSR), testing, and documentation, it’s well-positioned for production APIs.

## Additional Considerations

- Extension Ecosystem: Document plugin/extension patterns for third-party integrations (naming, lifecycle hooks, service registration, versioning compatibility, discoverability).
  - Example service provider
    ```php
    <?php
    namespace App\Extensions\Acme;

    use Glueful\Extensions\ServiceProvider;

    final class AcmeServiceProvider extends ServiceProvider
    {
        public function register($container): void
        {
            // Register services / singletons
            $container->set(AcmeClient::class, new AcmeClient(env('ACME_API_KEY')));
        }

        public function boot(): void
        {
            // Optional: register routes, listeners, console commands
            // e.g., Router::get('/acme/ping', [AcmeController::class, 'ping']);
        }
    }
    ```
  - Suggested conventions: `Vendor\Extensions\Package\*`, expose a `ServiceProvider`, optional `PackageManifest` (name, version, constraints).
  - Discovery: via config (`extensions.providers`) or composer extra metadata; ensure compatibility checks in `ExtensionManager`.
  - Links: Symfony DI concepts (providers), Composer plugin discovery.

- Deployment Patterns: Provide containerization and orchestration guidance (Dockerfile best practices, health/readiness probes, horizontal scaling with queue/cron workers, config via env/Secrets).
  - Template Dockerfile (FPM + Opcache)
    ```dockerfile
    # syntax=docker/dockerfile:1
    FROM php:8.2-fpm-alpine AS base

    # System deps
    RUN apk add --no-cache git unzip icu-dev libzip-dev oniguruma-dev bash nginx

    # PHP extensions
    RUN docker-php-ext-install intl opcache pdo pdo_mysql

    # Composer
    COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

    WORKDIR /var/www/html
    COPY . .
    RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader

    # Opcache recommended settings
    RUN { \
      echo "opcache.enable=1"; \
      echo "opcache.enable_cli=0"; \
      echo "opcache.jit=1255"; \
      echo "opcache.jit_buffer_size=64M"; \
      echo "opcache.memory_consumption=256"; \
      echo "opcache.max_accelerated_files=20000"; \
    } > /usr/local/etc/php/conf.d/opcache.ini

    # (Optional) Nginx sidecar can be used in K8s; container here only runs php-fpm
    EXPOSE 9000
    CMD ["php-fpm"]
    ```

  - Minimal Nginx config (for K8s ConfigMap)
    ```nginx
    server {
      listen 8080;
      server_name _;
      root /var/www/html/public;

      location /health { return 200 'ok'; add_header Content-Type text/plain; }

      location / {
        try_files $uri /index.php?$query_string;
      }

      location ~ \\.(php)$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass 127.0.0.1:9000; # php-fpm
      }
    }
    ```

  - Minimal Kubernetes manifests (Deployment + Service)
    ```yaml
    # k8s/deployment.yaml
    apiVersion: apps/v1
    kind: Deployment
    metadata:
      name: glueful-api
    spec:
      replicas: 3
      selector: { matchLabels: { app: glueful-api } }
      template:
        metadata:
          labels: { app: glueful-api }
        spec:
          containers:
            - name: php-fpm
              image: your-registry/glueful-api:latest
              ports: [{ containerPort: 9000 }]
              env:
                - name: APP_ENV
                  value: production
              readinessProbe:
                tcpSocket: { port: 9000 }
                initialDelaySeconds: 5
                periodSeconds: 5
            - name: nginx
              image: nginx:1.25-alpine
              ports: [{ containerPort: 8080 }]
              volumeMounts:
                - name: nginx-conf
                  mountPath: /etc/nginx/conf.d/default.conf
                  subPath: default.conf
              readinessProbe:
                httpGet: { path: /health, port: 8080 }
                initialDelaySeconds: 5
          volumes:
            - name: nginx-conf
              configMap:
                name: glueful-nginx
                items:
                  - key: default.conf
                    path: default.conf
    ---
    apiVersion: v1
    kind: ConfigMap
    metadata:
      name: glueful-nginx
    data:
      default.conf: |
        # paste the nginx config above here
    ---
    apiVersion: v1
    kind: Service
    metadata:
      name: glueful-api
    spec:
      selector: { app: glueful-api }
      ports:
        - name: http
          port: 80
          targetPort: 8080
    ```

  - Notes: scale workers (queue/cron) via separate Deployments; inject secrets via K8s Secrets; configure resources/limits and HPA once load is known.

- Monitoring Integration: Recommend APM and logging integrations (OpenTelemetry, New Relic, Datadog, Elastic), with examples for request/DB tracing and log shipping.
  - OpenTelemetry (manual SDK sketch)
    ```php
    // During boot (development optional)
    $resource = \OpenTelemetry\SDK\Resource\ResourceInfo::create(\OpenTelemetry\SDK\Resource\ResourceAttributes::create([
        'service.name' => 'glueful-api',
    ]));
    $exporter = new \OpenTelemetry\Exporter\Otlp\OtlpHttpExporter();
    $spanProcessor = new \OpenTelemetry\SDK\Trace\SimpleSpanProcessor($exporter);
    $tracerProvider = new \OpenTelemetry\SDK\Trace\TracerProvider($spanProcessor, null, $resource);
    $tracer = $tracerProvider->getTracer('glueful');
    ```
  - Datadog (ddtrace) quick enable
    ```bash
    # docker env
    DD_AGENT_HOST=$(hostname -i)
    DD_TRACE_ENABLED=true
    DD_SERVICE=glueful-api
    DD_ENV=production
    ```
  - New Relic PHP agent: install extension, set `NEW_RELIC_LICENSE_KEY`, `NEW_RELIC_APP_NAME`.
  - Logging processors (Monolog) to add context
    ```php
    $logger->pushProcessor(function(array $record) {
        $record['extra']['request_id'] = function_exists('request_id') ? request_id() : null;
        $record['extra']['user_id'] = isset($_SESSION['user_uuid']) ? $_SESSION['user_uuid'] : null;
        return $record;
    });
    ```
  - Links: OpenTelemetry PHP, Datadog dd-trace-php, New Relic PHP Agent, Elastic APM PHP.

### Optional: Build & Deploy Commands

- Build image
  ```bash
  docker build -t your-registry/glueful-api:latest .
  ```

- Local run (php-fpm only, for smoke tests)
  ```bash
  docker run --rm -it \
    -e APP_ENV=production \
    -p 9000:9000 \
    your-registry/glueful-api:latest
  # In another shell, run an nginx container and point fastcgi_pass to host.docker.internal:9000 (Mac/Win)
  ```

- Local run (compose-free two‑container example)
  ```bash
  # Start php-fpm
  docker run -d --name glueful-fpm -e APP_ENV=production -p 9000:9000 your-registry/glueful-api:latest
  # Start nginx with inline config (Linux may need --network host alternatives)
  cat > /tmp/glueful-nginx.conf <<'CONF'
  server {
    listen 8080;
    root /var/www/html/public;
    location /health { return 200 'ok'; add_header Content-Type text/plain; }
    location / { try_files $uri /index.php?$query_string; }
    location ~ \\.(php)$ {
      include fastcgi_params;
      fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
      fastcgi_pass host.docker.internal:9000; # or host IP on Linux
    }
  }
  CONF
  docker run -d --name glueful-nginx -p 8080:8080 -v /tmp/glueful-nginx.conf:/etc/nginx/conf.d/default.conf:ro nginx:1.25-alpine
  ```

- Kubernetes (apply from inline manifests)
  ```bash
  # Create namespace
  kubectl create ns glueful || true
  # Apply configmap
  kubectl -n glueful apply -f - <<'YAML'
  apiVersion: v1
  kind: ConfigMap
  metadata: { name: glueful-nginx }
  data:
    default.conf: |
      server {
        listen 8080;
        root /var/www/html/public;
        location /health { return 200 'ok'; add_header Content-Type text/plain; }
        location / { try_files $uri /index.php?$query_string; }
        location ~ \\.(php)$ {
          include fastcgi_params;
          fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
          fastcgi_pass 127.0.0.1:9000;
        }
      }
  YAML
  # Apply deployment + service
  kubectl -n glueful apply -f - <<'YAML'
  apiVersion: apps/v1
  kind: Deployment
  metadata: { name: glueful-api }
  spec:
    replicas: 3
    selector: { matchLabels: { app: glueful-api } }
    template:
      metadata: { labels: { app: glueful-api } }
      spec:
        containers:
          - name: php-fpm
            image: your-registry/glueful-api:latest
            ports: [{ containerPort: 9000 }]
            env: [{ name: APP_ENV, value: production }]
            readinessProbe: { tcpSocket: { port: 9000 }, initialDelaySeconds: 5, periodSeconds: 5 }
          - name: nginx
            image: nginx:1.25-alpine
            ports: [{ containerPort: 8080 }]
            volumeMounts: [{ name: nginx-conf, mountPath: /etc/nginx/conf.d/default.conf, subPath: default.conf }]
            readinessProbe: { httpGet: { path: /health, port: 8080 }, initialDelaySeconds: 5 }
        volumes:
          - name: nginx-conf
            configMap: { name: glueful-nginx, items: [{ key: default.conf, path: default.conf }] }
  ---
  apiVersion: v1
  kind: Service
  metadata: { name: glueful-api }
  spec:
    selector: { app: glueful-api }
    ports: [{ name: http, port: 80, targetPort: 8080 }]
  YAML
  # Port-forward for quick checks
  kubectl -n glueful port-forward svc/glueful-api 8080:80
  curl -sf http://127.0.0.1:8080/health
  ```

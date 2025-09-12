# Deployment

Checklist and tips for deploying Glueful services.

## Checklist

- Configure environment: `.env` with `APP_ENV=production`, DB/cache endpoints.
- Enable a real cache backend (e.g., Redis) in `config/cache.php`.
- Harden security defaults in `config/security.php` (CSP/HSTS, allowlists).
- Ensure PHP OPcache is enabled; tune memory limits.
- Serve via a production web server (Nginx/Apache) or PHP-FPM.
- Health endpoints: `/healthz` (liveness), `/ready` (readiness + allowlist).
- Logs: configure paths/rotation; ship to your observability stack.

## Zero-Downtime Hints

- Warm up route cache/opcache during deploy.
- Run DB migrations and queue workers with supervision.

See also: `docs/DEPLOYMENT.md` for deeper guidance.

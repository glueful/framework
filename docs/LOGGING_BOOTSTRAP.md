# Logging Bootstrap

## Purpose

Define one canonical, production-safe logging bootstrap model for Glueful.

Core principle:

- Generic logging remains part of core framework.
- Profile defaults are environment-aware.
- Env variables remain the final override mechanism.

## How Bootstrap Works

Logging configuration is resolved in `config/logging.php` using:

1. Active profile (`LOG_PROFILE` or fallback from `APP_ENV`)
2. Profile defaults
3. Explicit env overrides (`LOG_*`, `FRAMEWORK_LOG_*`, etc.)

This gives deterministic startup behavior while keeping operational flexibility.

## Profiles

Available profiles:

- `development`
- `staging`
- `production`
- `testing`

Select a profile:

```dotenv
LOG_PROFILE=production
```

If unset, profile falls back to `APP_ENV`.

## Production Baseline

Recommended baseline:

```dotenv
APP_ENV=production
APP_DEBUG=false
LOG_PROFILE=production

LOG_TO_FILE=true
LOG_TO_DB=false
LOG_LEVEL=warning

EVENTS_ENABLED=true
EVENTS_AUDIT_LOGGING=true
```

Notes:

- Set `LOG_TO_DB=true` only when searchable database-backed logs are required.
- Keep `APP_DEBUG=false` in production.
- Keep event audit toggles enabled unless there is an explicit reason to disable them.

## Retention Defaults

Recommended retention windows:

- debug: 7 days
- app/framework: 30 to 90 days
- auth/security/error: 365 days

Example env overrides:

```dotenv
LOG_RETENTION_DEBUG_DAYS=7
LOG_RETENTION_APP_DAYS=90
LOG_RETENTION_FRAMEWORK_DAYS=90
LOG_RETENTION_AUTH_DAYS=365
LOG_RETENTION_SECURITY_DAYS=365
LOG_RETENTION_ERROR_DAYS=365
```

## Storage Model

Database-backed logs are written to `activity_logs` via `DatabaseLogHandler`.

Structured audit-friendly columns include:

- `action`
- `actor_id`
- `resource_type`
- `resource_id`
- `channel`
- `created_at`

## Operational Checks

Use production check mode to validate unsafe settings:

```bash
php glueful system:check --production --details
```

Key failures include:

- `APP_DEBUG=true` in production
- both `LOG_TO_FILE=false` and `LOG_TO_DB=false`
- `EVENTS_ENABLED=false` in production
- `EVENTS_AUDIT_LOGGING=false` in production
- invalid retention values

## Cleanup

Use channel-aware cleanup to enforce retention policy:

- `LogCleanupTask::cleanDatabaseLogsByChannel()`
- `LogCleanupJob` with `cleanupType=channel` or `cleanupType=all`

## Troubleshooting

If logs are missing:

1. Check active profile (`LOG_PROFILE`, `APP_ENV`).
2. Confirm at least one sink is enabled (`LOG_TO_FILE` or `LOG_TO_DB`).
3. Confirm file path is writable (`LOG_FILE_PATH`).
4. Confirm DB connectivity when `LOG_TO_DB=true`.
5. Confirm events toggles for audit event flow (`EVENTS_ENABLED`, `EVENTS_AUDIT_LOGGING`).

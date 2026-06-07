# Migrations & Core Capability Schema

Glueful runs **one ordered migration stream** assembled from several owners — the framework core, enabled extensions, and your app — each tracked separately so they never collide. The framework owns the schema for the subsystems whose code it ships (auth, queue, scheduler, notifications, metrics, …), and installs each capability's tables **only when that capability is enabled**. This page explains how that works and how to control it.

## Ownership model

A table's migration belongs to the package whose code reads and writes it:

| Owner | Source label | Examples |
|---|---|---|
| **Framework core** | `glueful/framework` (auth) and `glueful/framework:<capability>` | `auth_sessions`, `api_keys`, `queue_jobs`, `notifications`, `api_metrics`, `locks`, … |
| **Extensions** | the package name, e.g. `glueful/users`, `glueful/aegis` | `users`, `profiles` (users); `roles`, `permissions` (aegis) |
| **Your app** | `app` | whatever you put in `database/migrations/` |

Each applied migration is recorded in the `migrations` table with its **`source`** and basename, so two owners can ship a migration with the same filename (e.g. `001_…`) without conflict, and rollback targets the right one.

### Ordering

Pending migrations run in a deterministic order: **`(priority, basename, source)`**.

Priorities (`Glueful\Database\Migrations\MigrationPriority`):

| Tier | Value | Used by |
|---|---|---|
| `FOUNDATION` | `-200` | framework core schema (auth + capabilities) |
| `IDENTITY` | `-100` | the user store (`glueful/users`) |
| `DEFAULT` | `0` | your app / skeleton |
| `DEPENDENT` | `100` | things that build on identity (`glueful/aegis` roles/permissions) |

So on a typical stack the order is: **core → users → app → aegis**.

## No runtime table creation

Core subsystems do **not** create their tables lazily at request time. The schema is installed by migrations only — run:

```bash
php glueful migrate:run
```

If a table is missing you'll get a normal "no such table" error telling you to migrate, rather than silent DDL on a production request. (Historically `DatabaseQueue`, `JobScheduler`, `NotificationRetryService`, and `ApiMetricsService` created tables on the fly; they no longer do.)

## Core capability schema

The framework ships its capability migrations under `framework/migrations/<capability>/`, each registered automatically **only when its gate is on**, under source `glueful/framework:<capability>`. Auth is always installed.

| Capability | Tables | Gate | Default |
|---|---|---|---|
| **auth** | `auth_sessions`, `auth_refresh_tokens`, `api_keys` | always on | on |
| **uploads** | `blobs` | `uploads.enabled` | **on** |
| **queue** | `queue_jobs`, `queue_failed_jobs`, `queue_batches` | `queue.default === 'database'` | on (db driver) |
| **scheduler** | `scheduled_jobs`, `job_executions` | `capabilities.scheduler` | **on** |
| **notifications** | `notifications`, `notification_deliveries`, `notification_preferences`, `notification_templates`, `notification_retry_queue` | `capabilities.notifications` | **on** |
| **metrics** | `api_metrics`, `api_metrics_daily`, `api_rate_limits` | `capabilities.metrics` | **on** |
| **locks** | `locks` | `lock.default === 'database'` | off (file driver) |

> **Archive** is no longer a core capability — it was extracted to the **`glueful/archive` extension**, which owns its own `archive_registry`/`archive_search_index`/`archive_table_stats` schema. Install it with `composer require glueful/archive`.

A capability that's off registers nothing — its tables are never created, and `migrate:status` won't list them.

## Controlling which capabilities install

Two kinds of gates:

**1. Driver/enable config (locks, queue, uploads).** These already have a config signal, so the schema follows it — no separate switch:

```env
QUEUE_CONNECTION=database   # queue tables install; redis/sync → they don't
LOCK_DRIVER=database        # locks table installs; file (default) → it doesn't
UPLOADS_ENABLED=true        # blobs installs
```

**2. The capability switchboard — `config/capabilities.php`.** Capabilities without a natural driver signal are toggled here:

```php
// config/capabilities.php
return [
    'scheduler'     => env('SCHEDULE_DATABASE_STORE', true),
    'notifications' => env('NOTIFICATIONS_DATABASE_STORE', true),
    'metrics'       => env('METRICS_DATABASE_STORE', true),
];
```

Toggle via env (no file edit needed):

```env
SCHEDULE_DATABASE_STORE=false      # don't install scheduler tables
NOTIFICATIONS_DATABASE_STORE=false
METRICS_DATABASE_STORE=false
```

…or override `config/capabilities.php` in your app for full control. After changing a gate, run `php glueful migrate:run` to apply (or `migrate:rollback` if you turned one off and want to drop its tables).

> **Note:** turning a capability **on** later simply makes its migration *pending* until the next `migrate:run`. Turning it **off** stops it from being registered, but does not drop already-created tables — roll them back explicitly if you want them gone.

## For extension authors

Register your extension's migrations from your service provider with an explicit priority and source:

```php
use Glueful\Database\Migrations\MigrationPriority;

public function register(ApplicationContext $context): void
{
    $this->loadMigrationsFrom(
        __DIR__ . '/../migrations',
        MigrationPriority::IDENTITY,   // or DEFAULT / DEPENDENT
        'vendor/your-extension'        // your composer package name = the source label
    );
}
```

- Pick a **priority** that places your tables correctly relative to others (e.g. something that decorates identities runs at `DEPENDENT`, after the user store).
- Use your **package name** as the source so your applied state is tracked separately.
- Reference a user/principal as an **indexed UUID with no cross-package FK** — validate existence in service logic, not via SQL (keeps packages decoupled).
- Migration filenames must start with three digits (`001_…`); cross-source ordering comes from the priority, not the prefix.

## Quick reference

```bash
php glueful migrate:run        # apply all pending (core + extensions + app), in order
php glueful migrate:status     # show pending/applied with their source
php glueful migrate:rollback   # roll back the last batch (by source + basename)
```

To see exactly what's installed and from where:

```sql
SELECT source, migration FROM migrations ORDER BY id;
```

## See also

- [Identity & User Providers](IDENTITY.md) — the provider-agnostic identity model the auth schema backs.
- [API Keys](API_KEYS.md) — the API-key capability in detail.

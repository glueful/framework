# Migrations & Core Capability Schema

Glueful runs **one ordered migration stream** assembled from several owners — the framework core, enabled extensions, and your app — each tracked separately so they never collide. The framework owns the schema for the subsystems whose code it ships (auth, queue, scheduler, notifications, metrics, …) and always installs it. This page explains how the stream is assembled and how extensions declare their own schema.

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
| `PLATFORM` | `-50` | platform layers that build on identity but precede app schema (e.g. `glueful/tenancy`) |
| `DEFAULT` | `0` | your app / skeleton |
| `DEPENDENT` | `100` | things that build on identity (`glueful/aegis` roles/permissions) |

So on a typical stack the order is: **core → users → platform → app → aegis**.

## No runtime table creation

Core subsystems do **not** create their tables lazily at request time. The schema is installed by migrations only — run:

```bash
php glueful migrate:run
```

If a table is missing you'll get a normal "no such table" error telling you to migrate, rather than silent DDL on a production request. (Historically `DatabaseQueue`, `JobScheduler`, `NotificationRetryService`, and `ApiMetricsService` created tables on the fly; they no longer do.)

## Core capability schema

The framework ships its migrations under `framework/migrations/<leaf>/`. Since 1.79 **every leaf is
registered unconditionally** at `FOUNDATION`, under source `glueful/framework` (auth) or
`glueful/framework:<leaf>`; they are declared in `Glueful\Extensions\Schema\FrameworkDescriptors`.

| Leaf | Tables |
|---|---|
| **auth** | `auth_sessions`, `auth_refresh_tokens`, `api_keys` |
| **extensions** | `extension_operations` (schema-on-enable ledger) |
| **uploads** | `blobs` |
| **queue** | `queue_jobs`, `queue_failed_jobs`, `queue_batches` |
| **scheduler** | `scheduled_jobs`, `job_executions` |
| **notifications** | `notifications`, `notification_deliveries`, `notification_preferences`, `notification_templates`, `notification_retry_queue` |
| **metrics** | `api_metrics`, `api_metrics_daily`, `api_rate_limits` |
| **locks** | `locks` |

> **Archive** is no longer a core capability — it was extracted to the **`glueful/archive` extension**, which owns its own `archive_registry`/`archive_search_index`/`archive_table_stats` schema. Install it with `composer require glueful/archive`.

Installing a table does not mean the subsystem uses it: the queue uses `queue_jobs` only with
`QUEUE_CONNECTION=database`, locks use `locks` only with `LOCK_DRIVER=database`, and so on.

## Runtime persistence switch — `config/capabilities.php`

`config/capabilities.php` no longer gates migrations. Its one remaining switch decides whether
notifications **persist** at runtime:

```php
// config/capabilities.php
return [
    'notifications' => env('NOTIFICATIONS_DATABASE_STORE', true),
];
```

With `NOTIFICATIONS_DATABASE_STORE=false` there is no `database` notification channel and
scheduling a notification throws. (The former `scheduler` and `metrics` switches were removed:
nothing read them.)

## For extension authors

Since 1.80 an installed Glueful package **must declare its schema** in `composer.json`; enabling an
undeclared one throws `UndeclaredSchemaException`. Declare a lane per migrations folder:

```json
"extra": {
  "glueful": {
    "provider": "Vendor\\Ext\\ExtServiceProvider",
    "migrations": [
      { "id": "default", "path": "migrations", "priority": "dependent", "mode": "on_enable" }
    ]
  }
}
```

- `priority` is one of `foundation | identity | platform | default | dependent` — pick the tier that
  places your tables correctly (something that decorates identities runs at `dependent`, after the
  user store).
- `mode` is `on_enable` for extensions (migrations run when `extensions:enable` runs) or `core`.
- An optional `verifier` names a schema-verifier class used by `migrate:verify --adopt`.
- A package with no schema declares `"migrations": "none"`.
- The declaration registers the lane; the provider does **not** call `loadMigrationsFrom()` for a
  declared path (a call that contradicts the manifest throws `DescriptorValidationException`).
  App-local providers that belong to no package can still use `loadMigrationsFrom()`.
- Reference a user/principal as an **indexed UUID with no cross-package FK** — validate existence in service logic, not via SQL (keeps packages decoupled).
- Migration filenames must start with three digits (`001_…`); cross-source ordering comes from the priority, not the prefix.

### When your migrations change owner: `previous_sources`

The ledger records every applied file under its **source**. If files change owner — an
application that becomes a package (its rows say `app`), a renamed package, a lane split out of
a package (`vendor/name` → `vendor/name:lane`) — every existing database would see them as new.
Declare the names they were recorded under before and the framework treats those rows as
applied, then rewrites them to the current source on the next run. Fresh installs never see the
key; after one run on an existing install it is inert.

In the manifest (`extra.glueful.migrations`):

```json
{ "id": "default", "path": "migrations", "priority": "default", "mode": "core",
  "previous_sources": ["app"] }
```

From a provider: `$this->loadMigrationsFrom($dir, $priority, 'vendor/name', ['old/name']);`

Only files the lane actually ships are adopted: a previous source that is still a live lane of
its own (the skeleton's `app`) keeps every other row.

## Quick reference

```bash
php glueful migrate:run        # apply all pending (core + extensions + app), in order
php glueful migrate:status     # show pending/applied with their source
php glueful migrate:rollback   # roll back the last migration (--steps=N for more; by source + basename)
php glueful migrate:verify     # check applied schema against declarations (--adopt to adopt)
```

To see exactly what's installed and from where:

```sql
SELECT source, migration FROM migrations ORDER BY id;
```

## See also

- [Identity & User Providers](IDENTITY.md) — the provider-agnostic identity model the auth schema backs.
- [API Keys](API_KEYS.md) — the API-key capability in detail.

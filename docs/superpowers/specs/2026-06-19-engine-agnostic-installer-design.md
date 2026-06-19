# Engine-Agnostic Installer + First-Run Setup Seams

**Date:** 2026-06-19
**Status:** Design approved — ready for implementation plan
**Repo:** `glueful/framework`

## Problem

`php glueful install` only sets up the database for **SQLite**. `InstallCommand::setupDatabase()`
bails for any other engine:

```php
if ($dbDriver !== 'sqlite') {
    $this->line('• Install currently supports SQLite only. Skipping database setup.');
    return;
}
```

So an app that requires MySQL/Postgres (e.g. Lemma, which is Postgres-only) cannot use `install`
to migrate its database, and there is no programmatic way for an app's **UI** to drive first-run
setup — the only prior art shells out (`exec("php glueful install")`) and greps stdout.

Much of the needed machinery already exists in the framework but is **disconnected**:

- `InstallCommand::updateEnvFile($key, $value)` — a generic `.env` writer (used only for keys),
  which **does not quote values** (a password with a space/`#`/`=`/quote corrupts `.env`).
  `Generate\KeyCommand` carries a **second, separate** unquoted copy — so there are two unsafe writers.
- `configureDatabaseInteractively()` + `configureMysqlDatabase()`/`configurePostgreSQLDatabase()`/
  `configureSqliteDatabase()` — full interactive DB-cred prompts that write `.env` — **defined but
  never called** (orphaned).
- `HealthService::checkDatabase()` — tests the **already-configured** connection (skipped in the
  migrate step today).
- `MigrationManager::migrate()` — the real, engine-agnostic migration runner (`migrate:run` wraps it).
- Admin-user creation is **absent** from core `install` — correct, since users live in the
  `glueful/users` extension.

This work **reconnects, un-gates, hardens, and extracts** that machinery into reusable services so
one tested engine backs both the CLI installer and an app's future setup UI.

## Boundary (framework vs app)

- **Framework (`Glueful\Installer\`):** `.env` writer (hardened, atomic), key generation, DB config
  + connection test against **arbitrary** creds, the engine-agnostic install orchestration, light
  read-only install-state helpers, and the CLI `install` flow — all calling the **same** code path.
- **App (e.g. Lemma):** the setup HTTP API + wizard UI, admin bootstrap, site settings, the
  `installed` lock semantics, and product-specific copy/checks. **The framework ships no setup
  endpoint** — setup is exposed pre-auth and writes config/runs migrations, so when/how it is
  allowed and locked belongs to the app.

## Hard invariants

Two invariants, both first-class.

**1. Preflight before any `.env` mutation.** When DB credentials are supplied, `ConnectionTester::test()`
runs **first — before `.env` is created or any key is written.** On failure the installer aborts and
**`.env` is left literally untouched** (not created from `.env.example`, no keys, no DB keys). There
is **no "save anyway" mode this iteration** — invalid DB input never mutates state. (A future opt-in
`force` may be added; out of scope here.)

**2. Tested credentials are exactly what the migrations run on.** The creds that pass the test are
the *same* connection the migrations use — guaranteed **structurally** by threading one
`DatabaseConfig` value object through test → persist → migrate, **never** by relying on a post-write
env/config reload. This matters because `Connection`/`MigrationManager` read already-loaded config
and **dotenv is immutable + loaded once** (`Framework.php:160`), so writing new creds to `.env` does
**not** change the in-process connection — migrations would otherwise silently run on the *old*
(default SQLite) connection. The installer therefore builds the migration `Connection` **from the
explicit tested config** and injects it into `MigrationManager` (see *Framework changes required*).

`ConnectionTester` builds its connection from the explicit `DatabaseConfig` (overriding — not reading —
the stale global config), so it tests the **exact** connection (engine, DSN, SSL mode, schema) the
migration will use; the two cannot diverge.

## The seams (`Glueful\Installer\`)

One value object plus four small, independently-testable units.

### `DatabaseConfig` (value object)
The single representation of "the DB config being installed", threaded through test → persist →
migrate so they can never diverge.
- Fields: `engine` + the engine's params — mysql/pgsql: `host`, `port`, `database`, `username`,
  `password`; pgsql also `schema` and `sslMode` (optional, default empty/`prefer`); sqlite:
  `database` (file path). Other pgsql extras (`prefix`, `timezone`, `role`) are optional passthrough.
- **One mapping in one place:** `DatabaseConfig` → a `Connection` (via `Connection`'s explicit
  `$config` override) and `DatabaseConfig` → the engine-specific env keys (below). Both
  `ConnectionTester` and the migration step build their connection from this one mapping, which is
  what makes "tested == migrated" structural.

### `EnvWriter`
Reads/writes `.env` key/values.
- API: `set(string $key, string $value): void`, `setMany(array<string,string> $pairs): void`,
  `get(string $key): ?string`.
- **Quoting/escaping (the bug fix):** values containing whitespace, `#`, `=`, `"`, or newlines are
  written double-quoted with internal quotes/backslashes escaped; safe bare values are written
  unquoted. Round-trips (a written value reads back identical).
- **Atomic write:** read → transform in memory → write a temp file in the same directory →
  `rename()` over `.env` (atomic on POSIX). Never leaves a partially-written `.env`.
- **Preserves comments and key order**; **appends missing keys at the end**. Updates an existing key
  in place.

### `ConnectionTester`
Validates an explicit `DatabaseConfig` against a live connection without touching global state.
- API: `test(DatabaseConfig $config): ConnectionTestResult`.
- Builds a connection from the **explicit config via the one `DatabaseConfig` → `Connection`
  mapping** the migration step also uses — so the test exercises the **exact** DSN/SSL-mode/schema
  the migration will. `Connection` accepts an explicit `$config`, which **overrides** (does not read)
  the stale loaded env/config, so the not-yet-committed creds are honored. Uses a **short connect
  timeout** (an unreachable host fails fast rather than hanging a CLI prompt or a UI request), opens,
  probes (`SELECT 1`), and **disposes the connection** (transient; never retained in a pool). Mutates
  no `.env`, no global config, no pool.
- Returns a **typed `ConnectionTestResult`**: `{engine: string, ok: bool, message: string,
  exceptionClass: ?string, sqlState: ?string}`. The message and fields are diagnostic and **never
  contain the password** or a credentialed DSN.

### `Installer` (orchestrator)
The install pipeline as a callable seam.
- API: `run(InstallOptions $options): InstallResult`.
- `InstallOptions` carries: a `DatabaseConfig` (or "use existing env" / `skipDatabase`), the skip
  flags (`skipKeys`/`skipCache`), and `force`.
- **Pipeline (order matters — preflight precedes all mutation):**
  1. **DB preflight (if a `DatabaseConfig` is supplied):** `ConnectionTester::test($config)`.
     **On failure: abort immediately — return the failed step; `.env` is not created or touched.**
  2. Ensure `.env` exists (copy from `.env.example`).
  3. Generate keys (via `EnvWriter`).
  4. Persist the `DatabaseConfig` to the engine-specific env keys (via `EnvWriter`).
  5. **Run migrations against a `Connection` built from the *same* `DatabaseConfig`**, injected into
     `MigrationManager` — **not** `Connection::fromContext()` (which would read the stale env and hit
     the old/default connection). `MigrationManager::migrate()` then runs against the tested engine.
  6. Init cache → final validation.
- **`InstallResult` is step-based**: an ordered list of `{step, status, message}` so a UI can render
  progress and a CLI can print it. No stdout-grepping.
- Engine-agnostic: the SQLite zero-config convenience (auto-create the db file) is one engine branch,
  not a special case that excludes the others. (With `--quiet`/use-existing-env and no explicit
  `DatabaseConfig`, the migration connection comes from the already-configured env as today.)
- *(Forward-compat, not implemented now: a `dryRun`/check mode that runs the steps' validations
  without persisting. The step-based result shape is chosen so it can be added without changing the
  contract.)*

### `InstallState`
Read-only "should the installer run?" helpers.
- API: `hasEnv(): bool`, `isDatabaseConfigured(): bool`, `migrationsPending(): bool`.
- **`migrationsPending()` is best-effort and must NOT require a reachable DB** unless DB config
  exists: with **no DB configured it returns `true`** (nothing has been migrated yet) and **never
  throws**, so an app's setup screen isn't noisy or broken before creds are entered. With DB config
  present it reports the real pending state; an unreachable-but-configured DB also returns `true`
  (don't throw).
- The app's own `installed` lock (an admin exists) is separate and stays app-side.

## Framework changes required

- **`MigrationManager` accepts an injected `Connection`.** Today its constructor calls
  `Connection::fromContext($context)` with no override (`MigrationManager.php:93`), so it can only
  use the already-loaded config — which, given immutable dotenv, is *not* the just-entered creds.
  Add an optional `?Connection $connection = null` parameter: `$this->db = $connection ??
  Connection::fromContext($context)`. The `Installer` builds the `Connection` from the tested
  `DatabaseConfig` (`Connection` already supports an explicit `$config` override) and passes it in.
  This is the change that makes invariant #2 hold; behavior is unchanged when no connection is
  injected.
- **`EnvWriter` becomes the single `.env` writer.** Delete both private `updateEnvFile()` copies —
  `InstallCommand.php:531` **and** `Generate\KeyCommand.php:168` (the latter is a second, unquoted
  writer) — and route both commands' key/value writes through `EnvWriter`. Otherwise the unsafe,
  unquoted writer survives in `generate:key` and the "single hardened writer" goal is false.

## Engine → env-key mapping

The DB-config step maps the engine + `DatabaseConfig` onto the framework's existing engine-specific
env keys (observed in the current `configure*Database()` methods + `config/database.php`):

- `mysql` → `DB_DRIVER=mysql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
- `pgsql` → `DB_DRIVER=pgsql`, `DB_PGSQL_HOST`, `DB_PGSQL_PORT`, `DB_PGSQL_DATABASE`,
  `DB_PGSQL_USERNAME`, `DB_PGSQL_PASSWORD`, and the optional **`DB_PGSQL_SCHEMA`** + **`DB_PGSQL_SSL_MODE`**
  (config also supports `prefix`/`timezone`/`role` — written through as optional passthrough when set,
  not prompted by default). These optional pgsql params are part of `DatabaseConfig`, so the tester
  and the migration connection honor them identically (no divergence on a non-public schema or
  SSL-required server).
- `sqlite` → `DB_DRIVER=sqlite`, `DB_SQLITE_DATABASE` (auto-create the file + dir)

This mapping is the single place that knows engine-specific keys; `EnvWriter`/`ConnectionTester`
stay engine-key-agnostic.

## CLI `install` change

- **Reconnect** the orphaned interactive prompts and route them through the seams.
- **Un-SQLite-lock** `setupDatabase()`: for any engine, `ConnectionTester::test()` then migrate;
  keep SQLite's zero-config default + auto-file-create, and the `--quiet`/env-driven path.
- **The command is a wrapper only.** Once prompts (interactive) or env (`--quiet`) are gathered, it
  builds `InstallOptions` and calls `Installer::run()` — **the same path Lemma's setup wizard will
  call** — then renders `InstallResult`. No install logic lives in the command.

## Error handling & idempotency

- A failed `ConnectionTester::test()` aborts **before `.env` is created or any key/cred is written**
  (invariant #1); on a fresh install `.env` does not appear at all. The `InstallResult` reports the
  failed step with the tester's message.
- `EnvWriter` is atomic (temp + rename) and update-or-append, so re-runs (`--force`) are safe and
  unrelated keys/comments are preserved.
- Key generation only writes when a key is missing/placeholder (or `force`).
- Cache-init failure is non-fatal (matches today): warns, continues.

## Testing

- **`EnvWriter`:** quotes/escapes passwords containing space/`#`/`=`/`"`/newline (round-trip);
  updates an existing key in place; appends a missing key at the end; preserves comments + order; the
  write is atomic (no partial file on simulated mid-write failure — assert temp-then-rename).
- **`ConnectionTester`:** returns `ok:true` against a reachable DB (sqlite temp file in unit tests;
  the suite's Postgres in integration); `ok:false` with a clear, password-free message + populated
  `exceptionClass`/`sqlState` on bad creds; asserts **no** `.env`/config/pool mutation.
- **`Installer` — invariant #2 (the critical test):** with a `DatabaseConfig` for a **non-SQLite**
  engine, migrations actually land in **that** database (assert the migration ran against the
  injected connection / the tables exist in the entered DB — *not* the default SQLite), proving
  tested == migrated; pgsql `schema`/`sslMode` set on the `DatabaseConfig` are honored by both the
  tester and the migration connection.
- **`Installer` — invariant #1:** a failed connection test on a fresh install leaves **no `.env`**
  (not created); returns a failed step. `--force` re-run is idempotent; `InstallResult` is
  step-structured.
- **`InstallState`:** `migrationsPending()` does not throw and returns `true` with no DB configured.
- **`generate:key` unification:** `generate:key` writes through `EnvWriter` (quotes values); a grep
  asserts **no** private `updateEnvFile()` remains in `InstallCommand` or `KeyCommand`.
- **CLI `install`:** interactive prompts are wired (the orphaned methods are now reached); `pgsql`
  no longer bails and migrates the configured Postgres; `--quiet` env path still works; the command
  delegates to `Installer::run()`.

## Out of scope (this iteration)

- A **"save anyway"/force-persist-bad-creds** mode — deliberately excluded (the hard invariant).
- `Installer` **dry-run/check** mode — forward-compatible via the step-based result, not built now.
- A **framework setup HTTP endpoint/controller** — apps own their setup surface (boundary above).
- **Admin-user creation / site settings / `installed` lock** — app/extension concerns (Lemma's
  `SetupService`), not core install.

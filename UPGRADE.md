# Upgrade Notes

## Archive extracted to `glueful/archive`

The archive subsystem is no longer part of the framework core. Its code, schema,
console command, config, and capability gate were all removed and now live in the
standalone **`glueful/archive`** extension. If your application uses archiving,
follow these steps.

1. **Require the extension:**

   ```bash
   composer require glueful/archive
   ```

2. **No manual registration needed.** The extension auto-discovers via its
   `extra.glueful` composer manifest entry — its `ServiceProvider` (services,
   routes, migrations, the `archive:manage` command) is wired up automatically.

3. **Run migrations:**

   ```bash
   php glueful migrate:run
   ```

   The archive migration re-runs **once** under its new source `glueful/archive`.
   The migration ledger is keyed on the composite `(source, migration)` pair, so
   the previously-applied core entry (source `glueful/framework:archive`) does not
   match, and the extension's copy is treated as pending. This writes one extra,
   harmless ledger row. The DDL is idempotent, so on a database that already has
   the archive tables this is a no-op (Decision §9 — intended; there is no repair
   tooling to "rename" the old ledger source).

4. **Move local config overrides.** If you published or overrode `config/archive.php`,
   move those overrides to the extension's config / the `ARCHIVE_*` environment
   variables. The core `config/archive.php` no longer exists.

5. **Capability gate moved to the extension.** `ARCHIVE_DATABASE_SCHEMA=true` now
   backs the extension's own `archive.enabled` toggle (instead of the removed core
   `capabilities.archive` gate). If you publish a `config/capabilities.php`, drop
   any `archive` key from it — the core switchboard no longer reads it.

6. **Clear the command cache.** So a cached command manifest doesn't reference the
   removed core `archive:manage` command:

   ```bash
   php glueful cache:clear
   ```

7. **Update imports.** Any application or extension code referencing the archive
   classes must update its namespace:

   ```
   Glueful\Services\Archive\*  →  Glueful\Extensions\Archive\*
   ```

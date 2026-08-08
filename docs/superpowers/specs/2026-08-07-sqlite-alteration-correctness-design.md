# SQLite Alteration Correctness — Design

**Date:** 2026-08-07
**Status:** Approved design, ready for implementation planning
**Roadmap:** item 3 of `docs/DATABASE_NATIVE_ROADMAP.md`, reframed from "SQLite inline-unique
drop" to **SQLite alteration correctness** — fixing one no-op while five other paths can
report false success would preserve the underlying defect.

## Problem

On SQLite, the schema builder currently **fails open**:

- `SQLiteSqlGenerator::modifyColumn()` / `dropColumn()` return SQL *comments*, which
  `PDO::exec()` runs as successful no-ops (`src/Database/Schema/Generators/SQLiteSqlGenerator.php:201-215`).
- `alterTable()` ignores `rename_columns`, `add_foreign_keys`, and `drop_foreign_keys`
  entirely — those changes vanish from the statement list (`:100-144`).
- `addForeignKey()` / `dropForeignKey()` likewise return comments (`:302-317`).
- Upstream, `TableBuilder::executeAlterations()` (`src/Database/Schema/Builders/TableBuilder.php:813`)
  compiles **modified columns as `add_columns`**, and emits no `modify_columns`,
  `rename_columns`, or `drop_foreign_keys` keys at all — so even a correct generator
  would receive a wrong change-set.

A migration using any of these operations "succeeds" on SQLite while doing nothing (or
the wrong thing). The known inline-unique-drop gap (payvia `007` designed its schema to
avoid it) is one symptom.

## Goal — the contract

> **An alteration on SQLite either produces the requested schema completely, or fails
> explicitly and atomically, before or without mutating the original table.**

One table-rebuild mechanism (SQLite's documented create-copy-swap procedure) covers:
modify column, drop column, rename column (when combined — see native dispatch), add
foreign key, drop foreign key, drop inline unique constraint, and **combinations of
those changes within one `alterTable()` call** (exactly one rebuild per call).

The rebuild preserves, when representable: existing row data; primary keys and
**AUTOINCREMENT state including the `sqlite_sequence` high-water mark**; implicit
rowids (explicit policy below); nullability and defaults; unchanged unique constraints
(inline and named); explicit indexes (including partial/expression, replayed verbatim);
foreign keys; CHECK constraints (including framework enum emulation); column ordering;
triggers; and table options (`WITHOUT ROWID`, `STRICT`) if present.

Anything not safely expressible fails **during preflight** — before the temporary table
or the original table is touched — with `UnsupportedSchemaOperationException`.

## Non-goals

- MySQL/PostgreSQL alter paths (already native; unchanged).
- Schema diffing/introspection tooling beyond what the rebuild needs (roadmap item 5).
- Virtual tables, attached or temp schemas, generated columns — **explicitly rejected**
  by the audit, not silently mishandled.
- Making `MigrationManager` transactional (its docblock falsely claims per-migration
  transactions; the docblock is corrected as part of this work, behavior unchanged).

## Components

New namespace `src/Database/Schema/Sqlite/` (core cross-driver DTOs untouched):

| Unit | Responsibility |
|---|---|
| `SqliteSchemaIntrospector` | Read `PRAGMA table_xinfo`, `index_list`, `index_xinfo`, `foreign_key_list`, `sqlite_sequence`, and `sqlite_schema` rows into a snapshot, plus database-wide views, triggers, and inbound FK dependencies. No mutation. |
| `SqliteTableSnapshot` | Lossless value object of one table: columns (declared type, notnull, default, PK ordinal, autoincrement flag), extracted CHECK clauses (column-level and table-level, each with referenced columns), composite-capable PK and FK records, named-index records (name + verbatim SQL), trigger records (name + verbatim SQL), table options (`WITHOUT ROWID`, `STRICT`), `sqlite_sequence.seq` when present, and the original CREATE SQL **as evidence only** — never a mutation substrate. Index and trigger records store *no* precomputed identifier list: the audit derives referenced identifiers on demand by running the scanner over the stored verbatim SQL, so there is exactly one derivation path. Deliberately more expressive than the core DTOs (e.g. composite FKs) so the *audit*, not the introspector, decides what is fatal. |
| `SqliteAlterationPlan` | The canonical change-set (below). |
| `SQLiteTableRebuilder` | Orchestrates preflight audit → plan → execute → verify. Owns the PDO for the operation. Generates the replacement table exclusively through `SQLiteSqlGenerator::createTable()` — no SQL text splicing anywhere. |
| `UnsupportedSchemaOperationException` (`src/Database/Schema/Exceptions/`) | Carries: table, requested operation, unsupported object/feature, and why preservation cannot be guaranteed. |

### The canonical change-set seam

The public alteration path currently constructs `TableBuilder`, whose
`executeAlterations()` mis-compiles the change-set (see Problem), while
`AlterTableBuilder` — which models the full change vocabulary — is effectively
disconnected. This work introduces **one canonical `SqliteAlterationPlan`** produced
from the builder state *before* dispatching to either native SQL or the rebuilder:

- Change vocabulary: `add_columns`, `modify_columns`, `drop_columns`,
  `rename_columns`, `add_indexes`, `drop_indexes`, `add_foreign_keys`,
  `drop_foreign_keys`, `rename_table`. **Unknown change types throw** — nothing is
  ignored.
- The compile defects in `TableBuilder::executeAlterations()` are fixed as part of
  wiring the seam: modified columns compile as modifications, renames and dropped FKs
  are carried through. This compile fix is **driver-neutral** (it corrects the
  change-set every generator receives); `SqliteAlterationPlan` itself is the
  SQLite-side dispatch artifact built from that corrected change-set.
- **Dispatch rule (SQLite):** if the plan touches `modify_columns`, `drop_columns`,
  `add_foreign_keys`, `drop_foreign_keys`, or contains a `drop_indexes` entry naming a
  constraint-backed auto-index (`sqlite_autoindex_%`) → the rebuilder absorbs the
  *entire* plan (one rebuild). An auto-index drop is a rebuild trigger because SQLite
  refuses `DROP INDEX` on indexes that back a constraint; the constraint can only be
  removed by regenerating the table. Otherwise native SQL as today (add column,
  standalone rename column — fixing its silently-dropped status, add index, drop of a
  named `CREATE INDEX` index, rename table).
- **Unique-constraint ownership on modification:** for a **modified** column the
  replacement `ColumnDefinition` is authoritative for that column's uniqueness. A
  single-column unique auto-index covering a modified column is carried into the rebuilt
  table **only** when the replacement declares `unique: true`; otherwise it is dropped.
  This is the inline-unique-drop mechanism — the roadmap's original gap. Unique
  auto-indexes over columns the plan does not modify survive unless their
  `sqlite_autoindex_%` name appears in `drop_indexes`.
- **Rebuilds are procedural, not a SQL string.** `SchemaBuilder::$pendingOperations`
  holds SQL strings executed in order; a rebuild cannot be queued as one. When the
  dispatch selects the rebuilder, all **preceding queued operations are flushed in
  order first**, then the rebuild runs, then queuing resumes.
- Native-only SQLite alteration calls are also procedural when they contain multiple
  statements: preceding SQL is flushed, then the call runs inside one transaction or
  unique savepoint so a later native statement cannot leave earlier changes applied.
- The actual public `TableBuilder` gains table rename. Stored alteration directives
  that remain unsupported in this slice (primary-key changes, table comments, and
  engine/charset/collation-style table options) throw before execution instead of
  disappearing from the compiled change-set.
- **`rename_table` combined with a rebuild:** the rebuild executes under the original
  table name; the rename is applied as a final native `ALTER TABLE … RENAME TO` with
  its own post-verification **inside the same transaction/savepoint, before the final
  foreign-key check and commit**. This *final* rename requires SQLite **3.26.0 or
  newer** and runs with `PRAGMA legacy_alter_table = OFF`: from 3.26.0 onward SQLite
  rewrites inbound foreign keys during a non-legacy rename even while `foreign_keys` is
  OFF, and non-legacy rename behavior also carries the rename into dependent
  trigger/view definitions — which is exactly what a user-visible rename must do. The
  version/pragma gate applies to this final rename only; the rebuild's *internal* swap
  rename runs under the opposite setting (see Atomicity). If the version or pragma
  requirement cannot be met, the combined operation fails preflight; rebuilds without
  `rename_table` remain available. (Rejecting every combination was the alternative;
  renaming last keeps supported combined calls working with no identifier ambiguity
  during replay.)

## Foreign-key strategy

`PRAGMA defer_foreign_keys` is **not** used. With foreign keys enabled, `DROP TABLE`
performs an implicit delete and will execute `CASCADE`, `SET NULL`, or `RESTRICT`
actions on referencing tables; deferral only postpones constraint *checking*, not those
actions — SQLite documents this explicitly. The safe modes are:

1. **No active transaction** (the normal migration path — `MigrationManager` does not
   actually wrap migrations in transactions): `PRAGMA foreign_keys = OFF`, **verify it
   reads back OFF**, then `BEGIN` the rebuild's own transaction. Prior `foreign_keys`
   state is captured first and restored in a `finally`.
2. **Active transaction and `foreign_keys` already OFF:** wrap the rebuild in a
   `SAVEPOINT`; `ROLLBACK TO` + `RELEASE` on failure.
3. **Active transaction and `foreign_keys` ON:** **fail during preflight** with
   `UnsupportedSchemaOperationException` — there is no safe way to rebuild a referenced
   table in this state.

**`PRAGMA foreign_key_check` runs globally, twice** — never the table-scoped form,
which misses foreign keys *declared by other tables* that reference the rebuilt table:

- **Before mutation:** a database with pre-existing violations is rejected up front
  (otherwise post-rebuild verification could not distinguish pre-existing from
  introduced violations).
- **Before commit:** any violation aborts and rolls back.

## Atomicity

- **Preflight fails when `PRAGMA journal_mode` is `OFF`** — SQLite documents rollback
  behavior as undefined in that mode, so the atomicity guarantee cannot be given.
- **`legacy_alter_table` is not one setting for the whole operation — the two renames
  need opposite values.** The prior value is captured once before any mutation and is
  restored and verified in `finally`; restoration failure is surfaced explicitly rather
  than leaving a silently altered connection setting. Between capture and restore:
  - The rebuild's **internal swap rename** (`ALTER TABLE <table>__rebuild_<random>
    RENAME TO <table>`, immediately after `DROP TABLE <table>`) runs with
    `legacy_alter_table = ON`, forced with a verified read-back. With the modern
    default (OFF) SQLite tries to rewrite the bodies of every dependent view and every
    trigger on another table at rename time, and — because the original table has just
    been dropped — fails with `error in view …: no such table: main.<table>`. That
    breaks *every* rebuild of a table any view or external trigger references,
    including cases the preservation audit deliberately permits. The legacy rename is a
    bare rename, which is precisely what the swap wants: the rebuilder itself replays
    the attached triggers and indexes it dropped, and dependent artifacts elsewhere
    still reference the unchanged final name. (Inbound foreign keys are unaffected
    either way while `foreign_keys` is OFF.)
  - The **final user-visible rename** (the `rename_table` plan entry) runs with
    `legacy_alter_table = OFF`, likewise forced with a verified read-back and under the
    SQLite ≥ 3.26.0 gate, because there the non-legacy rewrite of inbound foreign keys
    and dependent trigger/view bodies is exactly the wanted behavior.
- The complete rebuild plan (every statement) is generated **before any DDL executes**.
- Temporary table name is unique per operation (`<table>__rebuild_<random>`), created,
  populated, and swapped inside the transaction/savepoint.
- Data copy uses an **explicit source→target column map**: dropped columns omitted,
  renamed columns mapped old→new, added columns receive their DDL defaults. An implicit
  rowid is copied separately only when it is not already represented by an existing
  `INTEGER PRIMARY KEY` alias (policy below).
- Failure at any step — including verification — rolls back (transaction or savepoint),
  restores `foreign_keys` state, and leaves the original table intact.

### Rowid and AUTOINCREMENT policy

- **Rowid tables:** an existing `INTEGER PRIMARY KEY` alias is copied once through its
  named column and is never duplicated as `rowid`. Otherwise source and target each use
  the first unshadowed pseudocolumn from `rowid`, `_rowid_`, and `oid`; if all three are
  shadowed, preflight fails. If a source alias is removed, its named values seed the
  target implicit rowid. Introducing a new alias where the source had none also fails
  preflight because preservation cannot be guaranteed.
- **AUTOINCREMENT tables:** the snapshot captures `sqlite_sequence.seq`; after the
  swap, the rebuilder restores the sequence row for the table so the high-water mark
  cannot regress (rebuilding from surviving rows alone could lower it and permit reuse
  of deleted ids). Restoration is verified (runtime verification below).
- **`WITHOUT ROWID` tables:** no rowid to preserve; the option itself is carried into
  the regenerated DDL.

## Preservation audit (preflight, fail-closed)

Before any DDL, every existing schema object is audited against the plan. Each failure
throws `UnsupportedSchemaOperationException` naming table, operation, object, and
reason. The audit rejects:

- **Transaction/FK state:** active transaction with `foreign_keys` ON (mode 3 above);
  `journal_mode = OFF`.
- **Rename-pragma capability:** every rebuild needs `legacy_alter_table` forced **ON**
  for its internal swap rename, and a plan carrying `rename_table` additionally needs it
  forced **OFF** for the final user-visible rename plus SQLite **3.26.0 or newer**. A
  value that cannot be set, or whose read-back cannot be verified, fails preflight — as
  does `rename_table` on an older SQLite. The version gate is needed because the rebuild
  runs with `foreign_keys` OFF and older SQLite versions do not reliably rewrite inbound
  FK definitions in that state.
- **CHECK constraints the scanner cannot own.** The CHECK extractor is a real scanner —
  it understands single/double-quoted strings, escaped quotes, bracket/backtick
  identifiers, and `--`/`/* */` comments, not merely balanced parentheses. It
  distinguishes **column-level from table-level** CHECKs and records ownership plus
  referenced identifiers. A CHECK owned by a dropped column is removed with that
  column. Any table-level or cross-column CHECK referencing a dropped/renamed column
  fails closed. The framework enum-emulation shape (`CHECK (<col> IN (…))`, exactly)
  may be rewritten for a rename of its owning column. Ambiguous or unparseable
  expressions fail closed.
- **Indexes/triggers referencing changed identifiers.** Verbatim replay of a named
  index or attached trigger is allowed only when referenced identifiers are unchanged.
  Every database trigger is scanned as well, including triggers attached to another
  table whose body references the altered table. Uncertain trigger dependencies fail
  closed. Partial and expression indexes on untouched columns replay verbatim.
- **Composite unique constraints covering a modified column.** A multi-column unique
  auto-index that includes a column in `modify_columns` fails closed: the replacement
  `ColumnDefinition` is authoritative for that column's *own* uniqueness only and cannot
  express a constraint spanning other columns. The rejection names the auto-index and
  says how to proceed — either drop the constraint in the same call by naming its
  `sqlite_autoindex_%` index in `drop_indexes` (its only handle; the framework's
  `dropUnique(name)` cannot target a constraint-backed auto-index) and restate it
  afterwards with `unique([...])`, or modify the column in a call that leaves the
  composite constraint alone.
- **Inbound foreign keys.** FKs declared by every other table are audited. A rebuild-
  folded modification/drop/column rename of a referenced parent column fails before
  DDL; this slice does not rebuild dependent child tables. Final native table rename is
  the sole supported inbound-FK rewrite and is verified under the 3.26/non-legacy gate.
- **Views.** All `sqlite_schema` view rows are scanned — **not** only rows with
  `tbl_name = <table>`, which SQLite does not maintain dependably for views. A view
  whose SQL references the table combined with any renamed/dropped column, uses
  `SELECT *`, or whose dependency cannot be determined with certainty fails closed. (SQLite
  provides no dependable column-dependency metadata for views; uncertainty is fatal by
  policy.)
- **Unrepresentable structures:** composite foreign keys (snapshot carries them; the
  generator cannot emit them), generated columns (`table_xinfo` hidden ≥ 2), `COLLATE`
  clauses (the framework never emits them on SQLite), constraint `ON CONFLICT`
  clauses, declared column types the generator's type map cannot reproduce, FK
  attributes the generator cannot reproduce (e.g. `MATCH`, deferrable clauses),
  virtual tables, attached or temp schemas.
- **Unknown change types** in the plan.

## Two equivalence gates (deliberately separate)

1. **Test-time model gate** (locks the schema model itself):
   `introspect(original) → regenerate DDL into a scratch database → introspect` must
   equal the original canonical snapshot — for every table shape the framework's
   builder can produce. This is the acceptance test proving the DTO/generator
   round-trip is lossless *before* the rebuilder trusts it.
2. **Runtime verification** (locks each real rebuild): after the swap, re-introspect
   the rebuilt table and compare canonically against the **planned target** — plus
   equality of every preserved index (by name and SQL), trigger, table option,
   `sqlite_sequence` value, and unaffected schema artifact. The expected target shape is
   materialized by applying the planned DDL in a scratch `:memory:` database, and that
   scratch database covers **only** the table shape (columns, CHECKs, PK, FKs, options)
   and named-index DDL. **Triggers are never replayed into the scratch database** — a
   trigger body may reference other tables that do not exist there, which would throw
   inside verification. Triggers are instead verified by canonical SQL comparison
   (whitespace-normalized) of the live database's post-swap trigger rows against the
   preflight snapshot. Any mismatch aborts and rolls back. When the plan includes `rename_table`, verification additionally scans
   all inbound foreign-key definitions and every dependent trigger/view definition:
   each must reference the final table name, none may retain the original name, and
   their canonical semantics must otherwise equal the preflight snapshot. The final
   table snapshot alone is insufficient because inbound FKs live on other tables.

## Behavior deltas (the point of the feature)

1. `modify_columns`, `drop_columns`, `add_foreign_keys`, `drop_foreign_keys` on SQLite
   perform real rebuilds instead of silently no-opping.
2. `rename_columns` on SQLite executes (native `RENAME COLUMN`, or folded into a
   rebuild) instead of being silently discarded.
3. Inline unique constraints can be dropped (via `modify_columns`) — the original
   roadmap gap.
4. Unsupported operations throw `UnsupportedSchemaOperationException` **before
   mutation** instead of emitting comment SQL. Migrations that previously "passed" on
   SQLite by doing nothing will now fail loudly — intended, and called out in Upgrade
   Notes.
5. `TableBuilder`'s alteration compile defects (modify-as-add, dropped renames/FK
   drops) are fixed via the canonical plan seam — this also corrects the change-sets
   MySQL/PostgreSQL generators receive from that path, strictly making previously
   dropped changes take effect.

## Testing

- **Model gate:** the scratch-DB round-trip test across the builder's expressible
  surface (plain columns, enum CHECK, explicit CHECK, inline + named uniques,
  composite indexes, FKs with actions, AUTOINCREMENT, defaults incl. expressions the
  builder emits).
- **Per-operation:** each formerly-silent operation now works or throws — modify type,
  modify nullability, modify default, drop inline unique, drop column, rename column
  (native and combined), add FK, drop FK.
- **Modify-column uniqueness rule:** modifying a column whose replacement omits
  `unique` drops its inline unique constraint (a previously rejected duplicate now
  inserts); modifying the same column with `unique: true` keeps it (the duplicate still
  fails); a composite unique constraint covering a modified column fails closed before
  mutation.
- **Combination:** one `alterTable()` call mixing add/modify/drop/rename/index/FK
  changes performs exactly **one** rebuild (assert via a dispatch spy; procedural work
  is not visible to SQL preview), and the result matches the target. A failing native
  multi-statement call rolls back all statements from that call.
- **Combined table rename:** rebuild + `rename_table` succeeds on SQLite ≥ 3.26.0 with
  `legacy_alter_table` initially both OFF and ON; the prior pragma state (the original
  value, not either forced value) is restored, inbound FKs and dependent trigger/view
  SQL reference only the final name, and the final schema matches the target. SQLite
  < 3.26.0 and an unverifiable/unsettable `legacy_alter_table` fail before mutation
  while a non-rename rebuild still works.
- **Swap under dependents:** a plain rebuild (no `rename_table`) of a table referenced
  by a view and by a trigger on another table succeeds with `legacy_alter_table`
  initially OFF — the regression the internal swap's forced-ON policy exists to
  prevent — and the original pragma value is restored afterwards.
- **Preservation:** row data (incl. rowids and `INTEGER PRIMARY KEY` alias values),
  `sqlite_sequence` high-water mark, enum CHECKs, partial/expression indexes on
  untouched columns, triggers, `WITHOUT ROWID`/`STRICT` options survive.
- **Fail-closed:** each audit rejection (CHECK on renamed column, index on dropped
  column, composite FK, composite unique over a modified column, generated column,
  COLLATE, view dependency, in-transaction with FK ON, `journal_mode=OFF`, unknown
  change type) throws before any mutation — asserted by comparing full `sqlite_schema`
  before/after the exception.
- **Atomicity:** injected copy failure (e.g. NOT NULL violation on copy) and injected
  verification mismatch both roll back, original table intact, `foreign_keys` state
  restored; every rebuild failure — with or without a combined rename — restores the
  original `legacy_alter_table` value; pre-existing FK violations are rejected up front
  by the first global `foreign_key_check`.
- **No silent success:** grep-level test that the SQLite generator no longer returns
  comment-only statements for any alter operation.

## Bookkeeping

- CHANGELOG `[Unreleased]`: Added (rebuild engine + exception), Changed (fail-closed
  posture, TableBuilder compile fixes), Fixed (six silent no-op paths), Upgrade Notes
  (migrations that silently no-opped now fail or take effect).
- `docs/DATABASE_NATIVE_ROADMAP.md`: item 3 marked done on completion, text updated to
  the "alteration correctness" framing.
- `MigrationManager` docblock corrected (no per-migration transaction exists).

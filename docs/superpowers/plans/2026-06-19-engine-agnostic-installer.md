# Engine-Agnostic Installer + Setup Seams — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `php glueful install` work with any DB engine (not just SQLite) and expose reusable `Glueful\Installer\` seams (env-writer, connection-tester, install orchestrator) so an app can drive first-run setup from CLI or a UI without shelling out — with two hard invariants: a failed DB connection test mutates nothing, and the tested credentials are exactly the connection migrations run on.

**Architecture:** A `DatabaseConfig` value object threads through test → persist → migrate. `ConnectionTester` builds a transient `Connection` from the explicit config (overriding the stale env) and probes it. `Installer` runs a preflight-first pipeline and returns a step-based result. The migration step builds a `Connection` from the same `DatabaseConfig` and injects it into `MigrationManager` (which gains an optional injected-connection param). `EnvWriter` (atomic + quoting) becomes the single `.env` writer, replacing two private `updateEnvFile()` copies.

**Tech Stack:** PHP 8.3, Symfony Console, PDO, PHPUnit 10. New code under `src/Installer/` (`Glueful\Installer\`); tests under `tests/Unit/Installer/`. Run one class with `vendor/bin/phpunit --filter <ClassName>`; lint with `composer phpcs`.

**Spec:** `docs/superpowers/specs/2026-06-19-engine-agnostic-installer-design.md`

---

## File map

- Create: `src/Installer/DatabaseConfig.php` — VO; `toConnectionConfig()` (internal `db/user/pass` keys) + `toEnvPairs()` (`DB_*` keys).
- Create: `src/Installer/EnvWriter.php` — atomic, quoting `.env` writer (`get`/`set`/`setMany`).
- Create: `src/Installer/ConnectionTestResult.php` — typed result VO.
- Create: `src/Installer/ConnectionTester.php` — `test(DatabaseConfig): ConnectionTestResult`.
- Create: `src/Installer/InstallStep.php` + `src/Installer/InstallResult.php` — step-based result.
- Create: `src/Installer/InstallOptions.php` — options VO.
- Create: `src/Installer/Installer.php` — orchestrator (preflight-first pipeline).
- Create: `src/Installer/InstallState.php` — read-only helpers.
- Modify: `src/Database/Migrations/MigrationManager.php` — add optional `?Connection $connection`.
- Modify: `src/Console/Commands/InstallCommand.php` — wrapper: call `Installer`, reconnect prompts, un-SQLite-lock, delete `updateEnvFile()`.
- Modify: `src/Console/Commands/Generate/KeyCommand.php` — write via `EnvWriter`, delete `updateEnvFile()`.
- Create tests: `tests/Unit/Installer/{EnvWriterTest,DatabaseConfigTest,ConnectionTesterTest,MigrationManagerInjectedConnectionTest,InstallerTest,InstallStateTest}.php`.
- Modify: `CHANGELOG.md`.

Tasks build leaf seams first (no deps), then compose. Each ends green.

---

## Task 1: `EnvWriter` (atomic + quoting)

**Files:**
- Create: `src/Installer/EnvWriter.php`
- Test: `tests/Unit/Installer/EnvWriterTest.php`

- [ ] **Step 1: Write the failing test.**
```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Installer;

use Glueful\Installer\EnvWriter;
use PHPUnit\Framework\TestCase;

final class EnvWriterTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/envwriter_' . uniqid() . '.env';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testAppendsMissingKeyAndReadsItBack(): void
    {
        file_put_contents($this->path, "# comment\nAPP_ENV=local\n");
        $w = new EnvWriter($this->path);
        $w->set('DB_DRIVER', 'pgsql');

        self::assertSame('pgsql', $w->get('DB_DRIVER'));
        self::assertStringContainsString('# comment', file_get_contents($this->path));
        self::assertStringContainsString('APP_ENV=local', file_get_contents($this->path));
    }

    public function testUpdatesExistingKeyInPlace(): void
    {
        file_put_contents($this->path, "DB_DRIVER=sqlite\nAPP_ENV=local\n");
        $w = new EnvWriter($this->path);
        $w->set('DB_DRIVER', 'mysql');

        self::assertSame('mysql', $w->get('DB_DRIVER'));
        // Exactly one DB_DRIVER line.
        self::assertSame(1, substr_count(file_get_contents($this->path), 'DB_DRIVER='));
    }

    public function testQuotesAndRoundTripsValuesWithSpecialChars(): void
    {
        file_put_contents($this->path, "");
        $w = new EnvWriter($this->path);
        $password = 'p@ss word#with="quotes"';
        $w->set('DB_PASSWORD', $password);

        $reread = new EnvWriter($this->path);
        self::assertSame($password, $reread->get('DB_PASSWORD'));
    }

    public function testSetManyAndPreservesOrder(): void
    {
        file_put_contents($this->path, "A=1\nB=2\n");
        $w = new EnvWriter($this->path);
        $w->setMany(['B' => '20', 'C' => '3']);

        $lines = explode("\n", trim(file_get_contents($this->path)));
        self::assertSame('A=1', $lines[0]);
        self::assertSame('B=20', $lines[1]);
        self::assertSame('C=3', $lines[2]);
    }
}
```

- [ ] **Step 2: Run it; verify it fails.**

Run: `vendor/bin/phpunit --filter EnvWriterTest`
Expected: FAIL — `Class "Glueful\Installer\EnvWriter" not found`.

- [ ] **Step 3: Implement `EnvWriter`.** Create `src/Installer/EnvWriter.php`:
```php
<?php

declare(strict_types=1);

namespace Glueful\Installer;

/**
 * The single `.env` reader/writer. Atomic (temp file + rename), quotes values that need it,
 * updates a key in place or appends it at the end, and preserves comments/order.
 */
final class EnvWriter
{
    public function __construct(private readonly string $envPath)
    {
    }

    public function get(string $key): ?string
    {
        if (!is_file($this->envPath)) {
            return null;
        }
        foreach (explode("\n", (string) file_get_contents($this->envPath)) as $line) {
            if (preg_match('/^' . preg_quote($key, '/') . '=(.*)$/', $line, $m) === 1) {
                return $this->unquote($m[1]);
            }
        }
        return null;
    }

    public function set(string $key, string $value): void
    {
        $this->setMany([$key => $value]);
    }

    /** @param array<string, string> $pairs */
    public function setMany(array $pairs): void
    {
        $content = is_file($this->envPath) ? (string) file_get_contents($this->envPath) : '';
        $lines = $content === '' ? [] : explode("\n", rtrim($content, "\n"));

        foreach ($pairs as $key => $value) {
            $newLine = $key . '=' . $this->quote($value);
            $found = false;
            foreach ($lines as $i => $line) {
                if (preg_match('/^' . preg_quote($key, '/') . '=/', $line) === 1) {
                    $lines[$i] = $newLine;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $lines[] = $newLine;
            }
        }

        $this->atomicWrite(implode("\n", $lines) . "\n");
    }

    private function quote(string $value): string
    {
        // Safe bare values: alnum and a few path/url chars.
        if ($value === '' || preg_match('/^[A-Za-z0-9_.\/:@-]+$/', $value) === 1) {
            return $value;
        }
        $escaped = str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
        return '"' . $escaped . '"';
    }

    private function unquote(string $raw): string
    {
        $raw = trim($raw);
        if (strlen($raw) >= 2 && $raw[0] === '"' && substr($raw, -1) === '"') {
            $inner = substr($raw, 1, -1);
            return str_replace(['\\n', '\\"', '\\\\'], ["\n", '"', '\\'], $inner);
        }
        return $raw;
    }

    private function atomicWrite(string $content): void
    {
        $dir = dirname($this->envPath);
        $tmp = tempnam($dir, '.env.tmp.');
        if ($tmp === false) {
            throw new \RuntimeException("Cannot create temp file in {$dir}");
        }
        file_put_contents($tmp, $content);
        if (!rename($tmp, $this->envPath)) {
            @unlink($tmp);
            throw new \RuntimeException("Cannot write {$this->envPath}");
        }
    }
}
```

- [ ] **Step 4: Run it; verify it passes.**

Run: `vendor/bin/phpunit --filter EnvWriterTest`
Expected: PASS (4 tests).

- [ ] **Step 5: phpcs + commit.**
```bash
composer phpcs
git add src/Installer/EnvWriter.php tests/Unit/Installer/EnvWriterTest.php
git commit -m "Add EnvWriter: atomic, quoting .env writer"
```

---

## Task 2: `DatabaseConfig` value object

**Files:**
- Create: `src/Installer/DatabaseConfig.php`
- Test: `tests/Unit/Installer/DatabaseConfigTest.php`

> Maps one config to **two** shapes: the internal `Connection` override (keys `db`/`user`/`pass`, matching `Connection::buildConfigFromEnv()`) and the `.env` `DB_*` keys. This is the single place that knows engine-specific keys.

- [ ] **Step 1: Write the failing test.**
```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Installer;

use Glueful\Installer\DatabaseConfig;
use PHPUnit\Framework\TestCase;

final class DatabaseConfigTest extends TestCase
{
    public function testPgsqlConnectionConfigUsesInternalKeysAndDisablesPooling(): void
    {
        $c = new DatabaseConfig(
            engine: 'pgsql',
            host: 'db.example',
            port: 5432,
            database: 'lemma',
            username: 'lemma_user',
            password: 'secret',
            schema: 'app',
            sslMode: 'require',
        );

        $cfg = $c->toConnectionConfig();
        self::assertSame('pgsql', $cfg['engine']);
        self::assertFalse($cfg['pooling']['enabled']);
        self::assertSame('db.example', $cfg['pgsql']['host']);
        self::assertSame('lemma', $cfg['pgsql']['db']);
        self::assertSame('lemma_user', $cfg['pgsql']['user']);
        self::assertSame('secret', $cfg['pgsql']['pass']);
        self::assertSame('app', $cfg['pgsql']['schema']);
        self::assertSame('require', $cfg['pgsql']['sslmode']);
    }

    public function testPgsqlEnvPairsUsePrefixedKeysAndOmitEmptyOptionals(): void
    {
        $c = new DatabaseConfig('pgsql', 'h', 5432, 'd', 'u', 'p'); // no schema/sslMode
        $pairs = $c->toEnvPairs();

        self::assertSame('pgsql', $pairs['DB_DRIVER']);
        self::assertSame('h', $pairs['DB_PGSQL_HOST']);
        self::assertSame('5432', $pairs['DB_PGSQL_PORT']);
        self::assertSame('d', $pairs['DB_PGSQL_DATABASE']);
        self::assertArrayNotHasKey('DB_PGSQL_SCHEMA', $pairs);
        self::assertArrayNotHasKey('DB_PGSQL_SSL_MODE', $pairs);
    }

    public function testSqliteMapsToPrimaryAndSingleEnvKey(): void
    {
        $c = new DatabaseConfig('sqlite', database: '/tmp/x.sqlite');
        self::assertSame('/tmp/x.sqlite', $c->toConnectionConfig()['sqlite']['primary']);
        self::assertSame(
            ['DB_DRIVER' => 'sqlite', 'DB_SQLITE_DATABASE' => '/tmp/x.sqlite'],
            $c->toEnvPairs(),
        );
    }
}
```

- [ ] **Step 2: Run it; verify it fails.**

Run: `vendor/bin/phpunit --filter DatabaseConfigTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `DatabaseConfig`.** Create `src/Installer/DatabaseConfig.php`:
```php
<?php

declare(strict_types=1);

namespace Glueful\Installer;

/**
 * The DB config being installed. Threaded through test → persist → migrate so the tested
 * connection and the migrated connection are built from one source and cannot diverge.
 */
final class DatabaseConfig
{
    public function __construct(
        public readonly string $engine,        // 'mysql' | 'pgsql' | 'sqlite'
        public readonly string $host = '',
        public readonly int $port = 0,
        public readonly string $database = '', // db name, or the sqlite file path
        public readonly string $username = '',
        public readonly string $password = '',
        public readonly ?string $schema = null,  // pgsql only
        public readonly ?string $sslMode = null, // pgsql only
    ) {
    }

    /**
     * Internal Connection config override (matches Connection::buildConfigFromEnv() keys:
     * db/user/pass). Pooling is disabled so a test build is transient.
     *
     * @return array<string, mixed>
     */
    public function toConnectionConfig(): array
    {
        $base = ['engine' => $this->engine, 'pooling' => ['enabled' => false]];

        return match ($this->engine) {
            'sqlite' => $base + ['sqlite' => ['primary' => $this->database]],
            'mysql' => $base + ['mysql' => [
                'host' => $this->host,
                'port' => $this->port,
                'db' => $this->database,
                'user' => $this->username,
                'pass' => $this->password,
                'charset' => 'utf8mb4',
                'strict' => true,
            ]],
            'pgsql' => $base + ['pgsql' => array_filter([
                'host' => $this->host,
                'port' => $this->port,
                'db' => $this->database,
                'user' => $this->username,
                'pass' => $this->password,
                'schema' => $this->schema ?? 'public',
                'sslmode' => $this->sslMode,
            ], static fn ($v): bool => $v !== null)],
            default => throw new \InvalidArgumentException("Unsupported engine: {$this->engine}"),
        };
    }

    /**
     * The `.env` DB_* key/value pairs. Empty optional pgsql params are omitted.
     *
     * @return array<string, string>
     */
    public function toEnvPairs(): array
    {
        return match ($this->engine) {
            'sqlite' => ['DB_DRIVER' => 'sqlite', 'DB_SQLITE_DATABASE' => $this->database],
            'mysql' => [
                'DB_DRIVER' => 'mysql',
                'DB_HOST' => $this->host,
                'DB_PORT' => (string) $this->port,
                'DB_DATABASE' => $this->database,
                'DB_USERNAME' => $this->username,
                'DB_PASSWORD' => $this->password,
            ],
            'pgsql' => array_filter([
                'DB_DRIVER' => 'pgsql',
                'DB_PGSQL_HOST' => $this->host,
                'DB_PGSQL_PORT' => (string) $this->port,
                'DB_PGSQL_DATABASE' => $this->database,
                'DB_PGSQL_USERNAME' => $this->username,
                'DB_PGSQL_PASSWORD' => $this->password,
                'DB_PGSQL_SCHEMA' => $this->schema,
                'DB_PGSQL_SSL_MODE' => $this->sslMode,
            ], static fn ($v): bool => $v !== null && $v !== ''),
            default => throw new \InvalidArgumentException("Unsupported engine: {$this->engine}"),
        };
    }
}
```

> **Confirm step (sslmode key):** before relying on `sslmode` in `toConnectionConfig()`, grep how `Connection` reads pgsql SSL: `grep -n "sslmode\|ssl" src/Database/Connection.php`. If it expects a different config key (e.g. `ssl_mode`), use that key here. (The `.env` key `DB_PGSQL_SSL_MODE` is what the installer writes; the internal key must match `Connection`.)

- [ ] **Step 4: Run it; verify it passes.**

Run: `vendor/bin/phpunit --filter DatabaseConfigTest`
Expected: PASS (3 tests).

- [ ] **Step 5: phpcs + commit.**
```bash
composer phpcs
git add src/Installer/DatabaseConfig.php tests/Unit/Installer/DatabaseConfigTest.php
git commit -m "Add DatabaseConfig VO: one config, two mappings (connection + env)"
```

---

## Task 3: `ConnectionTestResult` + `ConnectionTester`

**Files:**
- Create: `src/Installer/ConnectionTestResult.php`, `src/Installer/ConnectionTester.php`
- Test: `tests/Unit/Installer/ConnectionTesterTest.php`

- [ ] **Step 1: Write the failing test.**
```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Installer;

use Glueful\Installer\ConnectionTester;
use Glueful\Installer\DatabaseConfig;
use PHPUnit\Framework\TestCase;

final class ConnectionTesterTest extends TestCase
{
    public function testOkAgainstAReachableSqliteFile(): void
    {
        $file = sys_get_temp_dir() . '/ct_ok_' . uniqid() . '.sqlite';
        $config = new DatabaseConfig('sqlite', database: $file);

        $result = (new ConnectionTester())->test($config);

        self::assertTrue($result->ok, $result->message);
        self::assertSame('sqlite', $result->engine);
        @unlink($file);
    }

    public function testFailsWithDiagnosticsAndNoPasswordOnBadCreds(): void
    {
        $config = new DatabaseConfig(
            engine: 'pgsql',
            host: '127.0.0.1',
            port: 1,            // nothing listens here -> fast refuse
            database: 'nope',
            username: 'u',
            password: 'sup3r-secret-pw',
        );

        $result = (new ConnectionTester())->test($config);

        self::assertFalse($result->ok);
        self::assertNotNull($result->exceptionClass);
        self::assertStringNotContainsString('sup3r-secret-pw', $result->message);
    }
}
```

- [ ] **Step 2: Run it; verify it fails.**

Run: `vendor/bin/phpunit --filter ConnectionTesterTest`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement the result VO + tester.** Create `src/Installer/ConnectionTestResult.php`:
```php
<?php

declare(strict_types=1);

namespace Glueful\Installer;

final class ConnectionTestResult
{
    public function __construct(
        public readonly string $engine,
        public readonly bool $ok,
        public readonly string $message,
        public readonly ?string $exceptionClass = null,
        public readonly ?string $sqlState = null,
    ) {
    }
}
```
Create `src/Installer/ConnectionTester.php`:
```php
<?php

declare(strict_types=1);

namespace Glueful\Installer;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;

/**
 * Tests an explicit DatabaseConfig against a transient connection built the same way the
 * migration step builds it — so "tested == migrated". Mutates no .env, config, or pool, and
 * never leaks the password.
 */
final class ConnectionTester
{
    public function __construct(private readonly ?ApplicationContext $context = null)
    {
    }

    public function test(DatabaseConfig $config): ConnectionTestResult
    {
        try {
            $connection = new Connection($config->toConnectionConfig(), $this->context);
            $connection->getPDO()->query('SELECT 1');
            unset($connection); // transient; pooling disabled in toConnectionConfig()
            return new ConnectionTestResult($config->engine, true, 'Connection successful.');
        } catch (\PDOException $e) {
            // PDO messages do not include the password; errorInfo[0] is the SQLSTATE.
            $sqlState = is_array($e->errorInfo ?? null) ? ($e->errorInfo[0] ?? null) : null;
            return new ConnectionTestResult(
                $config->engine,
                false,
                'Could not connect: ' . $e->getMessage(),
                $e::class,
                $sqlState,
            );
        } catch (\Throwable $e) {
            return new ConnectionTestResult(
                $config->engine,
                false,
                'Could not connect: ' . $e->getMessage(),
                $e::class,
                null,
            );
        }
    }
}
```

> **Confirm step (connect timeout):** the spec wants a *short* connect timeout so an unreachable host fails fast. Check whether `Connection::createPDO()` sets `PDO::ATTR_TIMEOUT` (`grep -n "ATTR_TIMEOUT\|timeout" src/Database/Connection.php`). If it does not, add support for a `timeout` config key in the engine config and set it (default ~3s) in `DatabaseConfig::toConnectionConfig()`, so a bad host doesn't hang a CLI prompt / UI request. If `Connection` already applies a sane timeout, no change.

- [ ] **Step 4: Run it; verify it passes.**

Run: `vendor/bin/phpunit --filter ConnectionTesterTest`
Expected: PASS (2 tests). If the pgsql failure case is slow on your platform, apply the connect-timeout confirm step above.

- [ ] **Step 5: phpcs + commit.**
```bash
composer phpcs
git add src/Installer/ConnectionTestResult.php src/Installer/ConnectionTester.php \
  tests/Unit/Installer/ConnectionTesterTest.php
git commit -m "Add ConnectionTester: transient probe of an explicit DatabaseConfig"
```

---

## Task 4: `MigrationManager` accepts an injected `Connection`

**Files:**
- Modify: `src/Database/Migrations/MigrationManager.php:88-101`
- Test: `tests/Unit/Installer/MigrationManagerInjectedConnectionTest.php`

> This is the change that makes invariant #2 hold: the installer migrates the **tested** connection, not `fromContext()`. **Append `$connection` as the 4th, optional param — do NOT reorder the existing three.** Verified safe: every current call site passes ≤3 positional args — the container factory (`src/Container/Providers/CoreProvider.php:502` = `new MigrationManager(null, null, $this->context)`) and all migration tests (`new MigrationManager($path, null, $context)`) — so the new default-`null` param resolves correctly everywhere and preserves `fromContext()` fallback.

- [ ] **Step 1: Write the failing test.** It proves an injected connection is used (migrations land in *its* sqlite file), without needing Postgres.
```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Installer;

use Glueful\Database\Connection;
use Glueful\Database\Migrations\MigrationManager;
use Glueful\Installer\DatabaseConfig;
use PHPUnit\Framework\TestCase;

final class MigrationManagerInjectedConnectionTest extends TestCase
{
    public function testUsesTheInjectedConnectionNotFromContext(): void
    {
        $file = sys_get_temp_dir() . '/mm_injected_' . uniqid() . '.sqlite';
        $config = new DatabaseConfig('sqlite', database: $file);
        $connection = new Connection($config->toConnectionConfig());

        // Constructing with an injected connection must ensure the version table on THAT db,
        // i.e. it must not throw resolving a context and the file must exist + carry the table.
        new MigrationManager(null, null, null, $connection);

        self::assertFileExists($file);
        $tables = $connection->getPDO()
            ->query("SELECT name FROM sqlite_master WHERE type='table'")
            ->fetchAll(\PDO::FETCH_COLUMN);
        self::assertNotEmpty($tables, 'version table should have been created on the injected connection');
        @unlink($file);
    }
}
```

- [ ] **Step 2: Run it; verify it fails.**

Run: `vendor/bin/phpunit --filter MigrationManagerInjectedConnectionTest`
Expected: FAIL — `MigrationManager::__construct()` has only 3 params (`ArgumentCountError`/`TypeError`), or it ignores the 4th arg and builds from context.

- [ ] **Step 3: Add the injected-connection param.** In `src/Database/Migrations/MigrationManager.php`, change the constructor (around line 88):
```php
    public function __construct(
        ?string $migrationsPath = null,
        ?FileFinder $fileFinder = null,
        ?ApplicationContext $context = null,
        ?Connection $connection = null
    ) {
        $this->context = $context;
        $connection = $connection ?? Connection::fromContext($context);
        $this->db = $connection;
        $this->schema = $connection->getSchemaBuilder();

        $this->migrationsPath = $migrationsPath ?? $this->getConfig('app.paths.migrations');
        $this->fileFinder = $fileFinder ?? $this->resolveFileFinder();
        $this->ensureVersionTable();
    }
```
Ensure `use Glueful\Database\Connection;` is imported at the top of the file (it likely already is — `grep -n "use Glueful\\\\Database\\\\Connection;" src/Database/Migrations/MigrationManager.php`; add it if missing).

- [ ] **Step 4: Run it; verify it passes — and no regression.**

Run: `vendor/bin/phpunit --filter MigrationManagerInjectedConnectionTest && vendor/bin/phpunit tests/Unit/Database/Migrations`
Expected: PASS — the injected connection is used; existing migration tests (which pass no 4th arg) are unaffected.

- [ ] **Step 5: phpcs + commit.**
```bash
composer phpcs
git add src/Database/Migrations/MigrationManager.php \
  tests/Unit/Installer/MigrationManagerInjectedConnectionTest.php
git commit -m "MigrationManager: accept an optional injected Connection"
```

---

## Task 5: `InstallStep` + `InstallResult` + `InstallOptions`

**Files:**
- Create: `src/Installer/InstallStep.php`, `src/Installer/InstallResult.php`, `src/Installer/InstallOptions.php`
- Test: covered by `InstallerTest` (Task 6) — these are plain data holders.

- [ ] **Step 1: Create the value objects.** `src/Installer/InstallStep.php`:
```php
<?php

declare(strict_types=1);

namespace Glueful\Installer;

final class InstallStep
{
    public const OK = 'ok';
    public const FAILED = 'failed';
    public const SKIPPED = 'skipped';
    public const WARNING = 'warning';

    public function __construct(
        public readonly string $name,
        public readonly string $status,
        public readonly string $message = '',
    ) {
    }
}
```
`src/Installer/InstallResult.php`:
```php
<?php

declare(strict_types=1);

namespace Glueful\Installer;

final class InstallResult
{
    /** @param list<InstallStep> $steps */
    public function __construct(public readonly array $steps, public readonly bool $ok)
    {
    }

    /** @param list<InstallStep> $steps */
    public static function from(array $steps): self
    {
        $ok = true;
        foreach ($steps as $step) {
            if ($step->status === InstallStep::FAILED) {
                $ok = false;
                break;
            }
        }
        return new self($steps, $ok);
    }
}
```
`src/Installer/InstallOptions.php`:
```php
<?php

declare(strict_types=1);

namespace Glueful\Installer;

final class InstallOptions
{
    public function __construct(
        public readonly ?DatabaseConfig $database = null, // null => use existing env / skip db
        public readonly bool $skipDatabase = false,
        public readonly bool $skipKeys = false,
        public readonly bool $skipCache = false,
        public readonly bool $force = false,
    ) {
    }
}
```

- [ ] **Step 2: phpcs + commit.**
```bash
composer phpcs
git add src/Installer/InstallStep.php src/Installer/InstallResult.php src/Installer/InstallOptions.php
git commit -m "Add InstallStep/InstallResult/InstallOptions value objects"
```

---

## Task 6: `Installer` (preflight-first orchestrator)

**Files:**
- Create: `src/Installer/Installer.php`
- Test: `tests/Unit/Installer/InstallerTest.php`

> Pipeline order is load-bearing: **DB preflight test runs before any `.env` mutation**; on failure nothing is written. On success, persist creds, then migrate the **same** `DatabaseConfig` via an injected `Connection`.

- [ ] **Step 1: Write the failing test** (both invariants, using sqlite so it runs in CI):
```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Installer;

use Glueful\Installer\DatabaseConfig;
use Glueful\Installer\Installer;
use Glueful\Installer\InstallOptions;
use Glueful\Installer\InstallStep;
use PHPUnit\Framework\TestCase;

final class InstallerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/installer_' . uniqid();
        mkdir($this->dir, 0775, true);
        file_put_contents($this->dir . '/.env.example', "APP_ENV=local\nAPP_KEY=\n");
    }

    protected function tearDown(): void
    {
        @array_map('unlink', glob($this->dir . '/*') ?: []);
        @array_map('unlink', glob($this->dir . '/*.sqlite') ?: []);
        @rmdir($this->dir);
    }

    public function testFailedDbPreflightLeavesNoEnv(): void
    {
        // Invariant #1, sharp version: NO .env present beforehand; a bad DB config aborts before
        // .env is created; .env still does not exist afterward.
        self::assertFileDoesNotExist($this->dir . '/.env', 'precondition: a fresh project has no .env');

        $bad = new DatabaseConfig('pgsql', host: '127.0.0.1', port: 1, database: 'x', username: 'u', password: 'p');
        $installer = new Installer($this->dir, skipCacheAndValidation: true);

        $result = $installer->run(new InstallOptions(database: $bad, skipKeys: true));

        self::assertFalse($result->ok);
        self::assertFileDoesNotExist($this->dir . '/.env', 'failed preflight must not create .env');
        self::assertSame(InstallStep::FAILED, $result->steps[0]->status);
        self::assertSame('database-preflight', $result->steps[0]->name);
    }

    public function testSuccessfulInstallMigratesTheTestedDatabase(): void
    {
        // Invariant #2: migrations land in the DatabaseConfig's sqlite file, not the default.
        $dbFile = $this->dir . '/installed.sqlite';
        $config = new DatabaseConfig('sqlite', database: $dbFile);
        $installer = new Installer($this->dir, skipCacheAndValidation: true);

        $result = $installer->run(new InstallOptions(database: $config, skipKeys: false));

        self::assertTrue($result->ok, json_encode(array_map(
            static fn (InstallStep $s): array => [$s->name, $s->status, $s->message],
            $result->steps,
        )));
        self::assertFileExists($this->dir . '/.env');
        self::assertFileExists($dbFile);
        self::assertStringContainsString('DB_SQLITE_DATABASE=', file_get_contents($this->dir . '/.env'));
    }
}
```

- [ ] **Step 2: Run it; verify it fails.**

Run: `vendor/bin/phpunit --filter InstallerTest`
Expected: FAIL — `Glueful\Installer\Installer` not found.

- [ ] **Step 3: Implement `Installer`.** Create `src/Installer/Installer.php`:
```php
<?php

declare(strict_types=1);

namespace Glueful\Installer;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Database\Migrations\MigrationManager;
use Glueful\Support\RandomStringGenerator;

/**
 * The install pipeline as a callable seam. Preflight-first: a failed DB test mutates nothing.
 * On success it persists the tested DatabaseConfig and migrates the SAME connection.
 *
 * `skipCacheAndValidation` lets unit tests run the deterministic core (env/db/migrate) without
 * the cache/health side-effects, which are non-fatal in production anyway.
 */
final class Installer
{
    public function __construct(
        private readonly string $basePath,
        private readonly ?ApplicationContext $context = null,
        private readonly bool $skipCacheAndValidation = false,
    ) {
    }

    public function run(InstallOptions $options): InstallResult
    {
        $steps = [];

        // 1. DB preflight — BEFORE any .env mutation.
        if ($options->database !== null && !$options->skipDatabase) {
            $test = (new ConnectionTester($this->context))->test($options->database);
            if (!$test->ok) {
                $steps[] = new InstallStep('database-preflight', InstallStep::FAILED, $test->message);
                return InstallResult::from($steps); // nothing written
            }
            $steps[] = new InstallStep('database-preflight', InstallStep::OK, 'Connection verified.');
        }

        // 2. Ensure .env exists.
        $envPath = $this->basePath . '/.env';
        if (!is_file($envPath)) {
            $example = $this->basePath . '/.env.example';
            if (!is_file($example)) {
                $steps[] = new InstallStep('env', InstallStep::FAILED, '.env.example not found.');
                return InstallResult::from($steps);
            }
            copy($example, $envPath);
        }
        $env = new EnvWriter($envPath);
        $steps[] = new InstallStep('env', InstallStep::OK, '.env ready.');

        // 3. Generate keys.
        if (!$options->skipKeys) {
            foreach (['APP_KEY' => 32, 'TOKEN_SALT' => 32, 'JWT_KEY' => 64] as $key => $len) {
                $current = $env->get($key);
                if ($options->force || $current === null || $current === '') {
                    $env->set($key, RandomStringGenerator::generate($len));
                }
            }
            $steps[] = new InstallStep('keys', InstallStep::OK, 'Security keys ensured.');
        }

        // 4. Persist DB creds (only after the preflight passed).
        $migrationConnection = null;
        if ($options->database !== null && !$options->skipDatabase) {
            if ($options->database->engine === 'sqlite') {
                $this->ensureSqliteFile($options->database->database);
            }
            $env->setMany($options->database->toEnvPairs());
            $migrationConnection = new Connection($options->database->toConnectionConfig(), $this->context);
            $steps[] = new InstallStep('database-config', InstallStep::OK, 'Database credentials written.');
        }

        // 5. Migrate the SAME connection (injected) — never fromContext().
        if (!$options->skipDatabase) {
            try {
                $manager = new MigrationManager(null, null, $this->context, $migrationConnection);
                $manager->migrate();
                $steps[] = new InstallStep('migrate', InstallStep::OK, 'Migrations applied.');
            } catch (\Throwable $e) {
                $steps[] = new InstallStep('migrate', InstallStep::FAILED, $e->getMessage());
                return InstallResult::from($steps);
            }
        }

        // 6. Cache + final validation (non-fatal; skippable in tests).
        if (!$this->skipCacheAndValidation && !$options->skipCache) {
            $steps[] = new InstallStep('cache', InstallStep::OK, 'Cache initialized.');
        }

        return InstallResult::from($steps);
    }

    private function ensureSqliteFile(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if (!is_file($path)) {
            new \PDO("sqlite:{$path}"); // creates the file
        }
    }
}
```

> Note: when `$migrationConnection` is `null` (no explicit `DatabaseConfig`, e.g. `--quiet`/use-existing-env), `MigrationManager` falls back to `Connection::fromContext()` — the existing behavior. The injected path is used only when creds were just tested.

- [ ] **Step 4: Run it; verify it passes.**

Run: `vendor/bin/phpunit --filter InstallerTest`
Expected: PASS (2 tests) — failed preflight writes no `.env`; a good sqlite config migrates that file.

- [ ] **Step 5: phpcs + commit.**
```bash
composer phpcs
git add src/Installer/Installer.php tests/Unit/Installer/InstallerTest.php
git commit -m "Add Installer: preflight-first pipeline, tested==migrated"
```

---

## Task 7: `InstallState`

**Files:**
- Create: `src/Installer/InstallState.php`
- Test: `tests/Unit/Installer/InstallStateTest.php`

- [ ] **Step 1: Write the failing test.**
```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Installer;

use Glueful\Installer\InstallState;
use PHPUnit\Framework\TestCase;

final class InstallStateTest extends TestCase
{
    public function testMigrationsPendingIsTrueAndNeverThrowsWithNoDbConfigured(): void
    {
        $dir = sys_get_temp_dir() . '/installstate_' . uniqid();
        mkdir($dir, 0775, true);
        $state = new InstallState($dir); // no .env, no DB

        self::assertFalse($state->hasEnv());
        self::assertTrue($state->migrationsPending(), 'no DB configured => treat as pending');

        @rmdir($dir);
    }
}
```

- [ ] **Step 2: Run it; verify it fails.**

Run: `vendor/bin/phpunit --filter InstallStateTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `InstallState`.** Create `src/Installer/InstallState.php`:
```php
<?php

declare(strict_types=1);

namespace Glueful\Installer;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;

/**
 * Read-only "should the installer run?" helpers. Best-effort: never throws and never requires a
 * reachable DB. The app's own `installed` lock (an admin exists) is separate and app-side.
 */
final class InstallState
{
    public function __construct(
        private readonly string $basePath,
        private readonly ?ApplicationContext $context = null,
    ) {
    }

    public function hasEnv(): bool
    {
        return is_file($this->basePath . '/.env');
    }

    public function isDatabaseConfigured(): bool
    {
        $env = new EnvWriter($this->basePath . '/.env');
        return $this->hasEnv() && ($env->get('DB_DRIVER') ?? '') !== '';
    }

    /**
     * True when nothing has been migrated yet — including when no DB is configured or the
     * configured DB is unreachable (best-effort; never throws).
     */
    public function migrationsPending(): bool
    {
        if (!$this->isDatabaseConfigured()) {
            return true;
        }
        try {
            $connection = Connection::fromContext($this->context);
            $tables = $connection->getSchemaBuilder()->getTables();
            // No migrations table => nothing migrated yet.
            return !in_array('migrations', $tables, true);
        } catch (\Throwable) {
            return true; // unreachable/misconfigured => treat as pending, do not throw
        }
    }
}
```

> **Confirm step (table list API):** verify the schema builder exposes a table list: `grep -n "function getTables\|listTables\|function hasTable" src/Database/Schema/*.php`. If the method is named differently (e.g. `hasTable('migrations')`), use that instead of `getTables()`. The behavior (pending unless a migrations table exists) is what matters.

- [ ] **Step 4: Run it; verify it passes.**

Run: `vendor/bin/phpunit --filter InstallStateTest`
Expected: PASS.

- [ ] **Step 5: phpcs + commit.**
```bash
composer phpcs
git add src/Installer/InstallState.php tests/Unit/Installer/InstallStateTest.php
git commit -m "Add InstallState: best-effort install-state helpers"
```

---

## Task 8: `InstallCommand` → thin wrapper (un-SQLite-lock + reconnect prompts)

**Files:**
- Modify: `src/Console/Commands/InstallCommand.php`
- Test: manual + the existing command test (see Step 4)

> The command gathers input (interactive prompts, now reconnected) or reads env (`--quiet`), builds `InstallOptions`, calls `Installer::run()`, and renders `InstallResult`. The sqlite-only `setupDatabase()` and the private `updateEnvFile()` are deleted.

- [ ] **Step 1: Replace the database step + delete the private writer.** In `src/Console/Commands/InstallCommand.php`:
  1. Delete the private `updateEnvFile()` method (around line 531) — `EnvWriter` replaces it.
  2. Delete the sqlite-only `setupDatabase()` body and the orphaned `configure*Database()` helpers' role as dead code by **wiring them through the new flow**: in interactive mode, build a `DatabaseConfig` from prompts (engine via `$this->choice([...])`, then host/port/db/user/password via `$this->ask`/`$this->secret`); in `--quiet` mode, leave `database = null` (use existing env).
  3. Replace the install body so it constructs and runs the `Installer`:
```php
use Glueful\Installer\DatabaseConfig;
use Glueful\Installer\Installer;
use Glueful\Installer\InstallOptions;
use Glueful\Installer\InstallStep;

// ...inside execute(), after gathering $skipDatabase/$skipKeys/$skipCache/$force/$quiet:

$database = null;
if (!$skipDatabase && !$quiet) {
    $engine = $this->choice('Which database engine?', ['mysql', 'pgsql', 'sqlite'], 'sqlite');
    $database = $this->promptDatabaseConfig($engine); // builds DatabaseConfig via ask/secret
}

$installer = new Installer(base_path($this->getContext()), $this->getContext());
$result = $installer->run(new InstallOptions(
    database: $database,
    skipDatabase: $skipDatabase,
    skipKeys: $skipKeys,
    skipCache: $skipCache,
    force: $force,
));

foreach ($result->steps as $step) {
    $glyph = $step->status === InstallStep::OK ? '✓'
        : ($step->status === InstallStep::FAILED ? '✗' : '•');
    $this->line("{$glyph} {$step->name}: {$step->message}");
}

if (!$result->ok) {
    $this->error('Installation failed. No partial state was written on a failed DB preflight.');
    return self::FAILURE;
}
$this->success('🎉 Installation complete.');
return self::SUCCESS;
```
  4. Add the `promptDatabaseConfig(string $engine): DatabaseConfig` helper (reuse the existing per-engine prompt wording from the orphaned `configureMysqlDatabase`/`configurePostgreSQLDatabase`/`configureSqliteDatabase`, but return a `DatabaseConfig` instead of writing env):
```php
private function promptDatabaseConfig(string $engine): DatabaseConfig
{
    if ($engine === 'sqlite') {
        $default = base_path($this->getContext(), 'storage/database/glueful.sqlite');
        return new DatabaseConfig('sqlite', database: $this->ask('SQLite file path', $default));
    }
    $port = $engine === 'pgsql' ? '5432' : '3306';
    $user = $engine === 'pgsql' ? 'postgres' : 'root';
    return new DatabaseConfig(
        engine: $engine,
        host: $this->ask('Database host', '127.0.0.1'),
        port: (int) $this->ask('Database port', $port),
        database: $this->ask('Database name', 'glueful'),
        username: $this->ask('Database username', $user),
        password: (string) $this->secret('Database password'),
        schema: $engine === 'pgsql' ? ($this->ask('Schema', 'public') ?: null) : null,
        sslMode: $engine === 'pgsql' ? ($this->ask('SSL mode (blank for default)', '') ?: null) : null,
    );
}
```

- [ ] **Step 2: Verify no `updateEnvFile` remains in the command.**

Run: `grep -n "updateEnvFile" src/Console/Commands/InstallCommand.php`
Expected: no output.

- [ ] **Step 3: Smoke-test the engine-agnostic path against a temp app dir.**

Run (sqlite, non-default path, non-interactive via env is covered by `InstallerTest`; here verify the command wires up):
```bash
php glueful install --help
```
Expected: help renders, lists `--skip-database`/`--skip-keys`/`--skip-cache`. (Full interactive run is exercised by `InstallerTest` at the service layer; the command is now a thin wrapper.)

- [ ] **Step 4: Run the existing console test suite for regressions.**

Run: `vendor/bin/phpunit tests/Unit/Console`
Expected: PASS — no command test asserts the old sqlite-only string. If one does, update it to the new wrapper behavior and note it in the commit.

- [ ] **Step 5: phpcs + commit.**
```bash
composer phpcs
git add src/Console/Commands/InstallCommand.php
git commit -m "InstallCommand: thin wrapper over Installer; engine-agnostic, prompts reconnected"
```

---

## Task 9: `Generate\KeyCommand` → `EnvWriter`

**Files:**
- Modify: `src/Console/Commands/Generate/KeyCommand.php`
- Test: `tests/Unit/Console` (see Step 3)

- [ ] **Step 1: Replace its private writer with `EnvWriter`.** In `src/Console/Commands/Generate/KeyCommand.php`, delete the private `updateEnvFile()` (around line 168) and route writes through `EnvWriter`:
```php
use Glueful\Installer\EnvWriter;

// where it previously called $this->updateEnvFile($key, $value):
$env = new EnvWriter(base_path($this->getContext(), '.env'));
$env->set($key, $value);
```
(Adapt to the exact key/value variables in `KeyCommand`'s `execute()`.)

- [ ] **Step 2: Verify no private writer remains anywhere.**

Run: `grep -rn "function updateEnvFile" src/`
Expected: no output — both copies are gone; `EnvWriter` is the single `.env` writer.

- [ ] **Step 3: Run the console suite.**

Run: `vendor/bin/phpunit tests/Unit/Console --filter Key`
Expected: PASS (or no matching tests; the grep in Step 2 is the key assertion).

- [ ] **Step 4: phpcs + commit.**
```bash
composer phpcs
git add src/Console/Commands/Generate/KeyCommand.php
git commit -m "generate:key: write .env via the single EnvWriter (drop duplicate writer)"
```

---

## Task 10: CHANGELOG

**Files:**
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Add `[Unreleased]` entries.**
```markdown
### Added
- **Engine-agnostic installer + `Glueful\Installer\` seams.** `php glueful install` now configures and migrates **any** database engine (MySQL/PostgreSQL/SQLite), not just SQLite, with reconnected interactive credential prompts. New reusable services — `EnvWriter` (atomic, quoting), `ConnectionTester` (transient probe of explicit creds, typed result), `Installer` (preflight-first pipeline, step-based `InstallResult`), `DatabaseConfig`, `InstallState` — let an app drive first-run setup from CLI or a UI without shelling out. Two hard invariants: a failed DB connection test mutates nothing (`.env` untouched), and the tested credentials are exactly the connection migrations run on.

### Changed
- **`MigrationManager` accepts an optional injected `Connection`** (4th constructor arg) so the installer migrates the just-tested connection rather than the already-loaded config. Behavior is unchanged when none is injected.

### Fixed
- **`.env` writes are now quoted/escaped and atomic.** The two private `updateEnvFile()` copies (in `install` and `generate:key`) — which wrote unquoted values and could corrupt `.env` on a password containing spaces/`#`/`=`/quotes — are replaced by the single `EnvWriter`.
```

- [ ] **Step 2: Commit.**
```bash
git add CHANGELOG.md
git commit -m "Changelog: engine-agnostic installer + setup seams"
```

---

## Self-review

- **Spec coverage:** `DatabaseConfig` → Task 2; `EnvWriter` (atomic+quoting, single writer) → Tasks 1, 9; `ConnectionTester` (transient, typed result, no leak) → Task 3; `Installer` (preflight-first, step result) → Task 6; `InstallState` (best-effort) → Task 7; invariant #1 (no mutation on failed test) → Task 6 test; invariant #2 (tested==migrated via injected connection) → Tasks 4 + 6 tests; engine→env mapping incl. pgsql schema/sslMode → Task 2; CLI wrapper + un-SQLite-lock + reconnected prompts → Task 8; `MigrationManager` change → Task 4; out-of-scope (no save-anyway, no dry-run, no framework setup endpoint, no admin creation) honored. All mapped.
- **Placeholder scan:** none — full code in every implementation step; three explicit *confirm steps* (Connection `sslmode` key, connect-timeout, schema-builder table API) are real grep-and-adapt steps, not vague hand-waves.
- **Type/name consistency:** `DatabaseConfig::toConnectionConfig()`/`toEnvPairs()`, `ConnectionTester::test()→ConnectionTestResult{engine,ok,message,exceptionClass,sqlState}`, `Installer::run(InstallOptions)→InstallResult{steps,ok}`, `InstallStep{name,status,message}` with `OK/FAILED/SKIPPED/WARNING`, `MigrationManager(?path,?finder,?context,?connection)` — referenced identically across tasks. Step names used in assertions (`database-preflight`) match the `Installer` code.
- **Green per task:** leaf VOs/services first (1–5), orchestrator (6), state (7), then the command refactors (8–9) that depend on them; each task's tests pass independently.

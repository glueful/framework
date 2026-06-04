<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Database\Migrations\Support;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Testing\TestCase;

/**
 * SQLite harness for MigrationManager tests.
 *
 * MigrationManager builds its own Connection via Connection::fromContext(), so we cannot inject
 * a connection — instead the booted test app's database config points at a per-test FILE-based
 * SQLite db with pooling DISABLED. Pooling-off makes Connection set its own PDO in the
 * constructor (bypassing the static self::$instances cache), so:
 *   - MigrationManager's connection and the test's verification connection share the db BY PATH,
 *   - there is no cross-test static PDO leak (each test uses a fresh file).
 * (:memory: would NOT work here — it is per-connection, so the two connections would see
 * different empty databases.)
 */
abstract class MigrationTestCase extends TestCase
{
    private string $appPath;
    private string $dbFile;
    private string $migrationsDir;

    protected function setUp(): void
    {
        $this->appPath = sys_get_temp_dir() . '/glueful-migr-' . uniqid('', true);
        $this->dbFile = $this->appPath . '/test.sqlite';
        $cfg = $this->appPath . '/config';
        mkdir($cfg, 0755, true);

        file_put_contents($cfg . '/app.php', "<?php\nreturn ['name' => 'Test', 'env' => 'testing', 'debug' => true];\n");
        // Connection reads config['sqlite']['primary'] for the path; pooling off avoids the
        // static PDO cache so the file db is shared by path across Connection instances.
        file_put_contents(
            $cfg . '/database.php',
            "<?php\nreturn ['engine' => 'sqlite', 'sqlite' => ['primary' => '" . $this->dbFile . "'], "
            . "'pooling' => ['enabled' => false]];\n"
        );
        file_put_contents($cfg . '/cache.php', "<?php\nreturn ['enabled' => false, 'default' => 'array', 'stores' => ['array' => ['driver' => 'array']]];\n");
        file_put_contents($cfg . '/session.php', "<?php\nreturn ['jwt_key' => 'test'];\n");
        file_put_contents($cfg . '/security.php', "<?php\nreturn ['csrf' => ['enabled' => false]];\n");

        $this->migrationsDir = $this->appPath . '/database/migrations';
        mkdir($this->migrationsDir, 0755, true);

        parent::setUp();
    }

    protected function getBasePath(): string
    {
        return $this->appPath;
    }

    protected function context(): ApplicationContext
    {
        return $this->app()->getContext();
    }

    protected function tempMigrationsDir(): string
    {
        return $this->migrationsDir;
    }

    /**
     * Write a fixture migration that creates a marker table, into $dir. Returns the basename.
     *
     * Each fixture is emitted in a UNIQUE namespace derived from dir+basename so two sources can
     * ship the same basename (e.g. 001_create_tables.php) without a "cannot redeclare class"
     * fatal — runMigration() detects the namespace and resolves the FQCN.
     */
    protected function writeFixture(string $dir, string $basename, string $createsTable): string
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $class = preg_replace('/^\d+_/', '', pathinfo($basename, PATHINFO_FILENAME));
        $ns = 'Glueful\\Tests\\Fixtures\\N' . substr(md5($dir . '|' . $basename), 0, 10);
        $php = <<<PHP
<?php
namespace {$ns};
use Glueful\\Database\\Migrations\\MigrationInterface;
use Glueful\\Database\\Schema\\Interfaces\\SchemaBuilderInterface;
class {$class} implements MigrationInterface
{
    public function up(SchemaBuilderInterface \$schema): void
    {
        \$t = \$schema->table('{$createsTable}');
        \$t->id();
        \$t->create()->execute();
    }
    public function down(SchemaBuilderInterface \$schema): void
    {
        \$schema->dropTableIfExists('{$createsTable}');
    }
    public function getDescription(): string { return '{$class}'; }
}
PHP;
        file_put_contents($dir . '/' . $basename, $php);
        return $basename;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (isset($this->appPath) && is_dir($this->appPath)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->appPath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->appPath);
        }
    }
}

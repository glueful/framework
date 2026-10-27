<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database;

use Glueful\Database\Connection;
use PHPUnit\Framework\TestCase;

/**
 * `DB_SQLITE_DATABASE=:memory:` (what the api-skeleton's phpunit.xml sets) names SQLite's
 * in-memory database. Path resolution must pass it — and `file:` URIs — through untouched,
 * never prefix them with the project root (which created a file literally named `:memory:`).
 */
final class SqliteMemoryPathTest extends TestCase
{
    private string|false $previous;

    protected function setUp(): void
    {
        $this->previous = getenv('DB_SQLITE_DATABASE');
    }

    protected function tearDown(): void
    {
        if ($this->previous === false) {
            putenv('DB_SQLITE_DATABASE');
            unset($_ENV['DB_SQLITE_DATABASE'], $_SERVER['DB_SQLITE_DATABASE']);
        } else {
            putenv('DB_SQLITE_DATABASE=' . $this->previous);
            $_ENV['DB_SQLITE_DATABASE'] = $this->previous;
        }
    }

    private function setPath(string $path): void
    {
        putenv('DB_SQLITE_DATABASE=' . $path);
        $_ENV['DB_SQLITE_DATABASE'] = $path;
        $_SERVER['DB_SQLITE_DATABASE'] = $path;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function inMemoryPaths(): array
    {
        return [
            'memory' => [':memory:'],
            'file uri' => ['file::memory:?cache=shared'],
        ];
    }

    /**
     * @dataProvider inMemoryPaths
     */
    public function testConfigDatabaseKeepsInMemoryPathVerbatim(string $path): void
    {
        $this->setPath($path);

        $config = require dirname(__DIR__, 3) . '/config/database.php';

        $this->assertSame($path, $config['sqlite']['primary']);
    }

    /**
     * @dataProvider inMemoryPaths
     */
    public function testConnectionFallbackKeepsInMemoryPathVerbatim(string $path): void
    {
        $this->setPath($path);

        $connection = new Connection(['engine' => 'sqlite', 'sqlite' => ['primary' => ':memory:']]);
        $resolve = new \ReflectionMethod(Connection::class, 'resolveSQLitePath');

        $this->assertSame($path, $resolve->invoke($connection));
    }

    public function testRelativePathIsStillResolvedAgainstTheProjectRoot(): void
    {
        $this->setPath('storage/database/x.sqlite');

        $config = require dirname(__DIR__, 3) . '/config/database.php';

        $this->assertSame(dirname(__DIR__, 3) . '/storage/database/x.sqlite', $config['sqlite']['primary']);
    }

    public function testInMemoryConnectionCreatesNoFile(): void
    {
        $cwd = getcwd();
        $dir = sys_get_temp_dir() . '/glueful-sqlite-mem-' . uniqid('', true);
        mkdir($dir);
        chdir($dir);
        try {
            $connection = new Connection(['engine' => 'sqlite', 'sqlite' => ['primary' => ':memory:']]);
            $connection->getPDO()->exec('CREATE TABLE t (id INTEGER)');
            $this->assertFileDoesNotExist($dir . '/:memory:');
        } finally {
            chdir((string) $cwd);
        }
    }
}

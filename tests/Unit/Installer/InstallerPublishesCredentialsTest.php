<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Installer;

use Glueful\Database\Connection;
use Glueful\Installer\DatabaseConfig;
use Glueful\Installer\Installer;
use Glueful\Installer\InstallOptions;
use Glueful\Installer\InstallStep;
use PHPUnit\Framework\TestCase;

/**
 * A fresh `create-project` boots with the sample's placeholder credentials; the operator then
 * types real ones at provision's prompt. The Installer wrote them to `.env` and migrated over
 * an injected connection — but any migration that opens its OWN connection (pack permission
 * seeds, Aegis's role seed) still saw the placeholders and failed with "role your_database_user
 * does not exist". The Installer must publish what it wrote to the running process before it
 * migrates.
 */
final class InstallerPublishesCredentialsTest extends TestCase
{
    private string $dir;

    /** @var array<string, string|null> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['DB_DRIVER', 'DB_SQLITE_DATABASE', 'DB_PGSQL_USERNAME'] as $k) {
            $this->saved[$k] = $_ENV[$k] ?? null;
        }
        $this->dir = sys_get_temp_dir() . '/installer_pub_' . uniqid();
        mkdir($this->dir . '/migrations', 0775, true);
        file_put_contents($this->dir . '/.env.example', "APP_ENV=local\nDB_DRIVER=pgsql\nDB_PGSQL_USERNAME=your_database_user\n");
        // A migration that opens its own connection, the way the pack permission seeds do.
        file_put_contents($this->dir . '/migrations/001_OwnConnectionSeed.php', <<<'PHP'
<?php
declare(strict_types=1);
use Glueful\Database\Connection;
use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
final class OwnConnectionSeed implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        $own = new Connection();
        if ($own->getDriverName() !== 'sqlite') {
            throw new \RuntimeException('own connection still sees the boot-time engine: ' . $own->getDriverName());
        }
        $own->getPDO()->exec('CREATE TABLE own_seed (id INTEGER PRIMARY KEY)');
    }
    public function down(SchemaBuilderInterface $schema): void {}
    public function getDescription(): string { return 'seed via own connection'; }
}
PHP);
        // The process booted with the placeholders.
        $_ENV['DB_DRIVER'] = 'pgsql';
        $_ENV['DB_PGSQL_USERNAME'] = 'your_database_user';
        unset($_ENV['DB_SQLITE_DATABASE']);
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            if ($v === null) {
                unset($_ENV[$k]);
            } else {
                $_ENV[$k] = $v;
            }
        }
        foreach (glob($this->dir . '/migrations/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir . '/migrations');
        foreach (glob($this->dir . '/{.env,.env.example,*.sqlite}', GLOB_BRACE) ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testMigrationsThatOpenTheirOwnConnectionSeeTheCredentialsJustWritten(): void
    {
        $dbFile = $this->dir . '/fresh.sqlite';
        $installer = new Installer($this->dir, skipCacheAndValidation: true, migrationsPath: $this->dir . '/migrations');

        $result = $installer->run(new InstallOptions(
            database: new DatabaseConfig('sqlite', database: $dbFile),
            skipKeys: true,
        ));

        $migrate = array_values(array_filter($result->steps, static fn (InstallStep $s): bool => $s->name === 'migrate'))[0] ?? null;
        self::assertNotNull($migrate);
        self::assertSame(InstallStep::OK, $migrate->status, $migrate->message);
        self::assertSame('sqlite', $_ENV['DB_DRIVER'], 'the written credentials are live in the process');
        self::assertSame($dbFile, $_ENV['DB_SQLITE_DATABASE']);
        self::assertSame(
            'own_seed',
            (new Connection())->getPDO()->query("SELECT name FROM sqlite_master WHERE name='own_seed'")->fetchColumn(),
            'the own-connection seed landed in the freshly configured database'
        );
    }
}

<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\Migrations;

use Glueful\Database\Connection;
use Glueful\Database\Migrations\MigrationManager;
use Glueful\Installer\DatabaseConfig;
use Glueful\Services\FileFinder;
use PHPUnit\Framework\TestCase;

/**
 * $schema->dropTable() (and, on MySQL/PostgreSQL, the addColumn()/dropColumn()/index/foreign-key
 * helpers) only queue their SQL. The runner never flushed after up()/down(), so a down() calling
 * dropTable() deleted its ledger row and left the table in place.
 */
final class QueuedSchemaOperationsFlushTest extends TestCase
{
    private string $base;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/glueful-flush-' . uniqid('', true);
        mkdir($this->base . '/app', 0777, true);
        $config = new DatabaseConfig('sqlite', database: $this->base . '/db.sqlite');
        $this->connection = new Connection($config->toConnectionConfig());
    }

    private function manager(): MigrationManager
    {
        return new MigrationManager($this->base . '/app', new FileFinder(), null, $this->connection);
    }

    private function writeMigration(string $number, string $classStem, string $upBody, string $downBody): void
    {
        $class = $classStem . 'X' . substr(md5($this->base . $number), 0, 8);
        file_put_contents($this->base . '/app/' . $number . '_' . $class . '.php', <<<PHP
            <?php

            use Glueful\\Database\\Migrations\\MigrationInterface;
            use Glueful\\Database\\Schema\\Interfaces\\SchemaBuilderInterface;

            class {$class} implements MigrationInterface
            {
                public function up(SchemaBuilderInterface \$schema): void
                {
                    {$upBody}
                }

                public function down(SchemaBuilderInterface \$schema): void
                {
                    {$downBody}
                }

                public function getDescription(): string
                {
                    return 'fixture';
                }
            }
            PHP);
    }

    /** @return list<string> */
    private function tables(): array
    {
        return $this->connection->getPDO()
            ->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")
            ->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function testRollbackRunsAQueuedDropTable(): void
    {
        $this->writeMigration(
            '001',
            'CreateGadgets',
            "\$schema->createTable('gadgets', function (\$t) { \$t->string('name', 50); });",
            "\$schema->dropTable('gadgets');"
        );
        $manager = $this->manager();
        $manager->migrate();
        self::assertContains('gadgets', $this->tables());

        $result = $manager->rollback();

        self::assertCount(1, $result['reverted']);
        self::assertNotContains('gadgets', $this->tables());
    }

    public function testMigrateRunsAQueuedDropTable(): void
    {
        $this->connection->getPDO()->exec('CREATE TABLE legacy_things (id INTEGER PRIMARY KEY)');
        $this->writeMigration('001', 'DropLegacy', "\$schema->dropTable('legacy_things');", '');

        $result = $this->manager()->migrate();

        self::assertCount(1, $result['applied']);
        self::assertNotContains('legacy_things', $this->tables());
    }
}

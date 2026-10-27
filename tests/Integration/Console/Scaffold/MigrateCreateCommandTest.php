<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Console\Scaffold;

use Glueful\Console\Commands\Migrate\CreateCommand;

/**
 * migrate:create wrote `001_create_tasks_table.php` declaring `class CreateTasksTable`, but
 * MigrationManager takes the class name from the filename (`create_tasks_table`), so every migration
 * it generated failed with "Migration class … not found". Files are now named after their class.
 */
final class MigrateCreateCommandTest extends ScaffoldCommandTestCase
{
    public function testGeneratedMigrationResolvesTheWayMigrationManagerLoadsIt(): void
    {
        $tester = $this->tester(CreateCommand::class);
        $status = $tester->execute(['name' => 'create_tasks_table'], ['interactive' => false]);
        self::assertSame(0, $status, $tester->getDisplay());

        $files = glob($this->base . '/database/migrations/*.php') ?: [];
        self::assertCount(1, $files, $tester->getDisplay());
        $file = $files[0];
        $this->assertLints($file);

        // Exactly MigrationManager::runMigration(): strip the NNN_ prefix from the basename.
        $out = $this->runInChild(
            'include_once ' . var_export($file, true) . ";\n"
            . '$base = pathinfo(' . var_export($file, true) . ', PATHINFO_FILENAME);' . "\n"
            . '$class = preg_replace(\'/^\\d+_/\', \'\', $base);' . "\n"
            . '$src = (string) file_get_contents(' . var_export($file, true) . ");\n"
            . 'if (preg_match(\'/namespace\\s+([^;]+);/\', $src, $m) === 1) { $class = $m[1] . \'\\\\\' . $class; }' . "\n"
            . 'if (!class_exists($class)) { echo "missing:$class"; exit(4); }' . "\n"
            . '$m = new $class();' . "\n"
            . 'echo $m instanceof Glueful\\Database\\Migrations\\MigrationInterface ? \'ok\' : \'not-a-migration\';' . "\n"
        );

        self::assertSame('ok', $out);
    }
}

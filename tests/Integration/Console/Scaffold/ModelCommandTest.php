<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Console\Scaffold;

use Glueful\Console\Commands\Scaffold\ModelCommand;

/**
 * scaffold:model must write where the app autoloads models (app/Models, App\Models) and its
 * --migration must produce a real Glueful migration that migrate:run picks up
 * (database/migrations/NNN_ClassName.php implementing MigrationInterface).
 */
final class ModelCommandTest extends ScaffoldCommandTestCase
{
    public function testWritesModelIntoAppModelsWhenAppDirExists(): void
    {
        $tester = $this->tester(ModelCommand::class);

        $status = $tester->execute(['name' => 'Task'], ['interactive' => false]);

        self::assertSame(0, $status, $tester->getDisplay());
        $file = $this->base . '/app/Models/Task.php';
        self::assertFileExists($file);
        self::assertFileDoesNotExist($this->base . '/api/Models/Task.php');
        self::assertStringContainsString('namespace App\\Models;', (string) file_get_contents($file));
        self::assertStringNotContainsString('Task::where(', $tester->getDisplay());
    }

    public function testKeepsLegacyLocationWithoutAppDir(): void
    {
        $tester = $this->tester(ModelCommand::class, withAppDir: false);

        $status = $tester->execute(['name' => 'Task'], ['interactive' => false]);

        self::assertSame(0, $status, $tester->getDisplay());
        self::assertFileExists($this->base . '/api/Models/Task.php');
    }

    public function testMigrationIsARealGluefulMigrationThatRuns(): void
    {
        @mkdir($this->base . '/database/migrations', 0777, true);
        file_put_contents($this->base . '/database/migrations/001_CreateUsersTable.php', "<?php\n");
        $tester = $this->tester(ModelCommand::class);

        $status = $tester->execute(
            ['name' => 'Task', '--migration' => true, '--soft-deletes' => true],
            ['interactive' => false]
        );

        self::assertSame(0, $status, $tester->getDisplay());
        self::assertDirectoryDoesNotExist($this->base . '/migrations');
        $file = $this->base . '/database/migrations/002_CreateTasksTable.php';
        self::assertFileExists($file, $tester->getDisplay());
        $this->assertLints($file);

        // Load it the way MigrationManager does (include + class from the filename) and run it.
        $out = $this->runInChild(
            'use Glueful\\Database\\Connection;' . "\n"
            . 'include_once ' . var_export($file, true) . ";\n"
            . '$src = (string) file_get_contents(' . var_export($file, true) . ");\n"
            . 'preg_match(\'/namespace\\s+([^;]+);/\', $src, $m);' . "\n"
            . '$class = ($m[1] ?? \'\') . \'\\\\CreateTasksTable\';' . "\n"
            . '$migration = new $class();' . "\n"
            . 'if (!$migration instanceof Glueful\\Database\\Migrations\\MigrationInterface) { exit(3); }' . "\n"
            . '$db = new Connection([\'engine\' => \'sqlite\', \'sqlite\' => [\'primary\' => \':memory:\'],'
            . ' \'pooling\' => [\'enabled\' => false]]);' . "\n"
            . '$schema = $db->getSchemaBuilder();' . "\n"
            . '$migration->up($schema);' . "\n"
            . '$migration->up($schema);' . "\n" // hasTable guard: re-run is a no-op
            . '$cols = array_column($schema->getTableColumns(\'tasks\'), \'name\');' . "\n"
            . 'echo implode(\',\', $cols), "|";' . "\n"
            . '$migration->down($schema);' . "\n"
            . 'echo $schema->hasTable(\'tasks\') ? \'still\' : \'dropped\';' . "\n"
        );

        self::assertStringContainsString('id', $out);
        self::assertStringContainsString('created_at', $out);
        self::assertStringContainsString('deleted_at', $out);
        self::assertStringEndsWith('|dropped', $out);
    }
}

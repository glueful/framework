<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Console\Scaffold;

use Glueful\Console\Commands\Scaffold\FactoryCommand;
use Glueful\Console\Commands\Scaffold\SeederCommand;

/**
 * The api-skeleton autoloads only App\ => app/, so scaffold:factory / scaffold:seeder must
 * generate under app/Database/{Factories,Seeders} (App\Database\…) — namespaces that
 * HasFactory and db:seed already resolve — instead of the unmapped Database\… namespaces.
 */
final class FactorySeederCommandTest extends ScaffoldCommandTestCase
{
    public function testFactoryIsGeneratedIntoAnAutoloadedAppNamespace(): void
    {
        $tester = $this->tester(FactoryCommand::class);

        $status = $tester->execute(['name' => 'TaskFactory']);

        self::assertSame(0, $status, $tester->getDisplay());
        $file = $this->base . '/app/Database/Factories/TaskFactory.php';
        self::assertFileExists($file, $tester->getDisplay());
        $source = (string) file_get_contents($file);
        self::assertStringContainsString('namespace App\\Database\\Factories;', $source);
        $this->assertLints($file);

        $out = $this->runInChild(
            'require ' . var_export($file, true) . ";\n"
            // Faker is a dev-only dependency, so inspect the class rather than instantiating it.
            . '$r = new ReflectionClass(App\\Database\\Factories\\TaskFactory::class);' . "\n"
            . 'echo $r->getParentClass()->getName(), "|", $r->getDefaultProperties()["model"];'
        );
        self::assertSame('Glueful\\Database\\Factory\\Factory|App\\Models\\Task', $out);
    }

    public function testFactoryKeepsLegacyLayoutWithoutAppDir(): void
    {
        $tester = $this->tester(FactoryCommand::class, withAppDir: false);

        self::assertSame(0, $tester->execute(['name' => 'TaskFactory']), $tester->getDisplay());
        $file = $this->base . '/database/factories/TaskFactory.php';
        self::assertFileExists($file);
        self::assertStringContainsString('namespace Database\\Factories;', (string) file_get_contents($file));
    }

    public function testSeederIsGeneratedIntoAnAutoloadedAppNamespace(): void
    {
        $tester = $this->tester(SeederCommand::class);

        $status = $tester->execute(['name' => 'TaskSeeder']);
        $display = $tester->getDisplay();

        self::assertSame(0, $status, $display);
        $file = $this->base . '/app/Database/Seeders/TaskSeeder.php';
        self::assertFileExists($file, $display);
        self::assertStringContainsString('namespace App\\Database\\Seeders;', (string) file_get_contents($file));
        // db:seed only prefixes Database\Seeders\ onto a short name, so the hint must give the FQCN.
        self::assertStringContainsString('db:seed "App\\Database\\Seeders\\TaskSeeder"', $display);
        $this->assertLints($file);

        $out = $this->runInChild(
            'require ' . var_export($file, true) . ";\n"
            . 'echo get_parent_class(App\\Database\\Seeders\\TaskSeeder::class);'
        );
        self::assertSame('Glueful\\Database\\Seeders\\Seeder', $out);
    }

    public function testDatabaseSeederIsGeneratedIntoTheAppNamespace(): void
    {
        $tester = $this->tester(SeederCommand::class);

        self::assertSame(0, $tester->execute(['name' => 'DatabaseSeeder']), $tester->getDisplay());
        $file = $this->base . '/app/Database/Seeders/DatabaseSeeder.php';
        self::assertFileExists($file);
        self::assertStringContainsString('namespace App\\Database\\Seeders;', (string) file_get_contents($file));
        $this->assertLints($file);
    }

    public function testSeederKeepsLegacyLayoutWithoutAppDir(): void
    {
        $tester = $this->tester(SeederCommand::class, withAppDir: false);

        self::assertSame(0, $tester->execute(['name' => 'TaskSeeder']), $tester->getDisplay());
        $file = $this->base . '/database/seeders/TaskSeeder.php';
        self::assertFileExists($file);
        self::assertStringContainsString('namespace Database\\Seeders;', (string) file_get_contents($file));
        self::assertStringContainsString('db:seed TaskSeeder', $tester->getDisplay());
    }
}

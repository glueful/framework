<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Installer;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\ConfigurationLoader;
use Glueful\Database\Connection;
use Glueful\Extensions\Schema\MigrationLockFactory;
use Glueful\Installer\DatabaseConfig;
use Glueful\Installer\Installer;
use Glueful\Installer\InstallOptions;
use Glueful\Installer\InstallStep;
use PHPUnit\Framework\TestCase;

/**
 * Provision is a COMPLETE, locked pass (schema policy spec B4): with a context, the installer
 * builds its manager through MigrationManagerFactory, so the app path AND every manifest core
 * descriptor apply in one custody sequence — the global-source snapshot is locked wholesale, the
 * pending read happens inside the lock, and the run report decides the step truthfully. A failed
 * descriptor migration is a FAILED install naming the migration, never a quiet success.
 */
final class InstallerFullPassTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/glueful-fullpass-' . uniqid('', true);
        mkdir($this->base . '/vendor/composer', 0777, true);
        mkdir($this->base . '/database/migrations', 0777, true);
        file_put_contents($this->base . '/.env.example', "APP_ENV=local\nAPP_KEY=\n");
        // A real host pins its own app migrations path; the framework default points at the
        // framework checkout, which is wrong for every host.
        mkdir($this->base . '/config');
        file_put_contents(
            $this->base . '/config/app.php',
            "<?php\nreturn ['paths' => ['migrations' => __DIR__ . '/../database/migrations']];\n"
        );

        $suffix = 'F' . substr(md5($this->base), 0, 8);
        $this->writeMigration(
            $this->base . '/database/migrations/001_CreateAppThings' . $suffix . '.php',
            'CreateAppThings' . $suffix,
            "\$schema->createTable('app_things', function (\$t) { \$t->string('name', 50); });"
        );
        $this->writeMigration(
            $this->base . '/vendor/acme/corelib/migrations/001_CreateCoreThings' . $suffix . '.php',
            'CreateCoreThings' . $suffix,
            "\$schema->createTable('core_things', function (\$t) { \$t->string('name', 50); });"
        );
        file_put_contents(
            $this->base . '/vendor/composer/installed.json',
            json_encode(['packages' => [[
                'name' => 'acme/corelib',
                'type' => 'library',
                'install-path' => '../acme/corelib',
                'extra' => ['glueful' => ['migrations' => [
                    ['id' => 'default', 'path' => 'migrations', 'priority' => 'default', 'mode' => 'core'],
                ]]],
            ]]], JSON_UNESCAPED_SLASHES)
        );
    }

    private function writeMigration(string $path, string $class, string $body): void
    {
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, <<<PHP
            <?php

            use Glueful\\Database\\Migrations\\MigrationInterface;
            use Glueful\\Database\\Schema\\Interfaces\\SchemaBuilderInterface;

            class {$class} implements MigrationInterface
            {
                public function up(SchemaBuilderInterface \$schema): void
                {
                    {$body}
                }

                public function down(SchemaBuilderInterface \$schema): void
                {
                }

                public function getDescription(): string
                {
                    return 'fixture';
                }
            }
            PHP);
    }

    private function context(): ApplicationContext
    {
        $context = new ApplicationContext($this->base);
        $context->setConfigLoader(new ConfigurationLoader($this->base, 'testing'));
        return $context;
    }

    private function installer(): Installer
    {
        return new Installer($this->base, $this->context(), skipCacheAndValidation: true);
    }

    private function sqliteConfig(): DatabaseConfig
    {
        return new DatabaseConfig('sqlite', database: $this->base . '/db.sqlite');
    }

    /** @param list<InstallStep> $steps */
    private function step(array $steps, string $name): ?InstallStep
    {
        foreach ($steps as $step) {
            if ($step->name === $name) {
                return $step;
            }
        }
        return null;
    }

    /** @return array<string, list<string>> applied basenames keyed by ledger source */
    private function ledger(): array
    {
        $pdo = (new Connection($this->sqliteConfig()->toConnectionConfig()))->getPDO();
        $bySource = [];
        foreach ($pdo->query('SELECT source, migration FROM migrations ORDER BY source, migration') as $row) {
            $bySource[$row['source']][] = $row['migration'];
        }
        return $bySource;
    }

    public function testProvisionAppliesAppAndCoreDescriptorInOnePass(): void
    {
        $result = $this->installer()->run(new InstallOptions(database: $this->sqliteConfig(), skipKeys: true));

        self::assertSame(InstallStep::OK, $this->step($result->steps, 'migrate')?->status);
        $ledger = $this->ledger();
        self::assertCount(1, $ledger['app'] ?? [], 'the app migration applied');
        self::assertCount(1, $ledger['acme/corelib'] ?? [], 'the manifest core descriptor applied');
    }

    public function testHeldDescriptorSourceLockRefusesProvision(): void
    {
        $holder = MigrationLockFactory::forConnection(
            new Connection($this->sqliteConfig()->toConnectionConfig()),
            $this->context()
        );
        $held = $holder->acquireAll(['acme/corelib']);
        try {
            $result = $this->installer()->run(new InstallOptions(database: $this->sqliteConfig(), skipKeys: true));

            $migrate = $this->step($result->steps, 'migrate');
            self::assertSame(InstallStep::FAILED, $migrate?->status, 'a held descriptor lock refuses provision');
            self::assertStringContainsString('lock', strtolower((string) $migrate?->message));
        } finally {
            $held->release();
        }
    }

    public function testLocksAreFreeAfterSuccessAndAfterFailure(): void
    {
        // Success first.
        $result = $this->installer()->run(new InstallOptions(database: $this->sqliteConfig(), skipKeys: true));
        self::assertSame(InstallStep::OK, $this->step($result->steps, 'migrate')?->status);
        $this->assertSnapshotLockFree();

        // Then a failing run (fresh fixture dir state, new failing core migration).
        $this->writeMigration(
            $this->base . '/vendor/acme/corelib/migrations/002_CorelibFails' . substr(md5($this->base), 0, 8)
                . '.php',
            'CorelibFails' . substr(md5($this->base), 0, 8),
            "throw new \\RuntimeException('descriptor fixture failure');"
        );
        $result = $this->installer()->run(new InstallOptions(database: $this->sqliteConfig(), skipKeys: true));
        self::assertSame(InstallStep::FAILED, $this->step($result->steps, 'migrate')?->status);
        $this->assertSnapshotLockFree();
    }

    private function assertSnapshotLockFree(): void
    {
        $lock = MigrationLockFactory::forConnection(
            new Connection($this->sqliteConfig()->toConnectionConfig()),
            $this->context()
        );
        $probe = $lock->acquireAll(['app', 'acme/corelib'], 1);
        $probe->release();
        self::assertTrue(true, 'installer custody released');
    }

    public function testFailedDescriptorMigrationFailsTheInstallTruthfully(): void
    {
        $this->writeMigration(
            $this->base . '/vendor/acme/corelib/migrations/000_CorelibFailsFirst' . substr(md5($this->base), 0, 8)
                . '.php',
            'CorelibFailsFirst' . substr(md5($this->base), 0, 8),
            "throw new \\RuntimeException('descriptor fixture failure');"
        );

        $result = $this->installer()->run(new InstallOptions(database: $this->sqliteConfig(), skipKeys: true));

        $migrate = $this->step($result->steps, 'migrate');
        self::assertSame(InstallStep::FAILED, $migrate?->status, 'a failed migration can never be install success');
        self::assertStringContainsString('000_CorelibFailsFirst', (string) $migrate?->message);
        self::assertStringContainsString('descriptor fixture failure', (string) $migrate?->message);

        // migrateSources() stops at the first failure: the package's later file stays pending.
        $ledger = $this->ledger();
        self::assertSame([], $ledger['acme/corelib'] ?? [], 'later migrations of the source stay pending');
    }
}

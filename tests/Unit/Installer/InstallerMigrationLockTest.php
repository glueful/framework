<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Installer;

use Glueful\Extensions\Schema\FileMigrationLock;
use Glueful\Installer\DatabaseConfig;
use Glueful\Installer\Installer;
use Glueful\Installer\InstallOptions;
use Glueful\Installer\InstallStep;
use PHPUnit\Framework\TestCase;

/**
 * Provision is serialized under the migration lock (schema policy spec B4): the installer's
 * migrate step contends on the same 'app' source as migrate:run and the enable executor, and it
 * releases its custody after success AND failure.
 */
final class InstallerMigrationLockTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/glueful-inst-lock-' . uniqid('', true);
        mkdir($this->dir . '/migrations', 0777, true);
        file_put_contents($this->dir . '/.env.example', "APP_ENV=local
APP_KEY=
");
    }

    /** The factory's context-less fallback lock dir (sqlite driver => flock backend). */
    private function fallbackLockDir(): string
    {
        return sys_get_temp_dir() . '/glueful-schema-locks';
    }

    private function installer(): Installer
    {
        return new Installer($this->dir, skipCacheAndValidation: true, migrationsPath: $this->dir . '/migrations');
    }

    private function sqliteConfig(): DatabaseConfig
    {
        return new DatabaseConfig('sqlite', database: $this->dir . '/db.sqlite');
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

    public function testInstallerRefusesWhileTheAppSourceLockIsHeld(): void
    {
        $holder = new FileMigrationLock($this->fallbackLockDir());
        $held = $holder->acquireAll(['app']);
        try {
            $result = $this->installer()->run(new InstallOptions(database: $this->sqliteConfig(), skipKeys: true));

            $migrate = $this->step($result->steps, 'migrate');
            self::assertNotNull($migrate);
            self::assertSame(InstallStep::FAILED, $migrate->status, 'a held app lock must refuse the migrate step');
            self::assertStringContainsString('lock', strtolower($migrate->message));
        } finally {
            $held->release();
        }
    }

    public function testInstallerReleasesItsLockAfterSuccess(): void
    {
        $result = $this->installer()->run(new InstallOptions(database: $this->sqliteConfig(), skipKeys: true));
        self::assertSame(InstallStep::OK, $this->step($result->steps, 'migrate')?->status);

        // If the installer leaked custody, this immediate acquire would contend.
        $probe = (new FileMigrationLock($this->fallbackLockDir()))->acquireAll(['app'], waitSeconds: 1);
        $probe->release();
        self::assertTrue(true, 'app lock is free after a successful install');
    }

    public function testInstallerReleasesItsLockAfterFailure(): void
    {
        // A migration that always fails makes the migrate step FAILED — custody must still free.
        file_put_contents($this->dir . '/migrations/001_AlwaysFails.php', <<<'PHP'
            <?php

            use Glueful\Database\Migrations\MigrationInterface;
            use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

            class AlwaysFails implements MigrationInterface
            {
                public function up(SchemaBuilderInterface $schema): void
                {
                    throw new \RuntimeException('installer fixture failure');
                }

                public function down(SchemaBuilderInterface $schema): void
                {
                }

                public function getDescription(): string
                {
                    return 'fixture';
                }
            }
            PHP);

        $result = $this->installer()->run(new InstallOptions(database: $this->sqliteConfig(), skipKeys: true));
        // The run report drives the step: a failed migration is a FAILED install naming the
        // file — and the lock must be free afterwards either way.
        $probe = (new FileMigrationLock($this->fallbackLockDir()))->acquireAll(['app'], waitSeconds: 1);
        $probe->release();
        $migrate = $this->step($result->steps, 'migrate');
        self::assertSame(InstallStep::FAILED, $migrate?->status);
        self::assertStringContainsString('001_AlwaysFails.php', (string) $migrate?->message);
        self::assertStringContainsString('installer fixture failure', (string) $migrate?->message);
    }
}

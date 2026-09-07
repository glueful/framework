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
    public function testIsInstalledMeansAllThreeSecurityKeysArePresent(): void
    {
        $dir = sys_get_temp_dir() . '/installstate_' . uniqid();
        mkdir($dir, 0775, true);

        self::assertFalse((new InstallState($dir))->isInstalled(), 'no .env => not installed');

        file_put_contents($dir . '/.env', "APP_KEY=abc\nJWT_KEY=\nTOKEN_SALT=\n");
        self::assertFalse((new InstallState($dir))->isInstalled(), 'a missing key => first run still pending');

        file_put_contents($dir . '/.env', "APP_KEY=abc\nJWT_KEY=def\nTOKEN_SALT=ghi\n");
        self::assertTrue((new InstallState($dir))->isInstalled());

        @unlink($dir . '/.env');
        @rmdir($dir);
    }
}

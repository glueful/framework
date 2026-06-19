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
}

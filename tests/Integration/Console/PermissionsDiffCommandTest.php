<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Console;

use Glueful\Console\Commands\Permissions\DiffCommand;
use Glueful\Permissions\PermissionManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Smoke test that permissions:diff wires up (ExtensionManager + scanner service + registry +
 * provider resolution) and runs. Classification logic is unit-tested in DiffCommandTest.
 */
final class PermissionsDiffCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        PermissionManager::getInstance()->clearProvider();
        parent::tearDown();
    }

    public function test_runs_and_prints_sections(): void
    {
        PermissionManager::getInstance()->clearProvider();

        $tester = new CommandTester(new DiffCommand());
        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Permissions', $display);
        self::assertStringContainsString('Roles', $display);
    }
}

<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Console;

use Glueful\Console\Commands\Permissions\ListCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * permissions:list self-aggregates and prints the declared catalog. The command builds its own
 * container (BaseCommand), so framework core permissions are always present.
 */
final class PermissionsListCommandTest extends TestCase
{
    public function test_lists_declared_core_permissions(): void
    {
        $tester = new CommandTester(new ListCommand());
        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Declared permissions', $display);
        self::assertStringContainsString('system.access', $display);
        self::assertStringContainsString('glueful/framework', $display);
    }
}

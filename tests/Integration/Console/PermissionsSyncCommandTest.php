<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Console;

use Glueful\Console\Commands\Permissions\SyncCommand;
use Glueful\Interfaces\Permission\{PermissionCatalogSyncInterface, PermissionProviderInterface};
use Glueful\Permissions\Catalog\SyncResult;
use Glueful\Permissions\PermissionManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * permissions:sync self-aggregates (discover + aggregate) then persists via the active provider.
 * The command builds its own container (BaseCommand), so these are integration-level.
 */
final class PermissionsSyncCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        PermissionManager::getInstance()->clearProvider();
        parent::tearDown();
    }

    public function test_syncs_catalog_into_active_sync_provider(): void
    {
        $provider = $this->createMockForIntersectionOfInterfaces([
            PermissionProviderInterface::class,
            PermissionCatalogSyncInterface::class,
        ]);
        $provider->method('getProviderInfo')->willReturn(['name' => 'fake']);
        $provider->method('getAvailablePermissions')->willReturn([
            'system.access' => '', 'users.view' => '', 'users.create' => '',
            'users.edit' => '', 'users.delete' => '',
        ]);
        $provider->method('getManagedCatalog')->willReturn([]);
        $provider->expects(self::once())
            ->method('syncCatalog')
            ->willReturn(new SyncResult(5, 0, 0, []));

        PermissionManager::getInstance()->clearProvider();
        PermissionManager::getInstance()->setProvider($provider, []);

        $tester = new CommandTester(new SyncCommand());
        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('created: 5', $tester->getDisplay());
    }

    public function test_reports_when_no_sync_provider(): void
    {
        PermissionManager::getInstance()->clearProvider();

        $tester = new CommandTester(new SyncCommand());
        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('No persistent permission provider', $tester->getDisplay());
    }
}

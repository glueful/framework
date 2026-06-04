<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Console;

use Glueful\Console\Commands\Permissions\SyncCommand;
use Glueful\Interfaces\Permission\{CatalogPruneInterface, PermissionCatalogSyncInterface, PermissionProviderInterface, RoleCatalogSyncInterface};
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

    public function test_prune_removes_stale_managed_permissions_and_roles(): void
    {
        $provider = $this->createMockForIntersectionOfInterfaces([
            PermissionProviderInterface::class,
            PermissionCatalogSyncInterface::class,
            CatalogPruneInterface::class,
            RoleCatalogSyncInterface::class,
        ]);
        $provider->method('getProviderInfo')->willReturn(['name' => 'fake']);
        $provider->method('getAvailablePermissions')->willReturn([
            'system.access' => '', 'users.view' => '', 'users.create' => '',
            'users.edit' => '', 'users.delete' => '',
        ]);
        $provider->method('getManagedCatalog')->willReturn([]);
        $provider->method('syncCatalog')->willReturn(new SyncResult(0, 0, 1, ['blog.stale']));
        // No roles are declared by the self-aggregated catalog, so a managed role is stale.
        $provider->method('getManagedRoles')->willReturn(['blog.staleRole' => 'vendor/blog']);
        $provider->expects(self::once())->method('pruneCatalog')->with(['blog.stale'])->willReturn(1);
        $provider->expects(self::once())->method('pruneRoles')->with(['blog.staleRole'])->willReturn(1);

        PermissionManager::getInstance()->clearProvider();
        PermissionManager::getInstance()->setProvider($provider, []);

        $tester = new CommandTester(new SyncCommand());
        $exit = $tester->execute(['--prune' => true]);

        self::assertSame(0, $exit);
        $out = $tester->getDisplay();
        self::assertStringContainsString('pruned permissions: 1', $out);
        self::assertStringContainsString('pruned roles: 1', $out);
    }

    public function test_prune_without_capability_fails_loudly(): void
    {
        // Sync-only provider (no prune/role capability) with stale permissions + --prune must
        // NOT print a misleading "Pruned: 0" — it reports unsupported and exits non-zero.
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
        $provider->method('syncCatalog')->willReturn(new SyncResult(0, 0, 0, ['blog.stale']));

        PermissionManager::getInstance()->clearProvider();
        PermissionManager::getInstance()->setProvider($provider, []);

        $tester = new CommandTester(new SyncCommand());
        $exit = $tester->execute(['--prune' => true]);

        self::assertNotSame(0, $exit);
        self::assertStringContainsString('does not support pruning', $tester->getDisplay());
        self::assertStringNotContainsString('Pruned', $tester->getDisplay());
    }
}

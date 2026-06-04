<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Permissions;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\ExtensionManager;
use Glueful\Interfaces\Permission\{PermissionCatalogSyncInterface, RoleCatalogSyncInterface};
use Glueful\Permissions\Catalog\{PermissionAttributeScanner, PermissionRegistry, RoleKey};
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Show drift between declared (registry), enforced (route attributes), and persisted (provider)
 * permissions and roles. Read-only.
 */
#[AsCommand(
    name: 'permissions:diff',
    description: 'Show drift between declared, enforced, and persisted permissions and roles'
)]
final class DiffCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var ExtensionManager $extensions */
        $extensions = $this->getService(ExtensionManager::class);
        $extensions->discover();
        $extensions->aggregatePermissionCatalog();

        /** @var PermissionRegistry $registry */
        $registry = $this->getService(PermissionRegistry::class);
        /** @var PermissionAttributeScanner $scanner */
        $scanner = $this->getService(PermissionAttributeScanner::class);
        $scan = $scanner->scan();

        $provider = null;
        if ($this->getContainer()->has('permission.manager')) {
            /** @var \Glueful\Permissions\PermissionManager $manager */
            $manager = $this->getContainer()->get('permission.manager');
            $provider = $manager->getProvider();
        }
        $sync = $provider instanceof PermissionCatalogSyncInterface ? $provider : null;
        $roleSync = $provider instanceof RoleCatalogSyncInterface ? $provider : null;

        $sections = self::classify(
            $registry->permissionSlugs(),
            $scan['permissions'],
            $registry->roleSlugs(),
            $scan['roles'],
            $sync !== null ? $sync->getManagedCatalog() : [],
            $provider !== null ? $provider->getAvailablePermissions() : [],
            $roleSync !== null ? $roleSync->getManagedRoles() : []
        );

        $output->writeln('<info>Permissions</info>');
        $this->section($output, 'Enforced but undeclared (likely typo / missing declaration)', $sections['perm_enforced_undeclared']);
        $this->section($output, 'Declared but unenforced (orphan?)', $sections['perm_declared_unenforced']);
        $this->section($output, 'Stale managed (declared nowhere — prunable with --prune)', $sections['perm_stale_managed']);
        $this->section($output, 'Unmanaged persisted (hand-created — informational, never pruned)', $sections['perm_unmanaged_persisted']);

        $output->writeln('<info>Roles</info>');
        $this->section($output, 'Enforced but undeclared (role)', $sections['role_enforced_undeclared']);
        $this->section($output, 'Declared but unenforced (role)', $sections['role_declared_unenforced']);
        $this->section($output, 'Stale managed roles (declared nowhere — prunable with --prune)', $sections['role_stale_managed']);

        return self::SUCCESS;
    }

    /**
     * Pure classification (no container) — the testable core.
     *
     * @param string[] $declaredPerms
     * @param string[] $enforcedPerms
     * @param string[] $declaredRoles
     * @param string[] $enforcedRoles
     * @param array<string,string> $managedPerms  slug => managed_by
     * @param array<string,string> $persistedAllPerms slug => description
     * @param array<string,string> $managedRoles  slug => managed_by
     * @return array<string, string[]>
     */
    public static function classify(
        array $declaredPerms,
        array $enforcedPerms,
        array $declaredRoles,
        array $enforcedRoles,
        array $managedPerms,
        array $persistedAllPerms,
        array $managedRoles
    ): array {
        $managedKeys = array_keys($managedPerms);
        $persistedKeys = array_keys($persistedAllPerms);
        // Roles: enforced-vs-declared compared via canonical RoleKey; stale uses raw DTO slugs.
        $declaredRoleKeys = array_map([RoleKey::class, 'canonical'], $declaredRoles);
        $enforcedRoleKeys = array_map([RoleKey::class, 'canonical'], $enforcedRoles);

        return [
            'perm_enforced_undeclared' => array_values(array_diff($enforcedPerms, $declaredPerms)),
            'perm_declared_unenforced' => array_values(array_diff($declaredPerms, $enforcedPerms)),
            'perm_stale_managed' => array_values(array_diff($managedKeys, $declaredPerms)),
            'perm_unmanaged_persisted' => array_values(array_diff($persistedKeys, $managedKeys)),
            'role_enforced_undeclared' => array_values(array_diff($enforcedRoleKeys, $declaredRoleKeys)),
            'role_declared_unenforced' => array_values(array_diff($declaredRoleKeys, $enforcedRoleKeys)),
            'role_stale_managed' => array_values(array_diff(array_keys($managedRoles), $declaredRoles)),
        ];
    }

    /** @param string[] $items */
    private function section(OutputInterface $output, string $title, array $items): void
    {
        if (count($items) === 0) {
            return;
        }
        $output->writeln('  <comment>' . $title . ':</comment>');
        foreach ($items as $slug) {
            $output->writeln('    - ' . $slug);
        }
    }
}

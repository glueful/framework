<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Permissions;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\ExtensionManager;
use Glueful\Interfaces\Permission\{CatalogPruneInterface, PermissionCatalogSyncInterface, RoleCatalogSyncInterface};
use Glueful\Permissions\Catalog\PermissionRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Persist the declared permission catalog into the active provider.
 *
 * The command builds the catalog from scratch (discover + aggregate) so it never depends on
 * prior boot state — the CLI may run in a freshly-built container. Sync is migration-like and
 * only happens here, never during boot.
 */
#[AsCommand(
    name: 'permissions:sync',
    description: 'Persist the declared permission catalog into the active provider'
)]
final class SyncCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption(
            'prune',
            null,
            InputOption::VALUE_NONE,
            'Delete managed permissions and roles that are no longer declared'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Rebuild the catalog deterministically for the CLI (independent of any prior boot).
        /** @var ExtensionManager $extensions */
        $extensions = $this->getService(ExtensionManager::class);
        $extensions->discover();
        $extensions->aggregatePermissionCatalog();

        /** @var PermissionRegistry $registry */
        $registry = $this->getService(PermissionRegistry::class);

        $provider = null;
        if ($this->getContainer()->has('permission.manager')) {
            /** @var \Glueful\Permissions\PermissionManager $manager */
            $manager = $this->getContainer()->get('permission.manager');
            $active = $manager->getProvider();
            if ($active instanceof PermissionCatalogSyncInterface) {
                $provider = $active;
            }
        }

        if ($provider === null) {
            $output->writeln(
                '<comment>No persistent permission provider installed; '
                . 'declarations remain in-registry only.</comment>'
            );
            return self::SUCCESS;
        }

        $permissions = array_map(static fn($p) => $p->toArray(), $registry->permissions());
        $roles = array_map(static fn($r) => $r->toArray(), $registry->roles());

        $result = $provider->syncCatalog($permissions, $roles);

        $output->writeln(sprintf(
            '<info>Catalog synced</info> — created: %d, updated: %d, unchanged: %d',
            $result->created,
            $result->updated,
            $result->unchanged
        ));

        return $this->handleStale($input, $output, $provider, $registry, $result->stale);
    }

    /**
     * Report (and optionally prune) stale managed permissions and roles.
     *
     * @param string[] $stalePermissions
     */
    private function handleStale(
        InputInterface $input,
        OutputInterface $output,
        PermissionCatalogSyncInterface $provider,
        PermissionRegistry $registry,
        array $stalePermissions
    ): int {
        // Capabilities are opt-in (interface segregation) — a permission-only provider has neither.
        $pruneCap = $provider instanceof CatalogPruneInterface ? $provider : null;
        $roleSync = $provider instanceof RoleCatalogSyncInterface ? $provider : null;

        $staleRoles = $roleSync !== null
            ? array_values(array_diff(array_keys($roleSync->getManagedRoles()), $registry->roleSlugs()))
            : [];

        if (count($stalePermissions) > 0) {
            $output->writeln('<comment>Stale managed permissions: ' . implode(', ', $stalePermissions) . '</comment>');
        }
        if (count($staleRoles) > 0) {
            $output->writeln('<comment>Stale managed roles: ' . implode(', ', $staleRoles) . '</comment>');
        }

        $hasStale = count($stalePermissions) > 0 || count($staleRoles) > 0;
        if (!(bool) $input->getOption('prune')) {
            if ($hasStale) {
                $output->writeln('<comment>Run with --prune to remove them.</comment>');
            }
            return self::SUCCESS;
        }
        if (!$hasStale) {
            return self::SUCCESS;
        }

        // --prune is explicit and destructive: if anything stale cannot be pruned because the
        // provider lacks the capability, fail loudly rather than printing a misleading "Pruned: 0".
        $unsupported = [];
        if (count($stalePermissions) > 0 && $pruneCap === null) {
            $unsupported[] = 'permissions';
        }
        if (count($staleRoles) > 0 && $roleSync === null) {
            $unsupported[] = 'roles';
        }
        if ($unsupported !== []) {
            $output->writeln(
                '<error>Provider does not support pruning ' . implode(' and ', $unsupported)
                . '; nothing pruned.</error>'
            );
            return self::FAILURE;
        }

        $prunedPerms = $pruneCap !== null ? $pruneCap->pruneCatalog($stalePermissions) : 0;
        $prunedRoles = $roleSync !== null ? $roleSync->pruneRoles($staleRoles) : 0;
        $output->writeln(sprintf(
            '<info>Pruned</info> — pruned permissions: %d, pruned roles: %d',
            $prunedPerms,
            $prunedRoles
        ));

        return self::SUCCESS;
    }
}

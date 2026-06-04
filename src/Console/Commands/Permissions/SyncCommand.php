<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Permissions;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\ExtensionManager;
use Glueful\Interfaces\Permission\PermissionCatalogSyncInterface;
use Glueful\Permissions\Catalog\PermissionRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
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
        if (count($result->stale) > 0) {
            $output->writeln(
                '<comment>Stale (managed, no longer declared): '
                . implode(', ', $result->stale) . '</comment>'
            );
            $output->writeln('<comment>Run permissions:diff to review; pruning arrives in Phase 2.</comment>');
        }

        return self::SUCCESS;
    }
}

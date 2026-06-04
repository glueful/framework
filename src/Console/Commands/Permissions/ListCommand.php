<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Permissions;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\ExtensionManager;
use Glueful\Permissions\Catalog\PermissionRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * List the declared permission catalog grouped by category. Self-aggregates so the output
 * reflects the current declarations regardless of prior boot state.
 */
#[AsCommand(
    name: 'permissions:list',
    description: 'List the declared permission catalog grouped by category'
)]
final class ListCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var ExtensionManager $extensions */
        $extensions = $this->getService(ExtensionManager::class);
        $extensions->discover();
        $extensions->aggregatePermissionCatalog();

        /** @var PermissionRegistry $registry */
        $registry = $this->getService(PermissionRegistry::class);

        $output->writeln('<info>Declared permissions</info>');
        foreach ($registry->permissionsByCategory() as $category => $perms) {
            $output->writeln('  <comment>' . ($category !== '' ? $category : 'uncategorized') . '</comment>');
            foreach ($perms as $perm) {
                $slug = $perm->slug();
                $output->writeln(sprintf('    %-40s %s', $slug, $registry->sourceOf($slug) ?? ''));
            }
        }

        if (count($registry->roleSlugs()) > 0) {
            $output->writeln('<info>Declared roles</info>');
            foreach ($registry->roles() as $role) {
                $arr = $role->toArray();
                $output->writeln(sprintf('    %-40s grants: %s', $arr['slug'], implode(', ', $arr['grants'])));
            }
        }

        return self::SUCCESS;
    }
}

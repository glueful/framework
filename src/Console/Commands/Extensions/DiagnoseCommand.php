<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Extensions;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\ExtensionManager;
use Glueful\Extensions\PackageManifest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'extensions:diagnose',
    description: 'Diagnose extension discovery and configuration issues'
)]
final class DiagnoseCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $manager = container($this->getContext())->get(ExtensionManager::class);

        $loaded = $manager->getProviders();
        $manifest = new PackageManifest($this->getContext());
        $composerProviders = $manifest->getGluefulProviders();

        $rows = [];
        foreach ($composerProviders as $pkg => $provider) {
            $status = isset($loaded[$provider]) ? 'loaded' : 'not_loaded';
            $rows[] = [
                'package' => $pkg,
                'provider' => $provider,
                'status' => $status,
            ];
        }

        if ($rows === []) {
            $this->warning('No glueful-extension packages discovered via Composer');
        } else {
            $tableRows = array_map(
                fn($r) => [$r['package'], $r['provider'], $r['status']],
                $rows
            );
            $this->table(['Package', 'Provider', 'Status'], $tableRows);
        }

        // Summarize loaded providers
        if ($loaded !== []) {
            $this->info('Loaded providers:');
            foreach ($loaded as $class => $instance) {
                $this->line(' - ' . $class);
            }
        }

        return self::SUCCESS;
    }
}

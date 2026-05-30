<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Extensions;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\EnabledProviders;
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
        $context = $this->getContext();
        $manager = $this->getService(ExtensionManager::class);

        $candidates = (new PackageManifest($context))->getCandidates();
        $enabledSet = array_fill_keys(EnabledProviders::from($context), true);

        // Resolve through the shared path; capture errors.
        $resolved = $manager->resolveProviderClasses();
        $errors = $manager->getResolverErrors();

        if ($candidates === []) {
            $this->warning('No glueful-extension packages discovered via Composer');
        } else {
            $rows = [];
            foreach ($candidates as $name => $c) {
                $rows[] = [
                    $name,
                    $c->provider,
                    isset($enabledSet[$c->provider]) ? 'enabled' : 'available',
                ];
            }
            $this->table(['Package', 'Provider', 'State'], $rows);
        }

        // Resolver errors (missing provider/dependency, version mismatch, cycle).
        if ($errors !== []) {
            $this->error('Resolver errors:');
            foreach ($errors as $e) {
                $this->line("  [{$e->kind}] {$e->message}");
            }
        } else {
            $this->info('Resolver: no errors.');
        }

        // Resolved (ordered) provider classes that will load.
        if ($resolved !== []) {
            $this->info('Resolved providers (load order):');
            foreach ($resolved as $class) {
                $this->line(' - ' . $class);
            }
        }

        // Production must boot from a compiled manifest.
        if (env('APP_ENV', 'production') === 'production') {
            $cacheFile = base_path($context, 'bootstrap/cache/extensions.php');
            if (!is_file($cacheFile) || !is_readable($cacheFile)) {
                $this->error(
                    'Production cache missing/unreadable at bootstrap/cache/extensions.php. '
                    . 'Run: php glueful extensions:cache'
                );
                return self::FAILURE;
            }
            $this->info('Production cache present and readable.');
        }

        return $errors === [] ? self::SUCCESS : self::FAILURE;
    }
}

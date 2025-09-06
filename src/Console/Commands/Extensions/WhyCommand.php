<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Extensions;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\ExtensionManager;
use Glueful\Extensions\ProviderLocator;
use Glueful\Extensions\PackageManifest;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Extensions Why Command
 *
 * Debug provider inclusion/exclusion reasoning with detailed discovery analysis.
 * Shows exactly why a provider was included/excluded and from which source.
 */
#[AsCommand(
    name: 'extensions:why',
    description: 'Explain why/how a provider was included or excluded'
)]
final class WhyCommand extends BaseCommand
{
    private ExtensionManager $extensions;

    public function __construct()
    {
        parent::__construct();
        $this->extensions = $this->getService(ExtensionManager::class);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Explain why/how a provider was included or excluded')
            ->addArgument('provider', InputArgument::REQUIRED, 'Provider class name to analyze');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $needle = (string) $input->getArgument('provider');

        // Normalize the provider name (allow partial matches)
        $needle = $this->normalizeProviderName($needle);

        $output->writeln("Analyzing provider: <info>{$needle}</info>");
        $output->writeln('');

        // Check if provider is currently loaded
        $loadedProviders = $this->extensions->getProviders();
        $isLoaded = isset($loadedProviders[$needle]);

        if ($isLoaded) {
            $output->writeln('✓ <info>Status: INCLUDED in final provider list</info>');
        } else {
            $output->writeln('✗ <error>Status: EXCLUDED from final provider list</error>');
        }

        // Analyze discovery sources
        $this->analyzeDiscoverySources($needle, $output);

        // Check configuration impact
        $this->analyzeConfigurationImpact($needle, $output);

        // Show load order and dependencies if loaded
        if ($isLoaded) {
            $this->analyzeLoadOrder($needle, $output);
        }

        return self::SUCCESS;
    }

    private function normalizeProviderName(string $needle): string
    {
        // If it doesn't contain backslashes, try to find a matching provider
        if (!str_contains($needle, '\\')) {
            $loadedProviders = $this->extensions->getProviders();
            foreach (array_keys($loadedProviders) as $providerClass) {
                $className = basename(str_replace('\\', '/', $providerClass));
                if (stripos($className, $needle) !== false) {
                    return $providerClass;
                }
            }

            // Also check all discoverable providers via ProviderLocator
            foreach (ProviderLocator::all() as $providerClass) {
                $className = basename(str_replace('\\', '/', $providerClass));
                if (stripos($className, $needle) !== false) {
                    return $providerClass;
                }
            }
        }

        return $needle;
    }

    private function analyzeDiscoverySources(string $needle, OutputInterface $output): void
    {
        $output->writeln('<info>Discovery Analysis:</info>');

        // Check enabled config
        $enabled = (array) config('extensions.enabled', []);
        if (in_array($needle, $enabled, true)) {
            $output->writeln('✓ Found in: extensions.enabled config');
        }

        // Check dev_only config
        $devOnly = (array) config('extensions.dev_only', []);
        if (in_array($needle, $devOnly, true)) {
            $appEnv = $_ENV['APP_ENV'] ?? (getenv('APP_ENV') !== false ? getenv('APP_ENV') : 'production');
            if ($appEnv !== 'production') {
                $output->writeln('✓ Found in: extensions.dev_only config (active in non-production)');
            } else {
                $output->writeln('✗ Found in: extensions.dev_only config (ignored in production)');
            }
        }

        // Check Composer packages
        $scanComposer = config('extensions.scan_composer', true);
        if ($scanComposer === true) {
            $manifest = new PackageManifest();
            $composerProviders = $manifest->getGluefulProviders();
            $composerProvider = array_search($needle, $composerProviders, true);
            if ($composerProvider !== false) {
                $output->writeln("✓ Found in: Composer packages (package: {$composerProvider})");
            }
        } else {
            $output->writeln('- Composer scanning disabled');
        }

        // Check local scan
        $appEnv = $_ENV['APP_ENV'] ?? (getenv('APP_ENV') !== false ? getenv('APP_ENV') : 'production');
        if ($appEnv !== 'production') {
            $localPath = config('extensions.local_path');
            if ($localPath !== null) {
                $localProviders = $this->scanLocalExtensions($localPath);
                if (in_array($needle, $localProviders, true)) {
                    $output->writeln("✓ Found in: Local scan (path: {$localPath})");
                }
            } else {
                $output->writeln('- Local scanning disabled');
            }
        } else {
            $output->writeln('- Local scanning disabled in production');
        }

        $output->writeln('');
    }

    private function analyzeConfigurationImpact(string $needle, OutputInterface $output): void
    {
        $output->writeln('<info>Configuration Impact:</info>');

        // Check allow-list mode
        $only = config('extensions.only');
        if ($only !== null) {
            $onlyArray = (array) $only;
            if (in_array($needle, $onlyArray, true)) {
                $output->writeln('✓ Reason: Included in extensions.only (allow-list mode)');
            } else {
                $output->writeln('✗ Reason: NOT in extensions.only (allow-list mode blocks all others)');
                return; // In allow-list mode, this is the final decision
            }
        }

        // Check blacklist
        $disabled = (array) config('extensions.disabled', []);
        if (in_array($needle, $disabled, true)) {
            $output->writeln('✗ Reason: Listed in extensions.disabled (blacklisted)');
        } elseif (count($disabled) > 0) {
            $output->writeln('✓ Reason: NOT in extensions.disabled blacklist');
        }

        // Check if class exists
        if (!class_exists($needle)) {
            $output->writeln('✗ Reason: Provider class does not exist or is not autoloadable');
        } else {
            $output->writeln('✓ Reason: Provider class exists and is autoloadable');
        }

        $output->writeln('');
    }

    private function analyzeLoadOrder(string $needle, OutputInterface $output): void
    {
        $output->writeln('<info>Load Order & Dependencies:</info>');

        $providers = $this->extensions->getProviders();
        $provider = $providers[$needle] ?? null;

        if ($provider === null) {
            $output->writeln('- Not available (provider not loaded)');
            return;
        }

        // Check priority
        $priority = 0;
        if ($provider instanceof \Glueful\Extensions\OrderedProvider) {
            $priority = $provider->priority();
            $dependencies = $provider->bootAfter();

            $output->writeln("Priority: {$priority}");

            if (count($dependencies) > 0) {
                $output->writeln('Dependencies (bootAfter):');
                foreach ($dependencies as $dep) {
                    $depLoaded = isset($providers[$dep]) ? '✓' : '✗';
                    $output->writeln("  {$depLoaded} {$dep}");
                }
            } else {
                $output->writeln('Dependencies: none');
            }
        } else {
            $output->writeln('Priority: 0 (default)');
            $output->writeln('Dependencies: none');
        }

        // Show position in load order
        $position = array_search($needle, array_keys($providers), true);
        if ($position !== false) {
            $total = count($providers);
            $output->writeln("Load position: " . ($position + 1) . " of {$total}");
        }

        $output->writeln('');
    }

    /**
     * @return list<class-string>
     */
    private function scanLocalExtensions(string $path): array
    {
        $providers = [];
        $extensionsPath = base_path($path);

        if (!is_dir($extensionsPath)) {
            return [];
        }

        $pattern = $extensionsPath . '/*/composer.json';
        $files = glob($pattern);
        if ($files === false) {
            return [];
        }

        foreach ($files as $file) {
            if (is_link($file) || !is_readable($file)) {
                continue;
            }

            $filesize = @filesize($file);
            if ($filesize === false || $filesize > 1024 * 100) {
                continue;
            }

            try {
                $json = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
                if (isset($json['extra']['glueful']['provider'])) {
                    $providers[] = $json['extra']['glueful']['provider'];
                }
            } catch (\JsonException) {
                continue;
            }
        }

        return $providers;
    }
}

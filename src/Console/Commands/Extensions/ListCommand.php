<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Extensions;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\ExtensionManager;
use Glueful\Extensions\ProviderLocator;
use Glueful\Extensions\PackageManifest;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Enhanced Extensions List Command
 *
 * List discovered extensions with comprehensive status information:
 * - Status indicators (✓/✗)
 * - Discovery source identification
 * - Dependency information
 * - Performance metrics
 */
#[AsCommand(
    name: 'extensions:list',
    description: 'List all discovered extensions with status'
)]
final class ListCommand extends BaseCommand
{
    private ExtensionManager $extensions;

    public function __construct()
    {
        parent::__construct();
        $this->extensions = $this->getService(ExtensionManager::class);
    }

    protected function configure(): void
    {
        $this->setDescription('List discovered extensions with comprehensive status');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $startTime = microtime(true);

        // Get all providers and metadata
        $loadedProviders = $this->extensions->getProviders();
        $meta = $this->extensions->listMeta();

        // Get all discoverable providers to show disabled ones too
        $allDiscoverable = ProviderLocator::all();
        $disabledProviders = $this->getDisabledProviders();

        if (count($loadedProviders) === 0 && count($disabledProviders) === 0) {
            $output->writeln('<comment>No extensions discovered.</comment>');
            return self::SUCCESS;
        }

        // Enhanced table header
        $output->writeln('<info>Extensions Status Report</info>');
        $output->writeln('========================');
        $output->writeln('');

        $output->writeln(sprintf(
            "%-3s %-25s %-15s %-10s %-20s %s",
            'St',
            'Provider',
            'Source',
            'Priority',
            'Dependencies',
            'Version'
        ));
        $output->writeln(str_repeat('-', 100));

        // Show loaded providers
        foreach ($loadedProviders as $class => $provider) {
            $m = $meta[$class] ?? [];
            $source = $this->getDiscoverySource($class);
            $priority = $this->getProviderPriority($provider);
            $dependencies = $this->getProviderDependencies($provider);

            $output->writeln(sprintf(
                "%-3s %-25s %-15s %-10s %-20s %s",
                '✓',
                $this->truncate($class, 25),
                $source,
                $priority,
                $this->truncate($dependencies, 20),
                $m['version'] ?? 'n/a'
            ));
        }

        // Show disabled providers
        foreach ($disabledProviders as $class) {
            $source = $this->getDiscoverySource($class);
            $reason = $this->getDisabledReason($class);

            $output->writeln(sprintf(
                "%-3s %-25s %-15s %-10s %-20s %s",
                '✗',
                $this->truncate($class, 25),
                $source,
                'disabled',
                $this->truncate($reason, 20),
                'n/a'
            ));
        }

        // Performance metrics and summary
        $loadTime = round((microtime(true) - $startTime) * 1000, 2);
        $cacheUsed = $this->extensions->getCacheUsed();

        $output->writeln('');
        $output->writeln('<info>Summary:</info>');
        $output->writeln(sprintf('Active providers:   %d', count($loadedProviders)));
        $output->writeln(sprintf('Disabled providers: %d', count($disabledProviders)));
        $output->writeln(sprintf('Cache used:         %s', $cacheUsed ? 'yes' : 'no'));
        $output->writeln(sprintf('Discovery time:     %s ms', $loadTime));

        return self::SUCCESS;
    }

    private function getDiscoverySource(string $class): string
    {
        // Check enabled config
        $enabled = (array) config('extensions.enabled', []);
        if (in_array($class, $enabled, true)) {
            return 'config';
        }

        // Check dev_only config
        $devOnly = (array) config('extensions.dev_only', []);
        if (in_array($class, $devOnly, true)) {
            return 'dev_only';
        }

        // Check Composer packages
        $scanComposer = config('extensions.scan_composer', true);
        if ($scanComposer === true) {
            $manifest = new PackageManifest();
            $composerProviders = $manifest->getGluefulProviders();
            if (in_array($class, $composerProviders, true)) {
                return 'composer';
            }
        }

        // Check local scan
        $appEnv = $_ENV['APP_ENV'] ?? (getenv('APP_ENV') !== false ? getenv('APP_ENV') : 'production');
        if ($appEnv !== 'production') {
            $localPath = config('extensions.local_path');
            if ($localPath !== null) {
                $localProviders = $this->scanLocalExtensions($localPath);
                if (in_array($class, $localProviders, true)) {
                    return 'local';
                }
            }
        }

        return 'unknown';
    }

    private function getProviderPriority(object $provider): string
    {
        if ($provider instanceof \Glueful\Extensions\OrderedProvider) {
            return (string) $provider->priority();
        }
        return '0';
    }

    private function getProviderDependencies(object $provider): string
    {
        if ($provider instanceof \Glueful\Extensions\OrderedProvider) {
            $dependencies = $provider->bootAfter();
            if (count($dependencies) === 0) {
                return 'none';
            }
            return count($dependencies) === 1
                ? basename(str_replace('\\', '/', $dependencies[0]))
                : count($dependencies) . ' deps';
        }
        return 'none';
    }

    /**
     * @return array<class-string>
     */
    private function getDisabledProviders(): array
    {
        $disabled = [];
        $disabledConfig = (array) config('extensions.disabled', []);

        // Get all possible providers from discovery sources
        $allDiscovered = [];

        // Check enabled config
        $allDiscovered = array_merge($allDiscovered, (array) config('extensions.enabled', []));

        // Check dev_only config
        $appEnv = $_ENV['APP_ENV'] ?? (getenv('APP_ENV') !== false ? getenv('APP_ENV') : 'production');
        if ($appEnv !== 'production') {
            $allDiscovered = array_merge($allDiscovered, (array) config('extensions.dev_only', []));

            // Check local scan
            $localPath = config('extensions.local_path');
            if ($localPath !== null) {
                try {
                    $local = $this->scanLocalExtensions($localPath);
                    $allDiscovered = array_merge($allDiscovered, $local);
                } catch (\Throwable) {
                    // Ignore scan errors in list command
                }
            }
        }

        // Check Composer packages
        $scanComposerDisabled = config('extensions.scan_composer', true);
        if ($scanComposerDisabled === true) {
            try {
                $manifest = new PackageManifest();
                $composer = $manifest->getGluefulProviders();
                $allDiscovered = array_merge($allDiscovered, array_values($composer));
            } catch (\Throwable) {
                // Ignore composer scan errors
            }
        }

        // Find providers that were discovered but are disabled
        foreach (array_unique($allDiscovered) as $provider) {
            if (in_array($provider, $disabledConfig, true)) {
                $disabled[] = $provider;
            }
        }

        return $disabled;
    }

    private function getDisabledReason(string $class): string
    {
        $disabled = (array) config('extensions.disabled', []);
        if (in_array($class, $disabled, true)) {
            return 'blacklisted';
        }

        // Check allow-list mode
        $only = config('extensions.only');
        if ($only !== null) {
            $onlyArray = (array) $only;
            if (!in_array($class, $onlyArray, true)) {
                return 'not in allow-list';
            }
        }

        return 'unknown';
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

    private function truncate(string $text, int $length): string
    {
        if (strlen($text) <= $length) {
            return $text;
        }
        return substr($text, 0, $length - 3) . '...';
    }
}

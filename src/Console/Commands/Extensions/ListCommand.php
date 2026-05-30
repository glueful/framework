<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Extensions;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\EnabledProviders;
use Glueful\Extensions\ExtensionManager;
use Glueful\Extensions\PackageManifest;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Extensions List Command
 *
 * Lists every composer-discovered extension candidate cross-referenced with the
 * `extensions.enabled` allow-list, showing each one's state:
 *   ✓ enabled              — discovered and in the allow-list (it loads)
 *   ○ available            — discovered but not enabled (does nothing)
 *   ⚠ enabled-but-missing  — in the allow-list but not installed/discovered
 *
 * This folds in the old `why` command: the state column is the reason.
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
        $context = $this->getContext();
        $candidates = (new PackageManifest($context))->getCandidates();
        $meta = $this->extensions->listMeta();

        $enabled = EnabledProviders::from($context);
        $enabledSet = array_fill_keys($enabled, true);

        // Providers that exist as candidates (used to detect enabled-but-missing).
        $candidateProviders = [];
        foreach ($candidates as $c) {
            $candidateProviders[$c->provider] = true;
        }

        if (count($candidates) === 0 && count($enabled) === 0) {
            $output->writeln('<comment>No extensions discovered and none enabled.</comment>');
            return self::SUCCESS;
        }

        $output->writeln('<info>Extensions Status Report</info>');
        $output->writeln('========================');
        $output->writeln('');
        $output->writeln(sprintf(
            '%-3s %-22s %-40s %-12s %s',
            'St',
            'Package',
            'Provider',
            'Requires',
            'Version'
        ));
        $output->writeln(str_repeat('-', 100));

        $enabledCount = 0;
        $availableCount = 0;

        // Discovered candidates (enabled ✓ or available ○).
        foreach ($candidates as $name => $c) {
            $isEnabled = isset($enabledSet[$c->provider]);
            $isEnabled ? $enabledCount++ : $availableCount++;
            $m = $meta[$c->provider] ?? [];

            $version = $c->version ?? (is_string($m['version'] ?? null) ? $m['version'] : null);
            $output->writeln(sprintf(
                '%-3s %-22s %-40s %-12s %s',
                $isEnabled ? '✓' : '○',
                $this->truncate($name, 22),
                $this->truncate($c->provider, 40),
                $this->truncate($c->requiresGlueful ?? '*', 12),
                $version ?? 'n/a'
            ));
        }

        // Enabled-but-missing (⚠): in the allow-list but not discovered.
        $missing = [];
        foreach ($enabled as $provider) {
            if (!isset($candidateProviders[$provider])) {
                $missing[] = $provider;
                $output->writeln(sprintf(
                    '%-3s %-22s %-40s %-12s %s',
                    '⚠',
                    '(not installed)',
                    $this->truncate($provider, 40),
                    '-',
                    'n/a'
                ));
            }
        }

        $output->writeln('');
        $output->writeln('<info>Summary:</info>');
        $output->writeln(sprintf('Enabled:             %d', $enabledCount));
        $output->writeln(sprintf('Available (off):     %d', $availableCount));
        $output->writeln(sprintf('Enabled-but-missing: %d', count($missing)));
        $output->writeln(sprintf('Cache used:          %s', $this->extensions->getCacheUsed() ? 'yes' : 'no'));

        if (count($missing) > 0) {
            $output->writeln('');
            $output->writeln(
                '<comment>⚠ Some enabled providers are not installed. Install them (composer require) '
                . 'or remove them with: php glueful extensions:disable <provider></comment>'
            );
        }

        return self::SUCCESS;
    }

    private function truncate(string $text, int $length): string
    {
        if (strlen($text) <= $length) {
            return $text;
        }
        return substr($text, 0, $length - 3) . '...';
    }
}

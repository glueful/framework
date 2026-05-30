<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Extensions;

use Glueful\Console\BaseCommand;
use Glueful\Console\Commands\Extensions\Concerns\ResolvesExtensionNeedle;
use Glueful\Extensions\EnabledProviders;
use Glueful\Extensions\ExtensionManager;
use Glueful\Extensions\PackageManifest;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Extensions Info Command
 *
 * Show detailed extension information by package name, provider FQCN, or slug:
 * package, provider, framework/extension requirements, enabled state, plus any
 * runtime metadata the provider registered.
 */
#[AsCommand(
    name: 'extensions:info',
    description: 'Show detailed extension information'
)]
final class InfoCommand extends BaseCommand
{
    use ResolvesExtensionNeedle;

    private ExtensionManager $extensions;

    public function __construct()
    {
        parent::__construct();
        $this->extensions = $this->getService(ExtensionManager::class);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Show detailed extension information')
            ->addArgument('slugOrClass', InputArgument::REQUIRED, 'Extension package name, provider FQCN, or slug');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $needle = (string) $input->getArgument('slugOrClass');
        $context = $this->getContext();
        $candidates = (new PackageManifest($context))->getCandidates();

        $providerClass = $this->resolveNeedle($needle, $candidates);
        if ($providerClass === null) {
            $output->writeln("<error>Extension not found among installed packages: {$needle}</error>");
            return self::FAILURE;
        }

        // Locate the candidate (by provider FQCN) for its package name + requires.
        $candidate = null;
        foreach ($candidates as $c) {
            if ($c->provider === $providerClass) {
                $candidate = $c;
                break;
            }
        }

        $isEnabled = in_array($providerClass, EnabledProviders::from($context), true);
        $m = $this->extensions->listMeta()[$providerClass] ?? [];

        $output->writeln('<info>Extension Information</info>');
        $output->writeln('=====================');
        $output->writeln('Package:        ' . ($candidate?->name ?? 'n/a'));
        $output->writeln('Provider:       ' . $providerClass);
        $output->writeln('State:          ' . ($isEnabled ? 'enabled ✓' : 'available ○ (not enabled)'));
        $output->writeln('Requires (fw):  ' . ($candidate?->requiresGlueful ?? '*'));
        $deps = $candidate?->requiresExtensions ?? [];
        $output->writeln('Requires (ext): ' . ($deps === [] ? 'none' : implode(', ', $deps)));
        $output->writeln('Name:           ' . (is_string($m['name'] ?? null) ? $m['name'] : 'n/a'));
        $version = $candidate?->version ?? (is_string($m['version'] ?? null) ? $m['version'] : null);
        $output->writeln('Version:        ' . ($version ?? 'n/a'));
        $output->writeln('Description:    ' . (is_string($m['description'] ?? null) ? $m['description'] : ''));

        foreach ($m as $key => $value) {
            if (!in_array($key, ['name', 'slug', 'version', 'description'], true)) {
                $output->writeln(sprintf(
                    '%-15s %s',
                    ucfirst((string) $key) . ':',
                    is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value
                ));
            }
        }

        return self::SUCCESS;
    }
}

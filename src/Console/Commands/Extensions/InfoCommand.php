<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Extensions;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\ExtensionManager;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Extensions Info Command
 *
 * Show detailed extension information by slug or provider FQCN.
 */
#[AsCommand(
    name: 'extensions:info',
    description: 'Show detailed extension information'
)]
final class InfoCommand extends BaseCommand
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
            ->setDescription('Show detailed extension information')
            ->addArgument('slugOrClass', InputArgument::REQUIRED, 'Extension slug or provider FQCN');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $needle = (string) $input->getArgument('slugOrClass');
        $metaAll = $this->extensions->listMeta();
        $providers = $this->extensions->getProviders();

        // Resolve by slug or FQCN
        $class = null;
        foreach (array_keys($providers) as $providerClass) {
            $m = $metaAll[$providerClass] ?? [];
            if ($providerClass === $needle || ($m['slug'] ?? null) === $needle) {
                $class = $providerClass;
                break;
            }
        }

        if ($class === null) {
            $output->writeln("<error>Extension not found: {$needle}</error>");
            return self::FAILURE;
        }

        $m = $metaAll[$class] ?? [];

        $output->writeln('<info>Extension Information</info>');
        $output->writeln('=====================');
        $output->writeln('Provider:       ' . $class);
        $output->writeln('Name:           ' . ($m['name'] ?? 'n/a'));
        $output->writeln('Slug:           ' . ($m['slug'] ?? 'n/a'));
        $output->writeln('Version:        ' . ($m['version'] ?? 'n/a'));
        $output->writeln('Description:    ' . ($m['description'] ?? ''));

        // Add any additional metadata
        foreach ($m as $key => $value) {
            if (!in_array($key, ['name', 'slug', 'version', 'description'], true)) {
                $output->writeln(sprintf(
                    '%-15s %s',
                    ucfirst($key) . ':',
                    is_array($value) ? implode(', ', $value) : (string) $value
                ));
            }
        }

        return self::SUCCESS;
    }
}

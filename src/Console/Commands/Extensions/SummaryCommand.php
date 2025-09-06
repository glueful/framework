<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Extensions;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\ExtensionManager;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Extensions Summary Command
 *
 * Show startup summary and diagnostics for the extension system.
 */
#[AsCommand(
    name: 'extensions:summary',
    description: 'Show startup summary and diagnostics'
)]
final class SummaryCommand extends BaseCommand
{
    private ExtensionManager $extensions;

    public function __construct()
    {
        parent::__construct();
        $this->extensions = $this->getService(ExtensionManager::class);
    }

    protected function configure(): void
    {
        $this->setDescription('Show startup summary and diagnostics');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $s = $this->extensions->getSummary();

        $output->writeln('<info>Extensions Summary</info>');
        $output->writeln('==================');
        $output->writeln('Total providers: ' . $s['total_providers']);
        $output->writeln('Booted:          ' . ((bool) $s['booted'] ? 'yes' : 'no'));
        $output->writeln('Cache used:      ' . ((bool) $s['cache_used'] ? 'yes' : 'no'));

        return self::SUCCESS;
    }
}

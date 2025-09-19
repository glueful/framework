<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Container;

use Glueful\Console\BaseCommand;
use Glueful\Container\Support\LazyInitializer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'di:lazy:status',
    description: 'Show LazyInitializer configured service IDs and optionally warm them'
)]
final class LazyStatusCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setDescription('Show LazyInitializer configured service IDs and optionally warm them')
            ->addOption('warm-background', null, InputOption::VALUE_NONE, 'Warm background services now')
            ->addOption('warm-request', null, InputOption::VALUE_NONE, 'Warm request-time services now')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format (table,json)', 'table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var LazyInitializer $li */
        $li = $this->getService(LazyInitializer::class);
        $stats = $li->stats();

        $format = (string) $input->getOption('format');
        $warmBg = (bool) $input->getOption('warm-background');
        $warmRq = (bool) $input->getOption('warm-request');

        if ($warmBg) {
            $this->info('Warming background services...');
            $li->initializeBackground();
        }
        if ($warmRq) {
            $this->info('Warming request-time services...');
            $li->activateRequestTime();
        }

        $bg = array_map(fn(string $id) => [$id], (array) ($stats['background'] ?? []));
        $rt = array_map(fn(string $id) => [$id], (array) ($stats['request_time'] ?? []));

        if ($format === 'json') {
            $this->line(json_encode([
                'background' => $stats['background'] ?? [],
                'request_time' => $stats['request_time'] ?? [],
            ], JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $this->info('LazyInitializer: Background IDs');
        $this->table(['service id'], $bg);
        $this->info('LazyInitializer: Request-Time IDs');
        $this->table(['service id'], $rt);

        return self::SUCCESS;
    }
}

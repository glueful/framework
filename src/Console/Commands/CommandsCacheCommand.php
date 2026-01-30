<?php

declare(strict_types=1);

namespace Glueful\Console\Commands;

use Glueful\Console\BaseCommand;
use Glueful\Container\Providers\ConsoleProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command Cache Management
 *
 * Manages the console command discovery cache for production optimization.
 */
#[AsCommand(
    name: 'commands:cache',
    description: 'Cache discovered console commands for production'
)]
class CommandsCacheCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('clear', 'c', InputOption::VALUE_NONE, 'Clear the command cache')
            ->addOption('status', 's', InputOption::VALUE_NONE, 'Show cache status')
            ->setHelp(<<<'HELP'
The <info>%command.name%</info> command manages the console command cache.

<info>Generate cache:</info>
  <comment>%command.full_name%</comment>

<info>Clear cache:</info>
  <comment>%command.full_name% --clear</comment>

<info>Show status:</info>
  <comment>%command.full_name% --status</comment>

In development, commands are auto-discovered on every run.
In production, the cache is auto-generated on first run for faster startup.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ((bool) $input->getOption('clear')) {
            return $this->clearCache($output);
        }

        if ((bool) $input->getOption('status')) {
            return $this->showStatus($output);
        }

        return $this->generateCache($output);
    }

    private function generateCache(OutputInterface $output): int
    {
        $output->writeln('<info>Generating command cache...</info>');

        // Clear existing cache first
        ConsoleProvider::clearCache();

        // Force cache regeneration by getting commands in "production" mode
        // We'll manually trigger the discovery and cache it
        $provider = new \ReflectionClass(ConsoleProvider::class);
        $discoverMethod = $provider->getMethod('discoverCommands');

        $instance = $provider->newInstanceWithoutConstructor();
        /** @var array<string> $commands */
        $commands = $discoverMethod->invoke($instance);

        // Write cache manually
        $getCachePath = $provider->getMethod('getCacheFilePath');
        /** @var string $cachePath */
        $cachePath = $getCachePath->invoke($instance);

        $writeCache = $provider->getMethod('writeCache');
        $writeCache->invoke($instance, $cachePath, $commands);

        $output->writeln(sprintf(
            '<info>Cached %d commands to:</info> %s',
            count($commands),
            $cachePath
        ));

        if ($output->isVerbose()) {
            $output->writeln('');
            $output->writeln('<comment>Commands cached:</comment>');
            foreach ($commands as $command) {
                $shortName = str_replace('Glueful\\Console\\Commands\\', '', $command);
                $output->writeln("  - {$shortName}");
            }
        }

        return self::SUCCESS;
    }

    private function clearCache(OutputInterface $output): int
    {
        $location = ConsoleProvider::getCacheLocation();

        if ($location === null) {
            $output->writeln('<comment>No command cache exists.</comment>');
            return self::SUCCESS;
        }

        if (ConsoleProvider::clearCache()) {
            $output->writeln('<info>Command cache cleared:</info> ' . $location);
            return self::SUCCESS;
        }

        $output->writeln('<error>Failed to clear command cache.</error>');
        return self::FAILURE;
    }

    private function showStatus(OutputInterface $output): int
    {
        $location = ConsoleProvider::getCacheLocation();
        $env = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? getenv('APP_ENV') ?: 'development';

        $output->writeln('<info>Command Cache Status</info>');
        $output->writeln('');
        $output->writeln(sprintf('  Environment:  <comment>%s</comment>', $env));
        $isProduction = in_array($env, ['production', 'prod'], true);
        $cacheMode = $isProduction ? 'enabled' : 'disabled (auto-discovery)';
        $output->writeln(sprintf('  Cache mode:   <comment>%s</comment>', $cacheMode));

        if ($location !== null) {
            $mtime = filemtime($location);
            $size = filesize($location);
            $commands = require $location;
            $count = is_array($commands) ? count($commands) : 0;

            $output->writeln('');
            $output->writeln('  <info>Cache file:</info>');
            $output->writeln(sprintf('    Path:       %s', $location));
            $output->writeln(sprintf('    Commands:   %d', $count));
            $output->writeln(sprintf('    Size:       %s', $this->formatBytes($size ?: 0)));
            $output->writeln(sprintf('    Generated:  %s', date('Y-m-d H:i:s', $mtime ?: 0)));
        } else {
            $output->writeln('');
            $output->writeln('  <comment>No cache file exists.</comment>');
        }

        return self::SUCCESS;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}

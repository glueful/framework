<?php

namespace Glueful\Console\Commands\Test;

use Glueful\Console\BaseCommand;
use Glueful\Development\Watcher\FileWatcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'test:watch',
    description: 'Run tests on file changes'
)]
class WatchCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setDescription('Run tests on file changes')
            ->setHelp('Watches files and re-runs the test command on changes.')
            ->addOption(
                'command',
                'c',
                InputOption::VALUE_REQUIRED,
                'Command to run',
                'composer test'
            )
            ->addOption(
                'interval',
                'i',
                InputOption::VALUE_REQUIRED,
                'Polling interval in milliseconds',
                '500'
            )
            ->addOption(
                'once',
                null,
                InputOption::VALUE_NONE,
                'Run once and exit'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $command = (string) $input->getOption('command');
        $interval = (int) $input->getOption('interval');
        $once = (bool) $input->getOption('once');

        $base = base_path($this->getContext());
        $watcher = new FileWatcher(['src', 'tests', 'config', 'routes'], $base);
        $watcher->setOutput($output);
        $watcher->setInterval($interval);
        $watcher->addExtensions(['php', 'xml', 'yml', 'yaml']);

        $this->info('Test watch started');
        $this->line('Command: ' . $command);
        $this->line('Watching: src, tests, config, routes');
        $this->line('');

        $this->runCommand($command);
        if ($once) {
            return self::SUCCESS;
        }

        $watcher->initialize();
        // @phpstan-ignore while.alwaysTrue
        while (true) {
            $changes = $watcher->checkOnce();
            if ($changes !== []) {
                $firstFile = array_key_first($changes);
                $this->line('');
                $this->info('Change detected: ' . $firstFile);
                $this->runCommand($command);
            }
            usleep($interval * 1000);
        }
    }

    private function runCommand(string $command): void
    {
        $process = Process::fromShellCommandline($command, base_path($this->getContext()));
        $process->setTimeout(null);
        $process->run(function (string $_type, string $buffer): void {
            echo $buffer;
        });
    }
}

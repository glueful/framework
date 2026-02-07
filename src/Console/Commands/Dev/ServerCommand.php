<?php

namespace Glueful\Console\Commands\Dev;

use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'dev:server',
    description: 'Start the development server with sensible defaults'
)]
class ServerCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setDescription('Start the development server with sensible defaults')
            ->setHelp('Wraps the serve command with watch enabled by default.')
            ->addOption('port', 'p', InputOption::VALUE_REQUIRED, 'Port to run the server on')
            ->addOption('host', 'H', InputOption::VALUE_REQUIRED, 'Host to bind the server to')
            ->addOption('queue', null, InputOption::VALUE_NONE, 'Start queue worker alongside server')
            ->addOption('open', 'o', InputOption::VALUE_NONE, 'Open the server URL in default browser')
            ->addOption('no-watch', null, InputOption::VALUE_NONE, 'Disable file watching');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $args = [PHP_BINARY, 'glueful', 'serve'];

        $noWatch = (bool) $input->getOption('no-watch');
        $queue = (bool) $input->getOption('queue');
        $open = (bool) $input->getOption('open');
        $host = $input->getOption('host');
        $port = $input->getOption('port');

        if (!$noWatch) {
            $args[] = '--watch';
        }
        if ($queue) {
            $args[] = '--queue';
        }
        if ($open) {
            $args[] = '--open';
        }
        if (is_string($host) && $host !== '') {
            $args[] = '--host=' . $host;
        }
        if (is_string($port) && $port !== '') {
            $args[] = '--port=' . $port;
        }

        $process = new Process($args, base_path($this->getContext()));
        $process->setTimeout(null);

        try {
            return $process->run(function (string $_type, string $buffer): void {
                echo $buffer;
            });
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }
}

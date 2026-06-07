<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Queue;

use Glueful\Queue\QueueWorker;
use Glueful\Queue\WorkerOptions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Queue Work Command
 *
 * The lean, single worker entrypoint for the framework. A thin adapter over
 * {@see QueueWorker} — it parses options into a {@see WorkerOptions} value
 * object and drives the worker's `daemon()` / `runOnce()` loop.
 *
 * Supervised worker fleets, autoscaling, process management and worker/job
 * metrics live in the `glueful/queue-ops` extension (`queue:supervise` /
 * `queue:autoscale`); they are intentionally absent here.
 *
 * @package Glueful\Console\Commands\Queue
 */
#[AsCommand(
    name: 'queue:work',
    description: 'Start a queue worker to process jobs'
)]
class WorkCommand extends BaseQueueCommand
{
    protected function configure(): void
    {
        $this->setDescription('Start a queue worker to process jobs')
             ->setHelp(
                 'Starts a single lean queue worker that drains jobs from the configured connection. ' .
                 'For supervised worker fleets and autoscaling, install the glueful/queue-ops extension ' .
                 '(queue:supervise / queue:autoscale).'
             )
             ->addOption(
                 'connection',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Queue connection to process (defaults to queue.default)'
             )
             ->addOption(
                 'queue',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Queue(s) to process (comma-separated)',
                 'default'
             )
             ->addOption(
                 'once',
                 null,
                 InputOption::VALUE_NONE,
                 'Process at most a single job, then exit'
             )
             ->addOption(
                 'sleep',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Seconds to sleep when no job is available',
                 '3'
             )
             ->addOption(
                 'memory',
                 'm',
                 InputOption::VALUE_REQUIRED,
                 'Memory limit in MB',
                 '128'
             )
             ->addOption(
                 'timeout',
                 't',
                 InputOption::VALUE_REQUIRED,
                 'Job timeout in seconds',
                 '60'
             )
             ->addOption(
                 'max-jobs',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Maximum jobs to process before exiting (0 = unlimited)',
                 '0'
             )
             ->addOption(
                 'max-runtime',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Maximum worker runtime in seconds before exiting (0 = unlimited)',
                 '0'
             )
             ->addOption(
                 'tries',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Maximum attempts for a job before it is marked failed',
                 '3'
             )
             ->addOption(
                 'stop-when-empty',
                 null,
                 InputOption::VALUE_NONE,
                 'Stop when the queue is empty'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $worker = $this->getService(QueueWorker::class);

            $connectionOption = $input->getOption('connection');
            $connection = ($connectionOption !== null && $connectionOption !== false && $connectionOption !== '')
                ? (string) $connectionOption
                : (string) config($this->getContext(), 'queue.default');

            $queues = $this->parseQueues($input->getOption('queue'));
            $options = $this->buildWorkerOptions($input);

            if ((bool) $input->getOption('once') === true) {
                $worker->runOnce($connection, $queues, $options);
                return self::SUCCESS;
            }

            return $worker->daemon($connection, $queues, $options);
        } catch (\Throwable $e) {
            $this->error('Error: ' . $e->getMessage());
            if ((bool) $input->getOption('verbose') === true) {
                $this->error($e->getTraceAsString());
            }
            return self::FAILURE;
        }
    }

    private function buildWorkerOptions(InputInterface $input): WorkerOptions
    {
        return new WorkerOptions(
            sleep: (int) $input->getOption('sleep'),
            memory: (int) $input->getOption('memory'),
            timeout: (int) $input->getOption('timeout'),
            maxJobs: (int) $input->getOption('max-jobs'),
            stopWhenEmpty: (bool) $input->getOption('stop-when-empty'),
            maxAttempts: (int) $input->getOption('tries'),
            maxRuntime: (int) $input->getOption('max-runtime'),
        );
    }
}

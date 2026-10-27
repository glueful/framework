<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Queue;

use Glueful\Scheduler\JobScheduler;
use Glueful\Lock\LockManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Job Scheduler Command
 *
 * Runs and inspects the jobs declared in config/schedule.php (and persisted scheduled_jobs rows):
 * `run` (due jobs once, `--force` for all), `work` (loop every `--interval` seconds), `list`,
 * `status` and `health`. Jobs are added, removed or switched off in config/schedule.php.
 *
 * @package Glueful\Console\Commands\Queue
 */
#[AsCommand(
    name: 'queue:scheduler',
    description: 'Advanced job scheduling and management system'
)]
class SchedulerCommand extends BaseCommand
{
    private JobScheduler $scheduler;

    protected function configure(): void
    {
        $this->setDescription('Advanced job scheduling and management system')
             ->setHelp('Runs and inspects scheduled jobs. Jobs are declared (and enabled or ' .
                      'disabled) in config/schedule.php.')
             ->addArgument(
                 'action',
                 InputArgument::OPTIONAL,
                 'Action to perform (run, work, list, status, health)',
                 'run'
             )
             ->addOption(
                 'interval',
                 'i',
                 InputOption::VALUE_REQUIRED,
                 'Worker mode check interval in seconds',
                 '60'
             )
             ->addOption(
                 'max-runs',
                 'm',
                 InputOption::VALUE_REQUIRED,
                 'Maximum number of scheduler runs (0 = unlimited)',
                 '0'
             )
             ->addOption(
                 'dry-run',
                 null,
                 InputOption::VALUE_NONE,
                 'Show what would be executed without running'
             )
             ->addOption(
                 'force',
                 'f',
                 InputOption::VALUE_NONE,
                 'Force job execution even if not due'
             )
             ->addOption(
                 'output-format',
                 'o',
                 InputOption::VALUE_REQUIRED,
                 'Output format (table, json, plain)',
                 'table'
             )
             ->addOption(
                 'watch',
                 'w',
                 InputOption::VALUE_NONE,
                 'Watch mode with real-time updates'
             )
             ->addOption(
                 'filter',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Filter jobs by status (enabled|disabled|running|failed)'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initializeServices();

        $action = $input->getArgument('action');

        try {
            return match ($action) {
                'run' => $this->executeRun($input),
                'work' => $this->executeWork($input),
                'list' => $this->executeList($input),
                'status' => $this->executeStatus($input),
                'health' => $this->executeHealth($input),
                default => $this->handleUnknownAction($action)
            };
        } catch (\Exception $e) {
            $this->io->error('Scheduler operation failed: ' . $e->getMessage());
            if ($output->isVerbose()) {
                $this->io->text($e->getTraceAsString());
            }
            return self::FAILURE;
        }
    }

    private function initializeServices(): void
    {
        $lockManager = $this->getService(LockManagerInterface::class);
        // The booted context: config/schedule.php is resolved from it and every handler receives it.
        $this->scheduler = new JobScheduler($lockManager, $this->getContext());
    }

    private function executeRun(InputInterface $input): int
    {
        $this->io->title('⏰ Running Scheduled Jobs');

        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');

        if ($dryRun === true) {
            $this->io->warning('DRY RUN MODE - Jobs will not be executed');
        }

        $this->io->text('Checking for due jobs...');

        if ($force === true) {
            $this->io->warning('FORCE MODE - All jobs will be executed regardless of schedule');
            if ($dryRun === true) {
                $this->showJobsToRun(true);
            } else {
                $this->scheduler->runAllJobs();
                $this->io->success('All jobs executed (forced)');
            }
        } else {
            if ($dryRun === true) {
                $this->showJobsToRun(false);
            } else {
                $this->scheduler->runDueJobs();
                $this->io->success('Scheduled tasks completed');
            }
        }

        return self::SUCCESS;
    }

    private function executeWork(InputInterface $input): int
    {
        $this->io->title('🔄 Starting Scheduler Worker');

        $interval = (int) $input->getOption('interval');
        $maxRuns = (int) $input->getOption('max-runs');
        $watch = (bool) $input->getOption('watch');

        $this->io->text("Worker interval: {$interval} seconds");
        if ($maxRuns > 0) {
            $this->io->text("Maximum runs: {$maxRuns}");
        } else {
            $this->io->text("Maximum runs: unlimited");
        }
        $this->io->text("Press Ctrl+C to stop the worker");
        $this->io->newLine();

        $runCount = 0;
        $startTime = time();

        // Set up signal handlers
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function () {
                $this->io->newLine();
                $this->io->text("Worker stopped by user");
                exit(0);
            });
        }

        try {
            while (true) {
                $runStart = time();

                if ($watch === true) {
                    $this->clearScreen();
                    $this->io->title('🔄 Scheduler Worker (Watch Mode)');
                    $this->displayWorkerStatus($runCount, $startTime, $interval);
                }

                $this->io->text(sprintf('[%s] Checking for due jobs...', date('H:i:s')));

                $this->scheduler->runDueJobs();
                $this->io->text('Scheduler run completed');

                $runCount++;

                // Check if we've reached the maximum runs
                if ($maxRuns > 0 && $runCount >= $maxRuns) {
                    $this->io->newLine();
                    $this->io->success("Maximum runs ({$maxRuns}) reached. Worker stopping.");
                    break;
                }

                // Calculate actual sleep time
                $executionTime = time() - $runStart;
                $sleepTime = max(0, $interval - $executionTime);

                if ($sleepTime > 0) {
                    if ($watch !== true) {
                        $this->io->text("Sleeping for {$sleepTime} seconds...");
                    }
                    sleep($sleepTime);
                }

                // Handle signals
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }
            }
        } catch (\Exception $e) {
            $this->io->error("Worker failed: " . $e->getMessage());
            return self::FAILURE;
        }

        $this->displayWorkerSummary($runCount, $startTime);
        return self::SUCCESS;
    }

    private function executeList(InputInterface $input): int
    {
        $this->io->title('📋 Scheduled Jobs List');

        $jobs = $this->scheduler->getJobs();
        $filter = $input->getOption('filter');
        $format = (string) $input->getOption('output-format');

        if ($filter !== false && $filter !== null) {
            $jobs = $this->filterJobs($jobs, (string) $filter);
            $this->io->text("Filtered by: {$filter}");
        }

        if (count($jobs) === 0) {
            $this->io->warning('No scheduled jobs found');
            return self::SUCCESS;
        }

        $this->displayJobs($jobs, $format);
        return self::SUCCESS;
    }

    private function executeStatus(InputInterface $input): int
    {
        $this->io->title('📊 Scheduler Status');

        $jobs = $this->scheduler->getJobs();
        $stats = $this->calculateSchedulerStats($jobs);

        $this->displaySchedulerStats($stats);

        // Show next upcoming jobs
        $upcomingJobs = $this->getUpcomingJobs($jobs, 5);
        if (count($upcomingJobs) > 0) {
            $this->io->section('⏳ Next Upcoming Jobs');
            $this->displayUpcomingJobs($upcomingJobs);
        }

        return self::SUCCESS;
    }

    private function executeHealth(InputInterface $input): int
    {
        $this->io->title('🏥 Scheduler Health Check');

        $health = $this->performHealthCheck();

        $this->displayHealthStatus($health);

        return $health['overall_status'] === 'healthy' ? self::SUCCESS : self::FAILURE;
    }

    private function showJobsToRun(bool $forceAll): void
    {
        $jobs = $this->scheduler->getJobs();

        if ($forceAll) {
            $jobsToRun = $jobs;
            $this->io->text('Would execute ALL jobs (forced):');
        } else {
            // Note: isDue method would need to be implemented in JobScheduler
            $jobsToRun = []; // Placeholder until isDue method is implemented
            $this->io->text('Would execute due jobs:');
        }

        if (count($jobsToRun) === 0) {
            $this->io->text('  No jobs to execute');
        } else {
            foreach ($jobsToRun as $job) {
                $this->io->text("  • {$job['name']} ({$job['schedule']})");
            }
        }
    }

    /**
     * @param array<string, mixed> $jobs
     */
    private function displayExecutedJobs(array $jobs): void
    {
        foreach ($jobs as $job) {
            $success = is_bool($job['success']) ? $job['success'] : false;
            $status = $success === true ? '✅' : '❌';
            $duration = isset($job['duration']) ? " ({$job['duration']}ms)" : '';
            $this->io->text("  {$status} {$job['name']}{$duration}");
        }
    }

    private function displayWorkerStatus(int $runCount, int $startTime, int $interval): void
    {
        $uptime = time() - $startTime;
        $this->io->text("Worker uptime: " . $this->formatDuration($uptime));
        $this->io->text("Completed runs: {$runCount}");
        $this->io->text("Check interval: {$interval}s");
        $this->io->newLine();
    }

    private function displayWorkerSummary(int $runCount, int $startTime): void
    {
        $this->io->newLine();
        $this->io->section('📊 Worker Summary');
        $totalTime = time() - $startTime;
        $this->io->text("Total runtime: " . $this->formatDuration($totalTime));
        $this->io->text("Total runs: {$runCount}");
        if ($runCount > 0) {
            $avgInterval = $totalTime / $runCount;
            $this->io->text("Average interval: " . round($avgInterval, 2) . "s");
        }
    }

    /**
     * @param list<array<string, mixed>> $jobs
     * @return array<int, array<string, mixed>>
     */
    private function filterJobs(array $jobs, string $filter): array
    {
        return array_filter($jobs, function ($job) use ($filter) {
            return match ($filter) {
                'enabled' => $job['enabled'] ?? true,
                'disabled' => ($job['enabled'] ?? true) !== true,
                'running' => $job['status'] === 'running',
                'failed' => $job['status'] === 'failed',
                default => true
            };
        });
    }

    /**
     * @param array<int, array<string, mixed>> $jobs
     */
    private function displayJobs(array $jobs, string $format): void
    {
        switch ($format) {
            case 'json':
                $this->io->text($this->jsonForDisplay($jobs, JSON_PRETTY_PRINT));
                break;
            case 'plain':
                foreach ($jobs as $job) {
                    $this->io->text("{$job['name']}: {$job['schedule']}");
                }
                break;
            default:
                $this->displayJobsTable($jobs);
                break;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $jobs
     */
    private function displayJobsTable(array $jobs): void
    {
        $rows = [['Name', 'Schedule', 'Status', 'Last Run', 'Next Run']];

        foreach ($jobs as $job) {
            $enabled = is_bool($job['enabled'] ?? null) ? $job['enabled'] : true;
            $status = $enabled === true ? '✅ Enabled' : '❌ Disabled';
            $lastRun = $job['last_run'] ?? 'Never';
            $nextRun = $job['next_run'] ?? 'Calculating...';

            $rows[] = [
                $job['name'],
                $job['schedule'],
                $status,
                $lastRun,
                $nextRun
            ];
        }

        $this->io->table($rows[0], array_slice($rows, 1));
    }

    /**
     * @param list<array<string, mixed>> $jobs
     * @return array<string, int>
     */
    private function calculateSchedulerStats(array $jobs): array
    {
        $total = count($jobs);
        $enabled = count(array_filter($jobs, fn($job) => $job['enabled'] ?? true));
        $disabled = $total - $enabled;
        $running = count(array_filter($jobs, fn($job) => ($job['status'] ?? '') === 'running'));

        return [
            'total' => $total,
            'enabled' => $enabled,
            'disabled' => $disabled,
            'running' => $running,
            'idle' => $enabled - $running
        ];
    }

    /**
     * @param array<string, int> $stats
     */
    private function displaySchedulerStats(array $stats): void
    {
        $rows = [
            ['Metric', 'Count'],
            ['Total Jobs', $stats['total']],
            ['Enabled Jobs', $stats['enabled']],
            ['Disabled Jobs', $stats['disabled']],
            ['Currently Running', $stats['running']],
            ['Idle Jobs', $stats['idle']]
        ];

        $this->io->table($rows[0], array_slice($rows, 1));
    }

    /**
     * @param list<array<string, mixed>> $jobs
     * @return list<array<string, mixed>>
     */
    private function getUpcomingJobs(array $jobs, int $limit): array
    {
        // This would calculate the next run times for all jobs
        // For now, return a subset
        return array_slice($jobs, 0, $limit);
    }

    /**
     * @param list<array<string, mixed>> $jobs
     */
    private function displayUpcomingJobs(array $jobs): void
    {
        $rows = [['Job', 'Next Run', 'Schedule']];

        foreach ($jobs as $job) {
            $nextRun = $job['next_run'] ?? 'Calculating...';
            $rows[] = [
                $job['name'],
                $nextRun,
                $job['schedule']
            ];
        }

        $this->io->table($rows[0], array_slice($rows, 1));
    }

    /**
     * @return array<string, mixed>
     */
    private function performHealthCheck(): array
    {
        $issues = [];
        $warnings = [];

        // Check if scheduler is properly configured
        $jobs = $this->scheduler->getJobs();
        if (count($jobs) === 0) {
            $warnings[] = 'No jobs are currently scheduled';
        }

        // Check for jobs with invalid cron expressions
        foreach ($jobs as $job) {
            if (!$this->validateCronExpression($job['schedule'])) {
                $issues[] = "Job '{$job['name']}' has invalid cron expression: {$job['schedule']}";
            }
        }

        // Check system resources
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            if ($load !== false && $load[0] > 5.0) {
                $warnings[] = 'High system load detected: ' . round($load[0], 2);
            }
        }

        $overallStatus = count($issues) === 0 ? 'healthy' : 'unhealthy';

        return [
            'overall_status' => $overallStatus,
            'issues' => $issues,
            'warnings' => $warnings,
            'total_jobs' => count($jobs),
            'enabled_jobs' => count(array_filter($jobs, fn($job) => $job['enabled'] ?? true))
        ];
    }

    /**
     * @param array<string, mixed> $health
     */
    private function displayHealthStatus(array $health): void
    {
        $status = $health['overall_status'] === 'healthy' ? '✅ Healthy' : '❌ Unhealthy';
        $this->io->text("Overall Status: {$status}");
        $this->io->text("Total Jobs: {$health['total_jobs']}");
        $this->io->text("Enabled Jobs: {$health['enabled_jobs']}");
        $this->io->newLine();

        $issues = is_array($health['issues']) ? $health['issues'] : [];
        $warnings = is_array($health['warnings']) ? $health['warnings'] : [];

        if (count($issues) > 0) {
            $this->io->section('🚨 Critical Issues');
            foreach ($issues as $issue) {
                $this->io->error($issue);
            }
        }

        if (count($warnings) > 0) {
            $this->io->section('⚠️ Warnings');
            foreach ($warnings as $warning) {
                $this->io->warning($warning);
            }
        }

        if (count($issues) === 0 && count($warnings) === 0) {
            $this->io->success('No issues detected');
        }
    }

    private function validateCronExpression(string $cron): bool
    {
        // Basic cron validation - would use a proper cron parser in production
        $parts = explode(' ', trim($cron));
        return count($parts) === 5;
    }

    private function clearScreen(): void
    {
        $this->io->write("\033[2J\033[;H");
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        } elseif ($seconds < 3600) {
            return floor($seconds / 60) . 'm ' . ($seconds % 60) . 's';
        } else {
            $hours = floor($seconds / 3600);
            $minutes = floor(($seconds % 3600) / 60);
            return $hours . 'h ' . $minutes . 'm';
        }
    }

    private function handleUnknownAction(string $action): int
    {
        $this->io->error("Unknown action: {$action}");
        $this->io->text('Available actions: run, work, list, status, health');
        return self::FAILURE;
    }
}

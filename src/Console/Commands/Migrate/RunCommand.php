<?php

namespace Glueful\Console\Commands\Migrate;

use Glueful\Console\BaseCommand;
use Glueful\Database\Migrations\MigrationManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Migrate Run Command
 * Executes pending database migrations with enhanced Symfony Console features:
 * - Proper argument validation
 * - Interactive confirmations
 * - Progress bars for multiple migrations
 * - Enhanced output formatting
 * @package Glueful\Console\Commands\Migrate
 */
#[AsCommand(
    name: 'migrate:run',
    description: 'Run pending database migrations'
)]
class RunCommand extends BaseCommand
{
    private ?MigrationManager $migrationManager = null;

    /**
     * Resolved on FIRST USE, never at construction: the console registers every command at
     * boot, and MigrationManager's constructor opens a database connection and runs
     * `ensureVersionTable()` DDL. Eager resolution therefore made EVERY console invocation
     * (`glueful list` included) require a reachable, writable database — which is precisely
     * the state a first-run provisioning command exists to repair. (Surfaced by a clean-machine
     * first-run install audit, 2026-08-16.)
     */
    private function migrations(): MigrationManager
    {
        return $this->migrationManager ??= $this->getService(MigrationManager::class);
    }


    protected function configure(): void
    {
        $this->setDescription('Run pending database migrations')
             ->setHelp('This command executes all pending database migrations in sequence.')
             ->addOption(
                 'force',
                 'f',
                 InputOption::VALUE_NONE,
                 'Force execution in production environment'
             )
             ->addOption(
                 'dry-run',
                 null,
                 InputOption::VALUE_NONE,
                 'Show what would be executed without running'
             )
             ->addOption(
                 'pretend',
                 null,
                 InputOption::VALUE_NONE,
                 'Alias for --dry-run'
             )
             ->addOption(
                 'batch',
                 'b',
                 InputOption::VALUE_REQUIRED,
                 'Specify batch number for grouping migrations'
             )
             ->addOption(
                 'path',
                 'p',
                 InputOption::VALUE_REQUIRED,
                 'Run migrations from custom directory'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $force = $input->getOption('force');
        $dryRun = (bool) $input->getOption('dry-run') || (bool) $input->getOption('pretend');

        // Production safety check
        if (!(bool) $force && !$this->confirmProduction('run database migrations')) {
            return self::FAILURE;
        }

        try {
            // Rows recorded under a lane's previous source names are adopted first (see
            // MigrationDescriptor::$previousSources): a database with nothing to run still gets
            // its ledger brought up to date. Never in a dry run.
            if (!$dryRun) {
                $this->migrations()->adoptPreviousSources();
            }

            // Get migration status efficiently (single query)
            $status = $this->migrations()->getMigrationStatus();
            $pendingMigrations = $status['pending'];

            if (count($pendingMigrations) === 0) {
                $this->info('No pending migrations found.');
                return self::SUCCESS;
            }

            $this->info(sprintf('Found %d pending migration(s)', count($pendingMigrations)));
            $this->line('');

            // Display pending migrations table
            $this->listPendingMigrations($pendingMigrations);
            $this->line('');

            if ($dryRun) {
                $this->warning('DRY RUN MODE - No actual migrations will be executed');
                return self::SUCCESS;
            }

            // Confirm execution if not forced
            if (
                !(bool) $force && !$this->confirm(
                    sprintf('Do you want to run %d migration(s)?', count($pendingMigrations)),
                    false
                )
            ) {
                $this->info('Migration cancelled.');
                return self::SUCCESS;
            }

            // Execute all migrations in a single batch. Custody (schema policy spec B4): ONE
            // source snapshot, locked, with a FRESH pending read INSIDE the lock — the pre-lock
            // status read above is display-only, and a source enabled after the snapshot was not
            // locked and therefore must not join this run (its enable executor migrates it).
            $this->info('Executing migrations...');
            $this->line('');

            $manager = $this->migrations();
            $snapshot = $manager->globalSources();
            /** @var \Glueful\Extensions\Schema\MigrationLockInterface $lock */
            $lock = $this->getService(\Glueful\Extensions\Schema\MigrationLockInterface::class);
            $handle = $lock->acquireAll($snapshot);
            try {
                $fresh = array_map(
                    static fn(array $row): string => $row['file'],
                    $manager->pendingForSources($snapshot)
                );
                $result = $fresh === []
                    ? ['applied' => [], 'failed' => []]
                    : $manager->migrate($fresh);
            } finally {
                $handle->release();
            }

            // Display execution results
            $this->displayExecutionResults($result, $pendingMigrations);

            if (isset($result['failed']) && count($result['failed']) > 0) {
                throw new \Exception('Some migrations failed: ' . implode(', ', $result['failed']));
            }

            $this->line('');
            $this->success('All migrations executed successfully!');
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Migration execution failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * @param array<int, string> $migrations
     */
    private function listPendingMigrations(array $migrations): void
    {
        $headers = ['Migration', 'Status'];
        $rows = [];

        foreach ($migrations as $migration) {
            $rows[] = [
                basename($migration),
                '⏳ Pending'
            ];
        }

        $this->table($headers, $rows);
    }

    /**
     * @param array<string, array<int, string>> $result
     * @param array<int, string> $pendingMigrations
     */
    private function displayExecutionResults(array $result, array $pendingMigrations): void
    {
        $headers = ['Migration', 'Status'];
        $rows = [];

        foreach ($pendingMigrations as $migration) {
            $filename = basename($migration);

            if (in_array($filename, $result['applied'], true)) {
                $rows[] = [
                    $filename,
                    '✅ Completed'
                ];
            } elseif (in_array($filename, $result['failed'], true)) {
                $rows[] = [
                    $filename,
                    '❌ Failed'
                ];
            } else {
                $rows[] = [
                    $filename,
                    '⏸️ Skipped'
                ];
            }
        }

        $this->table($headers, $rows);

        // Display summary
        $appliedCount = count($result['applied']);
        $failedCount = count($result['failed']);

        if ($appliedCount > 0) {
            $this->line(sprintf('<info>✅ Successfully applied: %d migration(s)</info>', $appliedCount));
        }
        if ($failedCount > 0) {
            $this->line(sprintf('<error>❌ Failed: %d migration(s)</error>', $failedCount));
        }
    }
}

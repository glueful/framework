<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Encryption;

use Glueful\Console\BaseCommand;
use Glueful\Database\Connection;
use Glueful\Encryption\EncryptionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'encryption:rotate',
    description: 'Re-encrypt database columns with the current key'
)]
class RotateCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('table', 't', InputOption::VALUE_REQUIRED, 'Table name to process')
            ->addOption('columns', 'c', InputOption::VALUE_REQUIRED, 'Comma-separated column names')
            ->addOption('primary-key', 'p', InputOption::VALUE_OPTIONAL, 'Primary key column', 'id')
            ->addOption('batch-size', 'b', InputOption::VALUE_OPTIONAL, 'Batch size', '100')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be done without changes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $table = (string) $input->getOption('table');
        $columnsInput = (string) $input->getOption('columns');
        $primaryKey = (string) $input->getOption('primary-key');
        $batchSize = (int) $input->getOption('batch-size');
        $dryRun = (bool) $input->getOption('dry-run');

        if ($table === '' || $columnsInput === '') {
            $output->writeln('<error>Both --table and --columns are required.</error>');
            $output->writeln('');
            $output->writeln('Usage:');
            $output->writeln('  php glueful encryption:rotate --table=users --columns=ssn,api_secret');
            return self::FAILURE;
        }

        $columns = array_map('trim', explode(',', $columnsInput));

        try {
            $service = new EncryptionService($this->getContext());
        } catch (\Throwable $e) {
            $output->writeln('<error>Failed to initialize encryption service:</error>');
            $output->writeln('  ' . $e->getMessage());
            return self::FAILURE;
        }

        try {
            $db = container($this->getContext())->get(Connection::class);
        } catch (\Throwable $e) {
            $output->writeln('<error>Failed to connect to database:</error>');
            $output->writeln('  ' . $e->getMessage());
            return self::FAILURE;
        }

        $output->writeln('<info>Encryption Key Rotation</info>');
        $output->writeln("Table: <comment>{$table}</comment>");
        $output->writeln('Columns: <comment>' . implode(', ', $columns) . '</comment>');
        $output->writeln("Batch size: <comment>{$batchSize}</comment>");
        if ($dryRun) {
            $output->writeln('<comment>DRY RUN - No changes will be made</comment>');
        }
        $output->writeln('');

        // Count total rows
        $countResult = $db->query("SELECT COUNT(*) as total FROM {$table}");
        $total = (int) ($countResult[0]['total'] ?? 0);

        if ($total === 0) {
            $output->writeln('<comment>No rows found in table.</comment>');
            return self::SUCCESS;
        }

        $output->writeln("Processing <info>{$total}</info> rows...");
        $output->writeln('');

        $processed = 0;
        $rotated = 0;
        $skipped = 0;
        $errors = 0;
        $offset = 0;

        while ($offset < $total) {
            $selectColumns = array_merge([$primaryKey], $columns);
            $selectSql = sprintf(
                'SELECT %s FROM %s LIMIT %d OFFSET %d',
                implode(', ', $selectColumns),
                $table,
                $batchSize,
                $offset
            );

            $rows = $db->query($selectSql);

            foreach ($rows as $row) {
                $updates = [];
                $rowRotated = false;
                $id = $row[$primaryKey];

                foreach ($columns as $column) {
                    $value = $row[$column] ?? null;

                    if ($value === null || $value === '') {
                        continue;
                    }

                    if (!$service->isEncrypted($value)) {
                        $skipped++;
                        continue;
                    }

                    try {
                        // Decrypt with any valid key (current or previous)
                        $aad = "{$table}.{$column}";
                        $decrypted = $service->decrypt($value, $aad);

                        // Re-encrypt with current key
                        $reEncrypted = $service->encrypt($decrypted, $aad);

                        // Only update if the ciphertext changed (different key ID)
                        if ($reEncrypted !== $value) {
                            $updates[$column] = $reEncrypted;
                            $rowRotated = true;
                        }
                    } catch (\Throwable $e) {
                        $output->writeln(sprintf(
                            '  <error>Error</error> row %s, column %s: %s',
                            $id,
                            $column,
                            $e->getMessage()
                        ));
                        $errors++;
                    }
                }

                if ($rowRotated && $updates !== []) {
                    if (!$dryRun) {
                        $setClauses = [];
                        $params = [];
                        foreach ($updates as $col => $val) {
                            $setClauses[] = "{$col} = ?";
                            $params[] = $val;
                        }
                        $params[] = $id;

                        $updateSql = sprintf(
                            'UPDATE %s SET %s WHERE %s = ?',
                            $table,
                            implode(', ', $setClauses),
                            $primaryKey
                        );

                        $db->query($updateSql, $params);
                    }
                    $rotated++;
                }

                $processed++;
            }

            $offset += $batchSize;

            // Progress update
            $percent = min(100, (int) (($processed / $total) * 100));
            $output->write("\r  Progress: {$percent}% ({$processed}/{$total})");
        }

        $output->writeln('');
        $output->writeln('');
        $output->writeln('<info>Summary:</info>');
        $output->writeln("  Rows processed: <comment>{$processed}</comment>");
        $output->writeln("  Rows rotated: <comment>{$rotated}</comment>");
        $output->writeln("  Values skipped (not encrypted): <comment>{$skipped}</comment>");

        if ($errors > 0) {
            $output->writeln("  Errors: <error>{$errors}</error>");
        }

        if ($dryRun) {
            $output->writeln('');
            $output->writeln('<comment>This was a dry run. Run without --dry-run to apply changes.</comment>');
        }

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}

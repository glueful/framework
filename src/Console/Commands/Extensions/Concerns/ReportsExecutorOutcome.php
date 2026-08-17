<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Extensions\Concerns;

use Glueful\Extensions\Schema\ExtensionOperation;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Shared executor-outcome rendering for extensions:enable/disable: the operation record is the
 * truth (id, status, failed migration, error), printed identically on both commands.
 */
trait ReportsExecutorOutcome
{
    private function reportOperation(OutputInterface $output, ExtensionOperation $operation, string $verb): int
    {
        switch ($operation->status) {
            case ExtensionOperation::STATUS_SUCCEEDED:
                $label = $operation->step === 'dry-run' ? "Dry run: would {$verb}" : ucfirst($verb) . 'd';
                $output->writeln("<info>{$label} {$operation->package}.</info>");
                return 0;
            case ExtensionOperation::STATUS_CACHE_STALE:
                $output->writeln("<comment>{$operation->error}</comment>");
                return 0;
            default:
                $output->writeln(
                    "<error>Operation #{$operation->id} {$operation->status}"
                    . ($operation->failedMigration !== null ? " at {$operation->failedMigration}" : '')
                    . ($operation->error !== null ? ": {$operation->error}" : '')
                    . '</error>'
                );
                return 1;
        }
    }
}

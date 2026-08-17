<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Migrate;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\Schema\ReceiptNormalizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Rewrites legacy-alias migration receipts to their descriptor identity (schema policy spec B1).
 * Checksum-verified, lock-serialized, ambiguity-refusing; --dry-run reports without writing.
 */
#[AsCommand(
    name: 'migrate:normalize-receipts',
    description: 'Rewrite legacy-alias migration receipts to their manifest descriptor identity'
)]
class NormalizeReceiptsCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report the rewrites without writing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Resolved on first use, never at construction (lazy-ledger rules).
        /** @var ReceiptNormalizer $normalizer */
        $normalizer = $this->getService(ReceiptNormalizer::class);
        $report = $normalizer->normalize(dryRun: (bool) $input->getOption('dry-run'));

        if ($report->rewritten === [] && $report->refused === []) {
            $this->info('Nothing to normalize.');
            return self::SUCCESS;
        }
        if ($report->rewritten !== []) {
            $this->table(
                ['Source', 'Alias', 'Migration'],
                array_map(
                    static fn(array $r): array => [$r['source'], $r['alias'], $r['migration']],
                    $report->rewritten
                )
            );
        }
        foreach ($report->refused as $refusal) {
            $this->warning("refused {$refusal['alias']}: {$refusal['reason']}");
        }
        return $report->refused === [] ? self::SUCCESS : self::FAILURE;
    }
}

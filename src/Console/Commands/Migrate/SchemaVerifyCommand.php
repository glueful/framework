<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Migrate;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\Schema\AdoptionService;
use Glueful\Extensions\Schema\AdoptionState;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Classifies every descriptor's adoption state (ready / adoptable / divergent, schema policy
 * spec B7) and, with --adopt <source>, writes verifier-approved receipts for existing installs.
 */
#[AsCommand(
    name: 'migrate:verify',
    description: 'Classify descriptor schema state (ready/adoptable/divergent); --adopt writes verified receipts'
)]
class SchemaVerifyCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('adopt', null, InputOption::VALUE_REQUIRED, 'Adopt the named source (must be adoptable)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Resolved on first use, never at construction (lazy-ledger rules).
        /** @var AdoptionService $service */
        $service = $this->getService(AdoptionService::class);

        $adopt = $input->getOption('adopt');
        if (is_string($adopt) && $adopt !== '') {
            try {
                $report = $service->adopt($adopt);
            } catch (\RuntimeException $e) {
                $this->error($e->getMessage());
                return self::FAILURE;
            }
            $this->success(sprintf('Adopted %d receipt(s) for %s.', count($report->adopted), $report->source));
            return self::SUCCESS;
        }

        $classified = $service->classify();
        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode(array_map(
                static fn(array $c): array => ['state' => $c['state']->value, 'reasons' => $c['reasons']],
                $classified
            ), JSON_PRETTY_PRINT));
        } else {
            $this->table(
                ['Source', 'State', 'Reasons'],
                array_map(
                    static fn(string $source, array $c): array => [
                        $source,
                        $c['state']->value,
                        implode('; ', $c['reasons']),
                    ],
                    array_keys($classified),
                    $classified
                )
            );
        }
        $divergent = array_filter(
            $classified,
            static fn(array $c): bool => $c['state'] === AdoptionState::Divergent
        );
        return $divergent === [] ? self::SUCCESS : self::FAILURE;
    }
}

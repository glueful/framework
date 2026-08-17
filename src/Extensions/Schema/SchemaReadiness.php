<?php

declare(strict_types=1);

namespace Glueful\Extensions\Schema;

use Glueful\Database\Connection;

/**
 * Checksum-driven readiness classification (schema policy spec B3). Ledger-driven, never
 * hasTable probes of the descriptor's own tables: schema-ready means every current migration
 * file has a receipt under the descriptor identity with the exact current SHA-256. A missing
 * ledger means Pending — readiness reads perform zero DDL (only migrate operations create the
 * ledger). Alias receipts are Divergent until receipt normalization runs.
 */
final class SchemaReadiness
{
    private const LEDGER = 'migrations';

    public function __construct(
        private readonly Connection $db,
        private readonly DescriptorInventory $inventory,
        private readonly bool $aliasesNormalized = false,
    ) {
    }

    public function classify(MigrationDescriptor $descriptor): ReadinessState
    {
        return $this->evaluate($descriptor)['state'];
    }

    /** @return list<string> */
    public function explain(MigrationDescriptor $descriptor): array
    {
        return $this->evaluate($descriptor)['reasons'];
    }

    /**
     * Classify every descriptor of a package; refuses undeclared packages (fail closed).
     *
     * @return array<string, array{state: ReadinessState, reasons: list<string>}> keyed by source
     */
    public function forPackage(string $package): array
    {
        if (!$this->inventory->isDeclared($package)) {
            throw UndeclaredSchemaException::for($package);
        }
        $out = [];
        foreach ($this->inventory->forPackage($package) as $descriptor) {
            $out[$descriptor->source()] = $this->evaluate($descriptor);
        }
        return $out;
    }

    /** @return array{state: ReadinessState, reasons: list<string>} */
    private function evaluate(MigrationDescriptor $descriptor): array
    {
        $files = $this->inventory->filesOf($descriptor);
        $source = $descriptor->source();
        $reasons = [];

        if (!$this->ledgerExists()) {
            return ['state' => ReadinessState::Pending, 'reasons' => ['migration ledger not created yet']];
        }

        $receipts = $this->receiptsFor($source);
        $aliasReceipts = [];
        foreach ($descriptor->legacyAliases as $alias) {
            foreach ($this->receiptsFor($alias) as $basename => $checksum) {
                $aliasReceipts[$basename] = $checksum;
            }
        }
        if ($aliasReceipts !== [] && !$this->aliasesNormalized) {
            return ['state' => ReadinessState::Divergent, 'reasons' => [
                "receipts exist under a legacy alias of {$source}; run migrate:normalize-receipts",
            ]];
        }
        if ($this->aliasesNormalized) {
            $receipts += $aliasReceipts; // descriptor rows win over alias rows
        }

        $currentBasenames = [];
        $missing = 0;
        foreach ($files as $file) {
            $basename = basename($file);
            $currentBasenames[$basename] = true;
            if (!array_key_exists($basename, $receipts)) {
                $missing++;
                continue;
            }
            if ($receipts[$basename] !== hash_file('sha256', $file)) {
                $reasons[] = "checksum mismatch for {$basename}: the shipped file differs from the applied one";
            }
        }
        foreach (array_keys($receipts) as $basename) {
            if (!isset($currentBasenames[$basename])) {
                $reasons[] = "receipt {$basename} has no shipped migration file (removed or renamed)";
            }
        }

        if ($reasons !== []) {
            return ['state' => ReadinessState::Divergent, 'reasons' => $reasons];
        }
        if ($missing > 0) {
            return ['state' => ReadinessState::Pending, 'reasons' => ["{$missing} migration(s) pending"]];
        }
        return ['state' => ReadinessState::Ready, 'reasons' => []];
    }

    private function ledgerExists(): bool
    {
        return $this->db->getSchemaBuilder()->hasTable(self::LEDGER);
    }

    /** @return array<string, string> basename => checksum */
    private function receiptsFor(string $source): array
    {
        $rows = $this->db->table(self::LEDGER)
            ->select(['migration', 'checksum'])
            ->where('source', $source)
            ->get();
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['migration']] = (string) ($row['checksum'] ?? '');
        }
        return $out;
    }
}

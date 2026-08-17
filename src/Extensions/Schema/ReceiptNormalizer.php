<?php

declare(strict_types=1);

namespace Glueful\Extensions\Schema;

use Glueful\Database\Connection;

/**
 * Rewrites legacy-alias ledger receipts to their descriptor identity (schema policy spec B1).
 * Every rewrite is checksum-verified against the exact shipped file; duplicates reconcile only
 * when checksums agree (the alias row is deleted), and any ambiguity refuses rather than guesses.
 * Runs under the migration lock over all affected target sources; a missing ledger yields an
 * empty report without querying — only migrate operations create the ledger.
 */
final class ReceiptNormalizer
{
    private const LEDGER = 'migrations';

    public function __construct(
        private readonly Connection $db,
        private readonly DescriptorInventory $inventory,
        private readonly MigrationLockInterface $lock,
    ) {
    }

    public function normalize(bool $dryRun = false): NormalizationReport
    {
        if (!$this->db->getSchemaBuilder()->hasTable(self::LEDGER)) {
            return new NormalizationReport([], []);
        }
        $aliasIndex = $this->inventory->aliasIndex();
        if ($aliasIndex === []) {
            return new NormalizationReport([], []);
        }

        $targets = array_values(array_unique(array_values($aliasIndex)));
        sort($targets, SORT_STRING);
        $handle = $this->lock->acquireAll($targets);
        try {
            return $this->run($aliasIndex, $dryRun);
        } finally {
            $handle->release();
        }
    }

    /**
     * @param array<string, string> $aliasIndex alias => target source
     */
    private function run(array $aliasIndex, bool $dryRun): NormalizationReport
    {
        $rewritten = [];
        $refused = [];
        $pdo = $this->db->getPDO();
        if (!$dryRun) {
            $pdo->beginTransaction();
        }
        try {
            foreach ($aliasIndex as $alias => $target) {
                $descriptor = $this->inventory->bySource($target);
                if ($descriptor === null) {
                    $refused[] = ['alias' => $alias, 'reason' => "target source {$target} has no descriptor"];
                    continue;
                }
                $filesByBasename = [];
                foreach ($this->inventory->filesOf($descriptor) as $file) {
                    $filesByBasename[basename($file)] = $file;
                }
                $rows = $this->db->table(self::LEDGER)
                    ->select(['id', 'migration', 'checksum'])
                    ->where('source', $alias)
                    ->get();
                foreach ($rows as $row) {
                    $basename = (string) $row['migration'];
                    $file = $filesByBasename[$basename] ?? null;
                    if ($file === null) {
                        $refused[] = [
                            'alias' => $alias,
                            'reason' => "{$basename}: no shipped file under {$target} to verify against",
                        ];
                        continue;
                    }
                    if ((string) ($row['checksum'] ?? '') !== hash_file('sha256', $file)) {
                        $refused[] = [
                            'alias' => $alias,
                            'reason' => "{$basename}: stored checksum does not match the shipped file",
                        ];
                        continue;
                    }
                    $duplicate = $this->db->table(self::LEDGER)
                        ->select(['checksum'])
                        ->where('source', $target)
                        ->where('migration', $basename)
                        ->first();
                    if ($duplicate !== null) {
                        if ((string) ($duplicate['checksum'] ?? '') === (string) ($row['checksum'] ?? '')) {
                            if (!$dryRun) {
                                $this->db->table(self::LEDGER)->where('id', $row['id'])->delete();
                            }
                            $rewritten[] = ['source' => $target, 'alias' => $alias, 'migration' => $basename];
                            continue;
                        }
                        $refused[] = [
                            'alias' => $alias,
                            'reason' => "{$basename}: a {$target} receipt already exists with a DIFFERENT checksum",
                        ];
                        continue;
                    }
                    if (!$dryRun) {
                        $this->db->table(self::LEDGER)->where('id', $row['id'])->update(['source' => $target]);
                    }
                    $rewritten[] = ['source' => $target, 'alias' => $alias, 'migration' => $basename];
                }
            }
            if (!$dryRun) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if (!$dryRun && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return new NormalizationReport($rewritten, $refused);
    }
}

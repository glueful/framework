<?php

declare(strict_types=1);

namespace Glueful\Extensions\Schema;

use Glueful\Database\Connection;

/**
 * Verifier-gated receipt adoption for existing installs (schema policy spec B7). A generic
 * command must never infer a migration ran merely because tables exist: Adoptable requires the
 * descriptor's manifest-declared structural verifier to PASS for every missing receipt during
 * classification, and adopt() re-verifies under the lock before atomically writing all receipts
 * with each shipped file's exact SHA-256. Nothing is ever dropped; existing rows are never
 * touched; a missing ledger refuses — only migrate operations create it.
 */
final class AdoptionService
{
    private const LEDGER = 'migrations';

    public function __construct(
        private readonly Connection $db,
        private readonly DescriptorInventory $inventory,
        private readonly SchemaReadiness $readiness,
        private readonly MigrationLockInterface $lock,
    ) {
    }

    /** @return array<string, array{state: AdoptionState, reasons: list<string>}> keyed by source */
    public function classify(): array
    {
        $out = [];
        foreach ($this->inventory->all() as $descriptor) {
            $out[$descriptor->source()] = $this->classifyDescriptor($descriptor);
        }
        ksort($out);
        return $out;
    }

    public function adopt(string $source): AdoptionReport
    {
        $descriptor = $this->inventory->bySource($source);
        if ($descriptor === null) {
            throw new \RuntimeException("No descriptor declares source '{$source}'.");
        }
        if (!$this->ledgerExists()) {
            throw new \RuntimeException(
                'The migration ledger does not exist yet — run a migrate operation first '
                . '(adoption only writes receipts, it never creates the ledger).'
            );
        }
        $classified = $this->classifyDescriptor($descriptor);
        if ($classified['state'] !== AdoptionState::Adoptable) {
            throw new \RuntimeException(
                "{$source} is {$classified['state']->value}, not adoptable: "
                . implode('; ', $classified['reasons'])
            );
        }

        $handle = $this->lock->acquireAll([$source]);
        try {
            $verifier = $this->verifierFor($descriptor);
            if ($verifier === null) {
                throw new \RuntimeException("{$source} lost its verifier between classify and adopt.");
            }
            $missing = $this->missingBasenames($descriptor);
            $pdo = $this->db->getPDO();
            $pdo->beginTransaction();
            try {
                $adopted = [];
                $filesByBasename = [];
                foreach ($this->inventory->filesOf($descriptor) as $file) {
                    $filesByBasename[basename($file)] = $file;
                }
                foreach ($missing as $basename) {
                    // Re-verify under the lock: a passing classification is not custody.
                    if (!$verifier->verify($this->db, $basename)) {
                        throw new \RuntimeException(
                            "Structural verifier refused {$basename} during adopt; nothing was written."
                        );
                    }
                    $this->db->table(self::LEDGER)->insert([
                        'migration' => $basename,
                        'batch' => 0,
                        'checksum' => hash_file('sha256', $filesByBasename[$basename]),
                        'description' => 'adopted receipt (verifier-approved)',
                        'extension' => null,
                        'source' => $source,
                    ]);
                    $adopted[] = $basename;
                }
                $pdo->commit();
                return new AdoptionReport($source, $adopted);
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        } finally {
            $handle->release();
        }
    }

    /** @return array{state: AdoptionState, reasons: list<string>} */
    private function classifyDescriptor(MigrationDescriptor $descriptor): array
    {
        $readiness = $this->readiness->classify($descriptor);
        if ($readiness === ReadinessState::Ready) {
            return ['state' => AdoptionState::Ready, 'reasons' => []];
        }
        if ($readiness === ReadinessState::Divergent) {
            return ['state' => AdoptionState::Divergent, 'reasons' => $this->readiness->explain($descriptor)];
        }

        // Pending: adoption depends on the verifier PASSING every missing basename (effects
        // present without receipts = adoptable, e.g. a lost ledger). When effects are ABSENT
        // (or unverifiable), the receipt count decides what that means: an UNTOUCHED source
        // (zero receipts) is simply not migrated yet — the healthy state of every disabled
        // extension's schema — while a partially receipted source with absent effects is a
        // genuine conflict. Divergence means conflict; an untouched source has none.
        $untouched = !$this->hasAnyReceipt($descriptor);

        $source = $descriptor->source();
        $verifier = null;
        try {
            $verifier = $this->verifierFor($descriptor);
        } catch (\RuntimeException $e) {
            return $this->notMigratedOrDivergent($untouched, [$e->getMessage()]);
        }
        if ($verifier === null) {
            return $this->notMigratedOrDivergent($untouched, ["no structural verifier registered for {$source}"]);
        }
        $reasons = [];
        foreach ($this->missingBasenames($descriptor) as $basename) {
            if (!$verifier->verify($this->db, $basename)) {
                $reasons[] = "structural verifier refused {$basename}";
            }
        }
        if ($reasons !== []) {
            return $this->notMigratedOrDivergent($untouched, $reasons);
        }
        return ['state' => AdoptionState::Adoptable, 'reasons' => []];
    }

    /**
     * Absent (or unverifiable) effects resolve by receipt count: an untouched source is
     * simply not migrated yet, a partially receipted one is a conflict.
     *
     * @param list<string> $reasons
     * @return array{state: AdoptionState, reasons: list<string>}
     */
    private function notMigratedOrDivergent(bool $untouched, array $reasons): array
    {
        if ($untouched) {
            return ['state' => AdoptionState::Pending, 'reasons' => [
                'not migrated yet — nothing applied and nothing claimed (applies on enable / migrate)',
            ]];
        }
        return ['state' => AdoptionState::Divergent, 'reasons' => $reasons];
    }

    /** Whether ANY receipt exists under the descriptor's source. */
    private function hasAnyReceipt(MigrationDescriptor $descriptor): bool
    {
        if (!$this->ledgerExists()) {
            return false;
        }
        $row = $this->db->table(self::LEDGER)
            ->select(['migration'])
            ->where('source', $descriptor->source())
            ->first();
        return $row !== null;
    }

    /**
     * Instantiate the manifest-declared verifier. Nonconformance never throws out of classify —
     * it becomes a Divergent reason via the RuntimeException message.
     */
    private function verifierFor(MigrationDescriptor $descriptor): ?StructuralVerifierInterface
    {
        $class = $descriptor->verifierClass;
        if ($class === null) {
            return null;
        }
        if (!class_exists($class)) {
            throw new \RuntimeException("declared verifier class {$class} does not exist");
        }
        if (!is_subclass_of($class, StructuralVerifierInterface::class)) {
            throw new \RuntimeException("declared verifier {$class} does not implement StructuralVerifierInterface");
        }
        $constructor = (new \ReflectionClass($class))->getConstructor();
        if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
            throw new \RuntimeException(
                "declared verifier {$class} requires constructor arguments; the manifest contract "
                . 'requires a public zero-required-argument constructor'
            );
        }
        /** @var StructuralVerifierInterface $verifier */
        $verifier = new $class();
        if ($verifier->source() !== $descriptor->source()) {
            throw new \RuntimeException(
                "declared verifier {$class} reports source '{$verifier->source()}' but the descriptor "
                . "is '{$descriptor->source()}'"
            );
        }
        return $verifier;
    }

    /** @return list<string> current files without a receipt under the descriptor source */
    private function missingBasenames(MigrationDescriptor $descriptor): array
    {
        $receipts = [];
        if ($this->ledgerExists()) {
            $rows = $this->db->table(self::LEDGER)
                ->select(['migration'])
                ->where('source', $descriptor->source())
                ->get();
            foreach ($rows as $row) {
                $receipts[(string) $row['migration']] = true;
            }
        }
        $missing = [];
        foreach ($this->inventory->filesOf($descriptor) as $file) {
            if (!isset($receipts[basename($file)])) {
                $missing[] = basename($file);
            }
        }
        return $missing;
    }

    private function ledgerExists(): bool
    {
        return $this->db->getSchemaBuilder()->hasTable(self::LEDGER);
    }
}

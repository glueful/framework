<?php

declare(strict_types=1);

namespace Glueful\Database\Migrations;

/**
 * Per-file outcomes of a source-scoped migration run (schema policy spec B4/B5). Execution stops
 * at the first failure, so at most one outcome is non-applied and later files stay pending.
 */
final class MigrationRunReport
{
    /**
     * @param list<array{file: string, source: string, status: 'applied'|'failed',
     *                   requiresManualRepair: bool, error: ?string}> $outcomes
     */
    public function __construct(public readonly array $outcomes)
    {
    }

    /** @return list<array{file: string, source: string, status: string, requiresManualRepair: bool, error: ?string}> */
    public function failed(): array
    {
        return array_values(array_filter(
            $this->outcomes,
            static fn(array $o): bool => $o['status'] === 'failed'
        ));
    }

    /** @return array{file: string, source: string, status: string, requiresManualRepair: bool, error: ?string}|null */
    public function firstFailure(): ?array
    {
        return $this->failed()[0] ?? null;
    }
}

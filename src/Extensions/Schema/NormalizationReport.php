<?php

declare(strict_types=1);

namespace Glueful\Extensions\Schema;

final class NormalizationReport
{
    /**
     * @param list<array{source: string, alias: string, migration: string}> $rewritten
     * @param list<array{alias: string, reason: string}> $refused
     */
    public function __construct(
        public readonly array $rewritten,
        public readonly array $refused,
    ) {
    }
}

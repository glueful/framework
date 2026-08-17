<?php

declare(strict_types=1);

namespace Glueful\Extensions\Schema;

final class AdoptionReport
{
    /** @param list<string> $adopted migration basenames whose receipts were written */
    public function __construct(
        public readonly string $source,
        public readonly array $adopted,
    ) {
    }
}

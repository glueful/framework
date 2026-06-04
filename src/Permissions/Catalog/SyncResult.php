<?php

declare(strict_types=1);

namespace Glueful\Permissions\Catalog;

final class SyncResult
{
    /** @param string[] $stale managed slugs absent from the registry */
    public function __construct(
        public readonly int $created,
        public readonly int $updated,
        public readonly int $unchanged,
        public readonly array $stale = [],
    ) {
    }
}

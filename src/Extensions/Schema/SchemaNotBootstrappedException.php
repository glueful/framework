<?php

declare(strict_types=1);

namespace Glueful\Extensions\Schema;

/**
 * The schema-operation ledger (the extension_operations core descriptor) is not migrated yet — a
 * 1.78.x upgrade must run one deliberate core migrate before the first enable. No operation row
 * is attempted: its table may not exist.
 */
final class SchemaNotBootstrappedException extends \RuntimeException
{
    public static function create(): self
    {
        return new self(
            "Run 'php glueful migrate:run' once after upgrading — the schema operation ledger "
            . 'is not yet migrated.'
        );
    }
}

<?php

declare(strict_types=1);

namespace Glueful\Validation\Contracts;

use Glueful\Database\Connection;

/**
 * A rule that must query the database to decide (e.g. `unique:`, `exists:`).
 *
 * The Validator hands such rules its connection before validating. A
 * database-aware rule that ends up with no connection must fail closed
 * (throw) rather than silently pass.
 */
interface DatabaseAwareRule extends Rule
{
    /**
     * Whether the rule already has a database handle (PDO or Connection).
     */
    public function hasConnection(): bool;

    /**
     * Supply the connection the rule queries.
     */
    public function setConnection(Connection $connection): static;
}

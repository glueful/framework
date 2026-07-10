<?php

declare(strict_types=1);

namespace Glueful\Database\Execution;

use PDOStatement;

/** Around-execution extension point that can hold a resource across prepare and execute. */
interface ExecutionWrapperInterface
{
    /**
     * @param array<int|string,mixed> $bindings
     * @param callable():PDOStatement $proceed
     */
    public function around(string $sql, array $bindings, callable $proceed): PDOStatement;
}

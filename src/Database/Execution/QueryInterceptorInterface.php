<?php

declare(strict_types=1);

namespace Glueful\Database\Execution;

/**
 * A pre-execution query interceptor.
 *
 * Invoked in {@see QueryExecutor::executeStatement()} BEFORE the statement is
 * prepared/executed. Throwing from before() prevents the query from running,
 * which makes it suitable for enforcement (e.g. tenant-scope guards) — not just
 * observation. Interceptors are registered once at boot and chained; all run in
 * registration order.
 */
interface QueryInterceptorInterface
{
    /**
     * @param array<int|string, mixed> $bindings
     */
    public function before(string $sql, array $bindings): void;
}

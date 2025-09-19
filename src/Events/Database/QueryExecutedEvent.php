<?php

declare(strict_types=1);

namespace Glueful\Events\Database;

use Glueful\Events\Contracts\BaseEvent;

/**
 * Query Executed Event
 *
 * Dispatched when a database query is executed.
 * Used for query logging, performance monitoring, and debugging.
 *
 * @package Glueful\Events\Database
 */
class QueryExecutedEvent extends BaseEvent
{
    /**
     * @param string $sql SQL query
     * @param array<int, mixed> $bindings Query bindings
     * @param float $executionTime Execution time in seconds
     * @param string $connectionName Database connection name
     * @param array<string, mixed> $metadata Additional metadata
     */
    public function __construct(
        private readonly string $sql,
        private readonly array $bindings = [],
        private readonly float $executionTime = 0.0,
        private readonly string $connectionName = 'default',
        array $metadata = []
    ) {
        parent::__construct();

        foreach ($metadata as $key => $value) {
            $this->setMetadata($key, $value);
        }
    }

    public function getSql(): string
    {
        return $this->sql;
    }

    /**
     * @return array<int, mixed>
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    public function getExecutionTime(): float
    {
        return $this->executionTime;
    }

    public function getConnectionName(): string
    {
        return $this->connectionName;
    }

    public function getFullQuery(): string
    {
        $query = $this->sql;
        foreach ($this->bindings as $binding) {
            $value = is_string($binding) ? "'{$binding}'" : (string)$binding;
            $query = preg_replace('/\\?/', $value, $query, 1);
        }
        return $query;
    }

    public function isSlow(float $threshold = 1.0): bool
    {
        return $this->executionTime > $threshold;
    }

    public function getQueryType(): string
    {
        $sql = trim(strtoupper($this->sql));
        if (str_starts_with($sql, 'SELECT')) {
            return 'SELECT';
        } elseif (str_starts_with($sql, 'INSERT')) {
            return 'INSERT';
        } elseif (str_starts_with($sql, 'UPDATE')) {
            return 'UPDATE';
        } elseif (str_starts_with($sql, 'DELETE')) {
            return 'DELETE';
        }
        return 'OTHER';
    }

    public function isModifying(): bool
    {
        return in_array($this->getQueryType(), ['INSERT', 'UPDATE', 'DELETE'], true);
    }
}

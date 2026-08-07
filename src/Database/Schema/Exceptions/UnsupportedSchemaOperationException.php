<?php

declare(strict_types=1);

namespace Glueful\Database\Schema\Exceptions;

/**
 * A schema alteration cannot be performed safely.
 *
 * Thrown during preflight, before any DDL executes — the original table is
 * never touched. Carries enough structure for callers and logs to say
 * exactly what was rejected and why preservation cannot be guaranteed.
 */
class UnsupportedSchemaOperationException extends \RuntimeException
{
    protected string $tableName = '';
    protected string $operationName = '';
    protected string $featureName = '';
    protected string $reasonText = '';

    public static function forFeature(string $table, string $operation, string $feature, string $reason): self
    {
        $exception = new self(sprintf(
            'Unsupported schema operation on table "%s" (%s): %s — %s',
            $table,
            $operation,
            $feature,
            $reason
        ));
        $exception->tableName = $table;
        $exception->operationName = $operation;
        $exception->featureName = $feature;
        $exception->reasonText = $reason;

        return $exception;
    }

    public function table(): string
    {
        return $this->tableName;
    }

    public function operation(): string
    {
        return $this->operationName;
    }

    public function feature(): string
    {
        return $this->featureName;
    }

    public function reason(): string
    {
        return $this->reasonText;
    }
}

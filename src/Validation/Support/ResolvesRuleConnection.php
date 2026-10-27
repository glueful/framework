<?php

declare(strict_types=1);

namespace Glueful\Validation\Support;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use PDO;

/**
 * Database-handle plumbing shared by database-aware rules (DbUnique, Exists).
 *
 * Resolution order: an explicitly set PDO, an explicitly set Connection, a
 * `pdo` / `connection` entry in the validation context, then the Connection
 * bound in the container of an ApplicationContext passed as `context`. When
 * none is available the rule fails closed with a LogicException — a missing
 * connection is a wiring bug, and silently passing would let duplicate or
 * dangling values through.
 */
trait ResolvesRuleConnection
{
    private ?PDO $pdo = null;

    private ?Connection $connection = null;

    /**
     * Set PDO instance for database connection
     */
    public function setPdo(PDO $pdo): static
    {
        $this->pdo = $pdo;
        return $this;
    }

    public function setConnection(Connection $connection): static
    {
        $this->connection = $connection;
        return $this;
    }

    public function hasConnection(): bool
    {
        return $this->pdo !== null || $this->connection !== null;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function resolvePdo(array $context, string $ruleName, string $field): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }
        if ($this->connection !== null) {
            return $this->connection->getPDO();
        }

        $pdo = $context['pdo'] ?? null;
        if ($pdo instanceof PDO) {
            return $pdo;
        }

        $connection = $context['connection'] ?? null;
        if ($connection instanceof Connection) {
            return $connection->getPDO();
        }

        $app = $context['context'] ?? null;
        if ($app instanceof ApplicationContext && $app->hasContainer()) {
            $container = $app->getContainer();
            if ($container->has(Connection::class)) {
                $resolved = $container->get(Connection::class);
                if ($resolved instanceof Connection) {
                    return $resolved->getPDO();
                }
            }
        }

        throw new \LogicException(sprintf(
            'The "%s" rule on field "%s" requires a database connection, but none was supplied. '
            . 'Construct the Validator with an ApplicationContext or Connection, or call '
            . 'setConnection()/setPdo() on the rule.',
            $ruleName,
            $field
        ));
    }
}

<?php

declare(strict_types=1);

namespace Glueful\Installer;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;

/**
 * Tests an explicit DatabaseConfig against a transient connection built the same way the
 * migration step builds it — so "tested == migrated". Mutates no .env, config, or pool, and
 * never leaks the password.
 */
final class ConnectionTester
{
    public function __construct(private readonly ?ApplicationContext $context = null)
    {
    }

    public function test(DatabaseConfig $config): ConnectionTestResult
    {
        try {
            $connection = new Connection($config->toConnectionConfig(), $this->context);
            $connection->getPDO()->query('SELECT 1');
            unset($connection); // transient; pooling disabled in toConnectionConfig()
            return new ConnectionTestResult($config->engine, true, 'Connection successful.');
        } catch (\PDOException $e) {
            // PDO messages do not include the password; errorInfo[0] is the SQLSTATE.
            $sqlState = is_array($e->errorInfo ?? null) ? ($e->errorInfo[0] ?? null) : null;
            return new ConnectionTestResult(
                $config->engine,
                false,
                'Could not connect: ' . $e->getMessage(),
                $e::class,
                $sqlState,
            );
        } catch (\Throwable $e) {
            return new ConnectionTestResult(
                $config->engine,
                false,
                'Could not connect: ' . $e->getMessage(),
                $e::class,
                null,
            );
        }
    }
}

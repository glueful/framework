<?php

declare(strict_types=1);

namespace Glueful\Database\Seeders;

use Glueful\Database\Connection;
use Psr\Container\ContainerInterface;

/**
 * Base Seeder Class
 *
 * Abstract base class for database seeders. Provides common functionality
 * for populating databases with initial or test data.
 *
 * Features:
 * - Dependency ordering support
 * - Transaction wrapping
 * - Table truncation helpers
 * - Nested seeder calling
 *
 * @example
 * class UserSeeder extends Seeder
 * {
 *     protected array $dependencies = [RoleSeeder::class];
 *
 *     public function run(): void
 *     {
 *         User::create(['name' => 'Admin', 'email' => 'admin@example.com']);
 *     }
 * }
 *
 * @package Glueful\Database\Seeders
 */
abstract class Seeder
{
    /**
     * The DI container
     */
    protected ContainerInterface $container;

    /**
     * The database connection
     */
    protected ?Connection $connection = null;

    /**
     * Seeders that should run before this one
     *
     * @var array<class-string<Seeder>>
     */
    protected array $dependencies = [];

    /**
     * Create a new seeder instance
     */
    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * Run the database seeds
     *
     * This method must be implemented by child classes to define
     * what data should be seeded into the database.
     */
    abstract public function run(): void;

    /**
     * Get seeder dependencies
     *
     * Dependencies are other seeders that must run before this seeder.
     *
     * @return array<class-string<Seeder>>
     */
    public function getDependencies(): array
    {
        return $this->dependencies;
    }

    /**
     * Call another seeder
     *
     * Use this method to run other seeders from within your seeder.
     * This is useful for organizing seeders or creating a main
     * DatabaseSeeder that orchestrates other seeders.
     *
     * @param class-string<Seeder>|array<class-string<Seeder>> $class Seeder class(es) to run
     */
    protected function call(string|array $class): void
    {
        $classes = is_array($class) ? $class : [$class];

        foreach ($classes as $seederClass) {
            /** @var Seeder $seeder */
            $seeder = $this->container->get($seederClass);
            $seeder->run();
        }
    }

    /**
     * Run a closure within a database transaction
     *
     * Wraps the seeding logic in a transaction for atomicity.
     * If any error occurs, all changes are rolled back.
     *
     * @param callable $callback The seeding logic to execute
     * @throws \Throwable If the callback throws an exception
     */
    protected function withTransaction(callable $callback): void
    {
        $pdo = $this->getConnection()->getPDO();

        $pdo->beginTransaction();

        try {
            $callback();
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Truncate a table before seeding
     *
     * Removes all records from the specified table. Foreign key
     * checks are temporarily disabled to allow truncation of
     * tables with relationships.
     *
     * @param string $table The table name to truncate
     */
    protected function truncate(string $table): void
    {
        $connection = $this->getConnection();
        $pdo = $connection->getPDO();
        $driverName = $connection->getDriverName();

        if ($driverName === 'mysql') {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            $pdo->exec("TRUNCATE TABLE `{$table}`");
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        } elseif ($driverName === 'sqlite') {
            $pdo->exec("DELETE FROM \"{$table}\"");
            $pdo->exec("DELETE FROM sqlite_sequence WHERE name='{$table}'");
        } elseif ($driverName === 'pgsql') {
            $pdo->exec("TRUNCATE TABLE \"{$table}\" RESTART IDENTITY CASCADE");
        } else {
            $pdo->exec("TRUNCATE TABLE {$table}");
        }
    }

    /**
     * Get the database connection
     *
     * @return Connection
     */
    protected function getConnection(): Connection
    {
        if ($this->connection === null) {
            $this->connection = $this->container->get(Connection::class);
        }

        return $this->connection;
    }

    /**
     * Output a message during seeding
     *
     * @param string $message The message to output
     */
    protected function command(string $message): void
    {
        if (php_sapi_name() === 'cli') {
            echo $message . PHP_EOL;
        }
    }
}

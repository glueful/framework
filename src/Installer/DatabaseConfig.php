<?php

declare(strict_types=1);

namespace Glueful\Installer;

/**
 * The DB config being installed. Threaded through test → persist → migrate so the tested
 * connection and the migrated connection are built from one source and cannot diverge.
 */
final class DatabaseConfig
{
    public function __construct(
        public readonly string $engine,        // 'mysql' | 'pgsql' | 'sqlite'
        public readonly string $host = '',
        public readonly int $port = 0,
        public readonly string $database = '', // db name, or the sqlite file path
        public readonly string $username = '',
        public readonly string $password = '',
        public readonly ?string $schema = null,  // pgsql only
        public readonly ?string $sslMode = null, // pgsql only
    ) {
    }

    /**
     * Internal Connection config override (matches Connection::buildConfigFromEnv() keys:
     * db/user/pass). Pooling is disabled so a test build is transient.
     *
     * @return array<string, mixed>
     */
    public function toConnectionConfig(): array
    {
        $base = ['engine' => $this->engine, 'pooling' => ['enabled' => false]];

        return match ($this->engine) {
            'sqlite' => $base + ['sqlite' => ['primary' => $this->database]],
            'mysql' => $base + ['mysql' => [
                'host' => $this->host,
                'port' => $this->port,
                'db' => $this->database,
                'user' => $this->username,
                'pass' => $this->password,
                'charset' => 'utf8mb4',
                'strict' => true,
            ]],
            'pgsql' => $base + ['pgsql' => array_filter([
                'host' => $this->host,
                'port' => $this->port,
                'db' => $this->database,
                'user' => $this->username,
                'pass' => $this->password,
                'schema' => $this->schema ?? 'public',
                'sslmode' => $this->sslMode,
            ], static fn ($v): bool => $v !== null)],
            default => throw new \InvalidArgumentException("Unsupported engine: {$this->engine}"),
        };
    }

    /**
     * The `.env` DB_* key/value pairs. Empty optional pgsql params are omitted.
     *
     * @return array<string, string>
     */
    public function toEnvPairs(): array
    {
        return match ($this->engine) {
            'sqlite' => ['DB_DRIVER' => 'sqlite', 'DB_SQLITE_DATABASE' => $this->database],
            'mysql' => [
                'DB_DRIVER' => 'mysql',
                'DB_HOST' => $this->host,
                'DB_PORT' => (string) $this->port,
                'DB_DATABASE' => $this->database,
                'DB_USERNAME' => $this->username,
                'DB_PASSWORD' => $this->password,
            ],
            'pgsql' => array_filter([
                'DB_DRIVER' => 'pgsql',
                'DB_PGSQL_HOST' => $this->host,
                'DB_PGSQL_PORT' => (string) $this->port,
                'DB_PGSQL_DATABASE' => $this->database,
                'DB_PGSQL_USERNAME' => $this->username,
                'DB_PGSQL_PASSWORD' => $this->password,
                'DB_PGSQL_SCHEMA' => $this->schema,
                'DB_PGSQL_SSL_MODE' => $this->sslMode,
            ], static fn ($v): bool => $v !== null && $v !== ''),
            default => throw new \InvalidArgumentException("Unsupported engine: {$this->engine}"),
        };
    }
}

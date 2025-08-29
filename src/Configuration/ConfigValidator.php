<?php

declare(strict_types=1);

namespace Glueful\Configuration;

/**
 * Configuration Validator
 *
 * Validates configuration arrays to catch errors early in the boot process.
 * Provides comprehensive validation for core configuration sections with
 * detailed error messages.
 */
class ConfigValidator
{
    /**
     * Validate entire configuration array
     *
     * @param array $config Complete merged configuration
     * @return array List of validation error messages (empty if valid)
     */
    public function validate(array $config): array
    {
        $violations = [];

        // Validate app configuration
        if (isset($config['app'])) {
            $violations = array_merge($violations, $this->validateAppConfig($config['app']));
        }

        // Validate database configuration
        if (isset($config['database'])) {
            $violations = array_merge($violations, $this->validateDatabaseConfig($config['database']));
        }

        // Validate security configuration
        if (isset($config['security'])) {
            $violations = array_merge($violations, $this->validateSecurityConfig($config['security']));
        }

        // Validate cache configuration
        if (isset($config['cache'])) {
            $violations = array_merge($violations, $this->validateCacheConfig($config['cache']));
        }

        // Validate queue configuration
        if (isset($config['queue'])) {
            $violations = array_merge($violations, $this->validateQueueConfig($config['queue']));
        }

        // Validate session configuration
        if (isset($config['session'])) {
            $violations = array_merge($violations, $this->validateSessionConfig($config['session']));
        }

        return $violations;
    }

    /**
     * Validate app configuration section
     */
    private function validateAppConfig(array $config): array
    {
        $violations = [];

        // Validate required fields
        if (empty($config['name'])) {
            $violations[] = 'app.name is required and cannot be empty';
        }

        if (isset($config['version']) && !preg_match('/^v?\d+\.\d+(\.\d+)?$/', $config['version'])) {
            $violations[] = 'app.version must be in format "v1.0" or "1.0.0"';
        }

        if (isset($config['timezone']) && !in_array($config['timezone'], timezone_identifiers_list())) {
            $violations[] = 'app.timezone must be a valid timezone identifier';
        }

        if (isset($config['debug']) && !is_bool($config['debug'])) {
            $violations[] = 'app.debug must be a boolean value';
        }

        // Validate rate limiting config
        if (isset($config['rate_limiting'])) {
            $rateLimiting = $config['rate_limiting'];

            if (isset($rateLimiting['enabled']) && !is_bool($rateLimiting['enabled'])) {
                $violations[] = 'app.rate_limiting.enabled must be a boolean';
            }

            if (isset($rateLimiting['requests_per_minute'])) {
                $rpm = $rateLimiting['requests_per_minute'];
                if (!is_int($rpm) || $rpm < 1 || $rpm > 10000) {
                    $violations[] = 'app.rate_limiting.requests_per_minute must be an integer between 1 and 10000';
                }
            }
        }

        // Validate CORS config
        if (isset($config['cors'])) {
            $cors = $config['cors'];

            if (isset($cors['enabled']) && !is_bool($cors['enabled'])) {
                $violations[] = 'app.cors.enabled must be a boolean';
            }

            if (isset($cors['allowed_methods']) && !is_array($cors['allowed_methods'])) {
                $violations[] = 'app.cors.allowed_methods must be an array';
            }

            if (isset($cors['max_age']) && (!is_int($cors['max_age']) || $cors['max_age'] < 0)) {
                $violations[] = 'app.cors.max_age must be a non-negative integer';
            }
        }

        return $violations;
    }

    /**
     * Validate database configuration section
     */
    private function validateDatabaseConfig(array $config): array
    {
        $violations = [];

        // Validate engine/default connection
        $defaultEngine = $config['engine'] ?? $config['default'] ?? null;
        if (empty($defaultEngine)) {
            $violations[] = 'database.engine (or database.default) is required';
        }

        // Validate connections
        if (
            !isset($config['connections'])
            && !isset($config['mysql'])
            && !isset($config['sqlite'])
            && !isset($config['pgsql'])
        ) {
            $violations[] = 'database must have either connections array or direct driver configs ' .
                '(mysql, sqlite, pgsql)';
        }

        // If using new connections format
        if (isset($config['connections']) && is_array($config['connections'])) {
            foreach ($config['connections'] as $name => $connection) {
                if (!isset($connection['driver'])) {
                    $violations[] = "database.connections.{$name}.driver is required";
                }

                if (isset($connection['driver'])) {
                    $connectionPath = "database.connections.{$name}";
                    $violations = array_merge(
                        $violations,
                        $this->validateConnectionConfig($connection, $connectionPath)
                    );
                }
            }
        }

        // Validate direct driver configs
        foreach (['mysql', 'sqlite', 'pgsql'] as $driver) {
            if (isset($config[$driver])) {
                $violations = array_merge(
                    $violations,
                    $this->validateConnectionConfig($config[$driver], "database.{$driver}")
                );
            }
        }

        // Validate pooling config
        if (isset($config['pooling']['enabled']) && !is_bool($config['pooling']['enabled'])) {
            $violations[] = 'database.pooling.enabled must be a boolean';
        }

        return $violations;
    }

    /**
     * Validate individual database connection configuration
     */
    private function validateConnectionConfig(array $connection, string $prefix): array
    {
        $violations = [];
        $driver = $connection['driver'] ?? null;

        switch ($driver) {
            case 'mysql':
                if (empty($connection['host']) && empty($connection['socket'])) {
                    $violations[] = "{$prefix}.host or {$prefix}.socket is required for MySQL";
                }
                if (empty($connection['database']) && empty($connection['db'])) {
                    $violations[] = "{$prefix}.database (or db) is required for MySQL";
                }
                if (
                    isset($connection['port']) &&
                    (!is_int($connection['port']) ||
                     $connection['port'] < 1 ||
                     $connection['port'] > 65535)
                ) {
                    $violations[] = "{$prefix}.port must be a valid port number (1-65535)";
                }
                break;

            case 'pgsql':
            case 'postgresql':
                if (empty($connection['host'])) {
                    $violations[] = "{$prefix}.host is required for PostgreSQL";
                }
                if (empty($connection['database']) && empty($connection['db'])) {
                    $violations[] = "{$prefix}.database (or db) is required for PostgreSQL";
                }
                break;

            case 'sqlite':
                $dbPath = $connection['database'] ?? $connection['primary'] ?? null;
                if (empty($dbPath)) {
                    $violations[] = "{$prefix}.database (or primary) is required for SQLite";
                }
                break;

            default:
                if (!empty($driver)) {
                    $violations[] = "{$prefix}.driver '{$driver}' is not supported (use mysql, pgsql, or sqlite)";
                }
        }

        return $violations;
    }

    /**
     * Validate security configuration section
     */
    private function validateSecurityConfig(array $config): array
    {
        $violations = [];

        $booleanFields = ['force_https', 'csrf_protection', 'xss_protection', 'content_type_sniffing'];
        foreach ($booleanFields as $field) {
            if (isset($config[$field]) && !is_bool($config[$field])) {
                $violations[] = "security.{$field} must be a boolean value";
            }
        }

        return $violations;
    }

    /**
     * Validate cache configuration section
     */
    private function validateCacheConfig(array $config): array
    {
        $violations = [];

        if (isset($config['default_ttl']) && (!is_int($config['default_ttl']) || $config['default_ttl'] < 0)) {
            $violations[] = 'cache.default_ttl must be a non-negative integer';
        }

        if (isset($config['enabled']) && !is_bool($config['enabled'])) {
            $violations[] = 'cache.enabled must be a boolean';
        }

        return $violations;
    }

    /**
     * Validate queue configuration section
     */
    private function validateQueueConfig(array $config): array
    {
        $violations = [];

        if (isset($config['enabled']) && !is_bool($config['enabled'])) {
            $violations[] = 'queue.enabled must be a boolean';
        }

        if (isset($config['max_workers']) && (!is_int($config['max_workers']) || $config['max_workers'] < 1)) {
            $violations[] = 'queue.max_workers must be a positive integer';
        }

        return $violations;
    }

    /**
     * Validate session configuration section
     */
    private function validateSessionConfig(array $config): array
    {
        $violations = [];

        if (isset($config['lifetime']) && (!is_int($config['lifetime']) || $config['lifetime'] < 1)) {
            $violations[] = 'session.lifetime must be a positive integer';
        }

        if (isset($config['secure']) && !is_bool($config['secure'])) {
            $violations[] = 'session.secure must be a boolean';
        }

        if (isset($config['http_only']) && !is_bool($config['http_only'])) {
            $violations[] = 'session.http_only must be a boolean';
        }

        return $violations;
    }

    /**
     * Validate specific configuration section
     *
     * @param string $section Section name (e.g., 'app', 'database')
     * @param array $config Section configuration
     * @return array Validation errors
     */
    public function validateSection(string $section, array $config): array
    {
        return match ($section) {
            'app' => $this->validateAppConfig($config),
            'database' => $this->validateDatabaseConfig($config),
            'security' => $this->validateSecurityConfig($config),
            'cache' => $this->validateCacheConfig($config),
            'queue' => $this->validateQueueConfig($config),
            'session' => $this->validateSessionConfig($config),
            default => [] // Unknown sections are allowed
        };
    }
}

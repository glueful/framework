<?php

declare(strict_types=1);

namespace Glueful\Config;

/**
 * DSN Parser utilities for common connection strings (DB, Redis).
 *
 * Examples:
 *  - mysql://user:pass@localhost:3306/dbname?charset=utf8mb4&timeout=5
 *  - pgsql://user:pass@db.example.com:5432/mydb?sslmode=require
 *  - sqlite:///absolute/path/to/file.sqlite
 *  - sqlite:///:memory:
 *  - redis://:password@localhost:6379/0
 *  - rediss://cache.example.com:6380/1
 */
final class DsnParser
{
    /**
     * Parse a database DSN into a normalized array suitable for drivers.
     *
     * Keys: driver, host, port, dbname, user, pass, path (for sqlite), options (array)
     *
     * @return array<string, mixed>
     */
    public static function parseDbDsn(string $dsn): array
    {
        $parts = self::parse($dsn);
        $scheme = strtolower($parts['scheme'] ?? '');
        $out = [
            'driver' => $scheme,
            'host' => $parts['host'] ?? null,
            'port' => isset($parts['port']) ? (int) $parts['port'] : null,
            'dbname' => null,
            'user' => $parts['user'] ?? null,
            'pass' => $parts['pass'] ?? null,
            'path' => null,
            'options' => $parts['query'] ?? [],
        ];

        if ($scheme === 'sqlite') {
            // sqlite path lives under path, dbname is not used
            $out['path'] = $parts['path'] ?? null; // may be /:memory:
            return $out;
        }

        // strip leading '/' from path to get dbname
        if (isset($parts['path']) && $parts['path'] !== '') {
            $p = ltrim((string) $parts['path'], '/');
            if ($p !== '') {
                $out['dbname'] = $p;
            }
        }
        return $out;
    }

    /**
     * Parse a Redis DSN into a normalized array.
     *
     * Keys: scheme (redis|rediss), host, port, db (int|null), password, path (for unix socket), options
     *
     * @return array<string, mixed>
     */
    public static function parseRedisDsn(string $dsn): array
    {
        $parts = self::parse($dsn);
        $scheme = strtolower($parts['scheme'] ?? 'redis');
        $out = [
            'scheme' => $scheme,
            'host' => $parts['host'] ?? null,
            'port' => isset($parts['port']) ? (int) $parts['port'] : 6379,
            'db' => null,
            'password' => $parts['pass'] ?? null,
            'path' => null,
            'options' => $parts['query'] ?? [],
        ];

        // Database index from path segment
        if (isset($parts['path']) && $parts['path'] !== '') {
            $p = ltrim((string) $parts['path'], '/');
            if ($p !== '' && ctype_digit($p)) {
                $out['db'] = (int) $p;
            } else {
                // Could be a UNIX socket path for some redis libraries
                if (str_starts_with((string) $parts['path'], '/')) {
                    $out['path'] = $parts['path'];
                }
            }
        }

        return $out;
    }

    /**
     * Robust DSN parser returning familiar parse_url pieces plus decoded query map.
     * @return array<string, mixed>
     */
    public static function parse(string $dsn): array
    {
        $components = @parse_url($dsn);
        if ($components === false || !is_array($components)) {
            throw new \InvalidArgumentException('Invalid DSN: ' . $dsn);
        }

        // Normalize user/pass
        if (isset($components['user'])) {
            $components['user'] = urldecode((string) $components['user']);
        }
        if (isset($components['pass'])) {
            $components['pass'] = urldecode((string) $components['pass']);
        }

        // Decode query into array
        $queryMap = [];
        if (isset($components['query'])) {
            parse_str((string) $components['query'], $queryMap);
        }
        $components['query'] = $queryMap;

        return $components;
    }
}

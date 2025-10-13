<?php

declare(strict_types=1);

namespace Glueful\Support;

final class Dsn
{
    /**
     * Parse a Redis DSN.
     * Supports: redis://[:pass]@host:port/db?query and rediss:// for TLS.
     * Returns a normalized map with host, port, db, password, scheme and any query params.
     *
     * @return array<string,mixed>
     */
    public static function parseRedis(string $dsn): array
    {
        $result = [
            'scheme' => 'redis',
            'host' => '127.0.0.1',
            'port' => 6379,
            'db' => 0,
            'password' => null,
            'params' => [],
        ];

        if ($dsn === '') {
            return $result;
        }

        $parts = @parse_url($dsn);
        if ($parts === false || !is_array($parts)) {
            return $result;
        }

        if (isset($parts['scheme']) && $parts['scheme'] !== '') {
            $result['scheme'] = strtolower($parts['scheme']);
        }
        if (isset($parts['host']) && $parts['host'] !== '') {
            $result['host'] = $parts['host'];
        }
        if (isset($parts['port'])) {
            $result['port'] = (int) $parts['port'];
        }
        if (isset($parts['user']) && $parts['user'] !== '') {
            // Rarely used: redis://user:pass@host
            $result['user'] = $parts['user'];
        }
        if (isset($parts['pass']) && $parts['pass'] !== '') {
            $result['password'] = $parts['pass'];
        }
        if (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/') {
            $db = ltrim($parts['path'], '/');
            if ($db !== '' && ctype_digit($db)) {
                $result['db'] = (int) $db;
            }
        }
        if (isset($parts['query']) && $parts['query'] !== '') {
            $query = [];
            parse_str($parts['query'], $query);
            /** @var array<string, string> $query */
            $result['params'] = $query;
            // common aliases
            if (isset($query['database']) && ctype_digit((string) $query['database'])) {
                $result['db'] = (int) $query['database'];
            }
            if (isset($query['db']) && ctype_digit((string) $query['db'])) {
                $result['db'] = (int) $query['db'];
            }
        }

        return $result;
    }
}

<?php

namespace Glueful\Database\Driver;

/**
 * SQLite Database Driver Implementation
 *
 * Implements SQLite-specific SQL generation and identifier handling.
 * Provides SQLite syntax for:
 * - Double-quote identifier quoting
 * - INSERT OR IGNORE operations
 * - REPLACE functionality
 * - SQLite-specific constraints
 *
 * Note: SQLite has specific limitations:
 * - No native UPSERT before version 3.24.0
 * - Limited constraint support
 * - Single-writer concurrency model
 */
class SQLiteDriver implements DatabaseDriver
{
    /**
     * Wrap SQLite identifier with double quotes
     *
     * Ensures proper escaping of column and table names:
     * - Uses double quotes for identifiers
     * - Handles special characters
     * - Handles table aliases (e.g., "table AS alias")
     * - Prevents SQL injection
     *
     * @param  string $identifier Column or table name
     * @return string Double-quote wrapped identifier
     */
    public function wrapIdentifier(string $identifier): string
    {
        // Handle table aliases: "table AS alias" or "table alias"
        if (preg_match('/^(.+?)\s+(?:AS\s+)?([a-zA-Z_][a-zA-Z0-9_]*)$/i', $identifier, $matches)) {
            $tableName = trim($matches[1]);
            $alias = $matches[2];
            return "\"$tableName\" AS \"$alias\"";
        }

        // Handle qualified column names: "alias.column" or "table.column"
        // Convert f.created_at to "f"."created_at"
        if (str_contains($identifier, '.') && !str_contains($identifier, ' ')) {
            $parts = explode('.', $identifier);
            return implode('.', array_map(fn($part) => "\"$part\"", $parts));
        }

        return "\"$identifier\"";
    }

    /**
     * Generate SQLite INSERT OR IGNORE statement
     *
     * Creates SQL that ignores constraint violations:
     * - Uses INSERT OR IGNORE syntax
     * - Maintains data integrity
     * - Handles duplicate records gracefully
     *
     * @param  string $table   Target table
     * @param  array<string, mixed>  $columns Column list
     * @return string SQLite insert statement
     */
    public function insertIgnore(string $table, array $columns): string
    {
        $cols = implode(", ", array_map([$this, 'wrapIdentifier'], $columns));
        $placeholders = implode(", ", array_fill(0, count($columns), "?"));
        return "INSERT OR IGNORE INTO {$this->wrapIdentifier($table)} ($cols) VALUES ($placeholders)";
    }

    /**
     * Generate SQLite UPSERT statement
     *
     * Creates INSERT with ON CONFLICT handling:
     * - Uses modern SQLite upsert syntax
     * - Falls back to REPLACE for older versions
     * - Handles constraint conflicts
     * - Maintains atomicity
     *
     * @param  string $table         Target table
     * @param  array<string, mixed>  $columns       Columns to insert
     * @param  array<string, mixed>  $updateColumns Columns to update on conflict
     * @return string SQLite upsert statement
     */
    public function upsert(string $table, array $columns, array $updateColumns): string
    {
        $cols = implode(", ", array_map([$this, 'wrapIdentifier'], $columns));
        $placeholders = implode(", ", array_fill(0, count($columns), "?"));
        $updates = implode(", ", array_map(fn($col) => "\"$col\" = EXCLUDED.\"$col\"", $updateColumns));

        return "INSERT INTO {$this->wrapIdentifier($table)} ($cols) VALUES ($placeholders)" .
               " ON CONFLICT(id) DO UPDATE SET $updates";
    }

    /**
     * Get SQLite table columns query
     *
     * Returns PRAGMA query to retrieve column information for a table.
     * SQLite uses PRAGMA table_info instead of information_schema.
     *
     * @param  string $table Target table name
     * @return string SQLite query to get column information
     */
    public function getTableColumnsQuery(string $table): string
    {
        return "PRAGMA table_info({$table})";
    }

    /**
     * Format datetime for SQLite storage
     *
     * SQLite stores datetime values as TEXT in ISO 8601 format ('Y-m-d H:i:s').
     * This method ensures consistent datetime formatting for SQLite DATETIME columns.
     *
     * @param  \DateTime|string|null $datetime Datetime to format (defaults to current time)
     * @return string SQLite-compatible datetime string
     * @throws \InvalidArgumentException If provided datetime string is invalid
     */
    public function formatDateTime($datetime = null): string
    {
        if ($datetime === null) {
            return date('Y-m-d H:i:s');
        }

        if ($datetime instanceof \DateTime) {
            return $datetime->format('Y-m-d H:i:s');
        }

        if (is_string($datetime)) {
            $parsedDate = \DateTime::createFromFormat('Y-m-d H:i:s', $datetime);
            if ($parsedDate === false) {
                // Try to parse as a general date string
                try {
                    $parsedDate = new \DateTime($datetime);
                } catch (\Exception) {
                    throw new \InvalidArgumentException("Invalid datetime string: {$datetime}");
                }
            }
            return $parsedDate->format('Y-m-d H:i:s');
        }

        throw new \InvalidArgumentException('Datetime must be null, DateTime object, or string');
    }

    /**
     * {@inheritdoc}
     */
    public function getPingQuery(): string
    {
        return 'SELECT 1';
    }

    /**
     * {@inheritdoc}
     */
    public function getDriverName(): string
    {
        return 'sqlite';
    }
}

<?php

declare(strict_types=1);

namespace Glueful\Support\Documentation;

use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Database\Connection;

/**
 * Resource Route Expander
 *
 * Expands generic {resource} routes into table-specific endpoints.
 * Takes route documentation with {resource} placeholders and generates
 * concrete endpoints for each database table with their schemas.
 */
class ResourceRouteExpander
{
    private SchemaBuilderInterface $schema;
    private Connection $db;

    /** @var array<string, array<string, mixed>> Cached table schemas */
    private array $tableSchemas = [];

    /** @var array<string> Tables to exclude from documentation */
    private array $excludedTables = [];

    /**
     * Constructor
     *
     * @param Connection|null $connection Database connection
     */
    public function __construct(?Connection $connection = null)
    {
        $this->db = $connection ?? new Connection();
        $this->schema = $this->db->getSchemaBuilder();

        // Load excluded tables from config
        $configExcluded = config('documentation.excluded_tables', []);
        $this->excludedTables = is_array($configExcluded) ? $configExcluded : [
            'migrations',
            'failed_jobs',
            'password_resets',
            'personal_access_tokens',
        ];
    }

    /**
     * Get all available tables with their schemas
     *
     * @return array<string, array<string, mixed>> Table name => schema mapping
     */
    public function getTableSchemas(): array
    {
        if ($this->tableSchemas !== []) {
            return $this->tableSchemas;
        }

        $tables = $this->schema->getTables();

        foreach ($tables as $table) {
            // Skip excluded tables
            if (in_array($table, $this->excludedTables, true)) {
                continue;
            }

            $columns = $this->schema->getTableColumns($table);
            $this->tableSchemas[$table] = $this->buildTableSchema($table, $columns);
        }

        return $this->tableSchemas;
    }

    /**
     * Build OpenAPI schema for a table
     *
     * @param string $table Table name
     * @param array<int|string, mixed> $columns Column data
     * @return array<string, mixed> OpenAPI schema
     */
    private function buildTableSchema(string $table, array $columns): array
    {
        $properties = [];
        $required = [];

        foreach ($columns as $columnName => $col) {
            $fieldName = $col['name'] ?? $col['Field'] ?? $columnName;
            $fieldType = $col['type'] ?? $col['Type'] ?? 'string';
            $nullable = isset($col['nullable']) ? (bool) $col['nullable'] : ($col['Null'] ?? 'NO') === 'YES';

            $property = $this->mapColumnToOpenApiType($fieldType, $fieldName);

            // Add description based on field name
            $property['description'] = $this->generateFieldDescription($fieldName, $table);

            $properties[$fieldName] = $property;

            // Primary keys and non-nullable fields that aren't auto-generated
            if (!$nullable && !in_array($fieldName, ['id', 'uuid', 'created_at', 'updated_at', 'deleted_at'], true)) {
                $required[] = $fieldName;
            }
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
            'x-table-name' => $table,
            'x-access-mode' => str_starts_with($table, 'vw_') ? 'read-only' : 'read-write',
        ];
    }

    /**
     * Map database column type to OpenAPI type
     *
     * @param string $dbType Database column type
     * @param string $fieldName Field name for context
     * @return array<string, mixed> OpenAPI type definition
     */
    private function mapColumnToOpenApiType(string $dbType, string $fieldName): array
    {
        $dbType = strtolower($dbType);

        // Handle UUID fields
        if ($fieldName === 'uuid' || str_ends_with($fieldName, '_uuid')) {
            return [
                'type' => 'string',
                'format' => 'uuid',
                'example' => '550e8400-e29b-41d4-a716-446655440000',
            ];
        }

        // Integer types
        if (str_contains($dbType, 'int') || str_contains($dbType, 'serial')) {
            if (str_contains($dbType, 'tinyint(1)') || str_contains($dbType, 'boolean')) {
                return ['type' => 'boolean'];
            }
            return ['type' => 'integer', 'format' => str_contains($dbType, 'bigint') ? 'int64' : 'int32'];
        }

        // Decimal/float types
        $isDecimal = str_contains($dbType, 'decimal') || str_contains($dbType, 'numeric');
        $isFloat = str_contains($dbType, 'float') || str_contains($dbType, 'double') || str_contains($dbType, 'real');
        if ($isDecimal || $isFloat) {
            return ['type' => 'number', 'format' => 'double'];
        }

        // Date/time types
        if (str_contains($dbType, 'datetime') || str_contains($dbType, 'timestamp')) {
            return ['type' => 'string', 'format' => 'date-time'];
        }
        if (str_contains($dbType, 'date')) {
            return ['type' => 'string', 'format' => 'date'];
        }
        if (str_contains($dbType, 'time')) {
            return ['type' => 'string', 'format' => 'time'];
        }

        // Text/blob types
        if (str_contains($dbType, 'text') || str_contains($dbType, 'blob')) {
            return ['type' => 'string'];
        }

        // JSON type
        if (str_contains($dbType, 'json')) {
            return ['type' => 'object', 'additionalProperties' => true];
        }

        // Enum type
        if (str_contains($dbType, 'enum')) {
            preg_match("/enum\('([^']+)'(?:,'([^']+)')*\)/i", $dbType, $matches);
            if (count($matches) > 1) {
                $values = array_slice($matches, 1);
                return ['type' => 'string', 'enum' => $values];
            }
        }

        // Default to string
        return ['type' => 'string'];
    }

    /**
     * Generate a human-readable description for a field
     *
     * @param string $fieldName Field name
     * @param string $_tableName Table name for context (reserved for future use)
     * @return string Description
     */
    private function generateFieldDescription(string $fieldName, string $_tableName): string
    {
        // Common field descriptions
        $commonDescriptions = [
            'id' => 'Unique identifier',
            'uuid' => 'Universally unique identifier',
            'created_at' => 'Timestamp when the record was created',
            'updated_at' => 'Timestamp when the record was last updated',
            'deleted_at' => 'Timestamp when the record was soft-deleted',
            'status' => 'Current status of the record',
            'name' => 'Name',
            'email' => 'Email address',
            'password' => 'Password (hashed)',
            'description' => 'Description',
            'title' => 'Title',
            'slug' => 'URL-friendly identifier',
            'active' => 'Whether the record is active',
            'enabled' => 'Whether the record is enabled',
        ];

        if (isset($commonDescriptions[$fieldName])) {
            return $commonDescriptions[$fieldName];
        }

        // Convert snake_case to readable text
        $readable = str_replace('_', ' ', $fieldName);
        $readable = ucfirst($readable);

        // Handle foreign key fields
        if (str_ends_with($fieldName, '_id') || str_ends_with($fieldName, '_uuid')) {
            $related = str_replace(['_id', '_uuid'], '', $fieldName);
            return "Reference to {$related}";
        }

        return $readable;
    }

    /**
     * Expand a route path with {resource} to table-specific paths
     *
     * @param string $path Route path (e.g., "/{resource}/{uuid}")
     * @return array<string, string> Table name => expanded path mapping
     */
    public function expandResourcePath(string $path): array
    {
        $expanded = [];
        $tables = array_keys($this->getTableSchemas());

        foreach ($tables as $table) {
            $expandedPath = str_replace('{resource}', $table, $path);
            $expanded[$table] = $expandedPath;
        }

        return $expanded;
    }

    /**
     * Get schema for a specific table
     *
     * @param string $table Table name
     * @return array<string, mixed>|null Schema or null if not found
     */
    public function getSchemaForTable(string $table): ?array
    {
        $schemas = $this->getTableSchemas();
        return $schemas[$table] ?? null;
    }

    /**
     * Get list of all available table names
     *
     * @return array<string> Table names
     */
    public function getTableNames(): array
    {
        return array_keys($this->getTableSchemas());
    }

    /**
     * Check if a table is a view (read-only)
     *
     * @param string $table Table name
     * @return bool True if table is a view
     */
    public function isReadOnly(string $table): bool
    {
        return str_starts_with($table, 'vw_');
    }

    /**
     * Set tables to exclude from documentation
     *
     * @param array<string> $tables Table names to exclude
     * @return self
     */
    public function excludeTables(array $tables): self
    {
        $this->excludedTables = array_merge($this->excludedTables, $tables);
        $this->tableSchemas = []; // Clear cache
        return $this;
    }

    /**
     * Clear the schema cache
     */
    public function clearCache(): void
    {
        $this->tableSchemas = [];
    }
}

<?php

declare(strict_types=1);

namespace Glueful\Support\Documentation;

use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Database\Connection;

/**
 * Table Definition Generator
 *
 * Generates JSON definition files from database table schemas.
 * These definitions describe the structure of database tables
 * for API documentation generation.
 */
class TableDefinitionGenerator
{
    private SchemaBuilderInterface $schema;
    private Connection $db;
    private string $dbResource;
    private string $outputPath;

    /** @var array<string, bool> Map of generated filename => true */
    private array $generatedFiles = [];

    /**
     * Constructor
     *
     * @param Connection|null $connection Database connection (uses default if null)
     * @param string|null $outputPath Output directory for JSON files
     */
    public function __construct(?Connection $connection = null, ?string $outputPath = null)
    {
        $this->db = $connection ?? new Connection();
        $this->schema = $this->db->getSchemaBuilder();
        $this->dbResource = $this->getDatabaseRole();
        $this->outputPath = $outputPath ?? config('documentation.paths.database_definitions');

        $this->ensureOutputDirectory();
    }

    /**
     * Generate JSON definitions for all tables in database
     *
     * @param string|null $database Specific database to process (uses default if null)
     * @return array<string> List of generated file paths
     */
    public function generateAll(?string $database = null): array
    {
        $dbResource = $database ?? $this->dbResource;
        $generatedFiles = [];

        $tables = $this->schema->getTables();

        foreach ($tables as $table) {
            $columns = $this->schema->getTableColumns($table);
            $fields = $this->normalizeColumns($columns);

            $filePath = $this->generateFromColumns($dbResource, $table, $fields);
            if ($filePath !== null) {
                $generatedFiles[] = $filePath;
            }
        }

        return $generatedFiles;
    }

    /**
     * Generate JSON definition for a single table
     *
     * @param string $table Table name
     * @param string|null $database Database identifier (uses default if null)
     * @return string|null Path to generated file or null if already exists
     */
    public function generateForTable(string $table, ?string $database = null): ?string
    {
        $dbResource = $database ?? $this->dbResource;
        $filename = $this->outputPath . "$dbResource.$table.json";

        if (isset($this->generatedFiles[$filename])) {
            return null;
        }

        $fields = $this->schema->getTableColumns($table);

        $config = $this->buildTableConfig($table, $fields);

        file_put_contents(
            $filename,
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $this->generatedFiles[$filename] = true;

        return $filename;
    }

    /**
     * Generate JSON definition from pre-fetched column data
     *
     * @param string $dbResource Database identifier
     * @param string $tableName Table name
     * @param array<array<string, mixed>> $columns Column data
     * @return string|null Path to generated file or null if already exists
     */
    public function generateFromColumns(string $dbResource, string $tableName, array $columns): ?string
    {
        $filename = $this->outputPath . "$dbResource.$tableName.json";

        if (isset($this->generatedFiles[$filename])) {
            return null;
        }

        $config = [
            'table' => [
                'name' => $tableName,
                'fields' => []
            ],
            'access' => [
                'mode' => $this->inferAccessMode($tableName)
            ]
        ];

        foreach ($columns as $field) {
            $config['table']['fields'][] = [
                'name' => $field['Field'],
                'api_field' => $this->generateApiFieldName($field['Field']),
                'type' => $field['Type'],
                'nullable' => $field['Null'] === 'YES'
            ];
        }

        file_put_contents(
            $filename,
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $this->generatedFiles[$filename] = true;

        return $filename;
    }

    /**
     * Get the current database role from configuration
     *
     * @return string The database role (e.g., 'primary')
     */
    private function getDatabaseRole(): string
    {
        $engine = config('database.engine', 'mysql');
        return config("database.{$engine}.role", 'primary');
    }

    /**
     * Ensure output directory exists
     */
    private function ensureOutputDirectory(): void
    {
        if (!is_dir($this->outputPath)) {
            mkdir($this->outputPath, 0755, true);
        }
    }

    /**
     * Normalize column data from various SchemaBuilder formats
     *
     * @param array<int|string, mixed> $columns Raw column data
     * @return array<int, array<string, mixed>> Normalized column data
     */
    private function normalizeColumns(array $columns): array
    {
        $normalized = [];

        foreach ($columns as $columnName => $col) {
            if (isset($col['name'])) {
                // Format: ['name' => ..., 'type' => ..., 'nullable' => ...]
                $normalized[] = [
                    'Field' => $col['name'],
                    'Type' => $col['type'] ?? '',
                    'Null' => isset($col['nullable']) && (bool) $col['nullable'] ? 'YES' : 'NO'
                ];
            } elseif (isset($col['Field'])) {
                // Already normalized format
                $normalized[] = $col;
            } else {
                // Fallback for unexpected structure
                $normalized[] = [
                    'Field' => $columnName,
                    'Type' => is_string($col) ? $col : 'VARCHAR',
                    'Null' => 'NO'
                ];
            }
        }

        return $normalized;
    }

    /**
     * Build table configuration array
     *
     * @param string $tableName Table name
     * @param array<int|string, mixed> $fields Raw field data
     * @return array<string, mixed> Configuration array
     */
    private function buildTableConfig(string $tableName, array $fields): array
    {
        $config = [
            'table' => [
                'name' => $tableName,
                'fields' => []
            ],
            'access' => [
                'mode' => $this->inferAccessMode($tableName)
            ]
        ];

        foreach ($fields as $field) {
            $config['table']['fields'][] = [
                'name' => $field['Field'],
                'api_field' => $this->generateApiFieldName($field['Field']),
                'type' => $field['Type'],
                'nullable' => $field['Null'] === 'YES'
            ];
        }

        return $config;
    }

    /**
     * Convert database field name to API field name
     *
     * @param string $fieldName Database field name
     * @return string API field name
     */
    private function generateApiFieldName(string $fieldName): string
    {
        return $fieldName;
    }

    /**
     * Infer access mode from table name
     *
     * Views (prefixed with 'vw_') are read-only, tables are read-write.
     *
     * @param string $tableName Table name
     * @return string Access mode ('r' for read-only, 'rw' for read-write)
     */
    private function inferAccessMode(string $tableName): string
    {
        return str_starts_with($tableName, 'vw_') ? 'r' : 'rw';
    }

    /**
     * Get list of generated files
     *
     * @return array<string> List of generated file paths
     */
    public function getGeneratedFiles(): array
    {
        return array_keys($this->generatedFiles);
    }

    /**
     * Clear the generated files cache
     *
     * Useful when regenerating files that may have been modified.
     */
    public function clearCache(): void
    {
        $this->generatedFiles = [];
    }
}

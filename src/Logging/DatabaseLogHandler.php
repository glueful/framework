<?php

declare(strict_types=1);

namespace Glueful\Logging;

use Monolog\LogRecord;
use Monolog\Level;
use Monolog\Handler\AbstractProcessingHandler;
use Glueful\Helpers\Utils;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Database\Connection;

/**
 * Database Log Handler
 *
 * Handles writing log records to a database table. Extends Monolog's AbstractProcessingHandler
 * to provide database logging capabilities. Logs are stored in the 'activity_logs' table with:
 * - Unique identifiers
 * - Log levels
 * - Timestamps
 * - Message content
 * - Contextual data
 * - Execution timing
 * - Channel information
 * - Structured audit fields (action, actor_id, resource_type, resource_id)
 *
 * @package Glueful\Logging
 */
class DatabaseLogHandler extends AbstractProcessingHandler
{
    private SchemaBuilderInterface $schema;
    private Connection $db;
    protected string $table = 'activity_logs';

    /**
     * Initialize database log handler
     *
     * Sets up the handler with specified log level and establishes
     * database connection through SchemaBuilder.
     *
     * @param array<string, mixed> $options Minimum logging level (defaults to DEBUG)
     */
    public function __construct(array $options = [])
    {
        parent::__construct($options['level'] ?? Level::Debug);
        $connection = new Connection();
        $this->schema = $connection->getSchemaBuilder();
        $this->db = $connection;

        // Ensure logs table exists
        if (isset($options['table'])) {
            $this->table = $options['table'];
        }
        $this->ensureLogsTable();
    }


    /**
     * Set the database table name for storing log entries
     *
     * @param string $table Table name
     * @return self
     */
    public function setTable(string $table): self
    {
        $this->table = $table;
        return $this;
    }


    /**
     * Write log record to database
     *
     * Processes the log record and stores it in the activity_logs table:
     * - Generates unique UUID for the log entry
     * - Formats datetime to MySQL compatible format
     * - Serializes context data to JSON
     * - Extracts execution time if available
     * - Extracts structured audit fields from context
     * - Handles potential database errors
     *
     * @param LogRecord $record Log record to write
     * @throws \PDOException If database write fails
     */
    protected function write(LogRecord $record): void
    {
        try {
            // Extract structured audit fields from context
            $action = $this->extractContextValue($record->context, ['action', 'event', 'operation']);
            $actorId = $this->extractContextValue($record->context, ['actor_id', 'user_id', 'user_uuid']);
            $resourceType = $this->extractContextValue($record->context, ['resource_type', 'entity_type', 'table']);
            $resourceId = $this->extractContextValue($record->context, ['resource_id', 'entity_id', 'id', 'uuid']);

            // Insert log entry using fluent QueryBuilder
            $this->db->table($this->table)->insert([
                'uuid' => Utils::generateNanoID(),
                'channel' => $record->channel,
                'level' => $record->level->name,
                'message' => $record->message,
                'context' => json_encode(
                    $record->context,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                ),
                'exec_time' => $record->context['exec_time'] ?? null,
                'action' => $action,
                'actor_id' => $actorId,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'created_at' => $record->datetime->format('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            error_log("Failed to write log to database: " . $e->getMessage());
        }
    }

    /**
     * Extract a value from context using multiple possible keys
     *
     * @param array<string, mixed> $context Log context
     * @param array<string> $keys Possible keys to look for (in priority order)
     * @return string|null First found value or null
     */
    private function extractContextValue(array $context, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($context[$key])) {
                $value = $context[$key];
                if (is_scalar($value) && $value !== '') {
                    return (string) $value;
                }
            }
        }
        return null;
    }

    private function ensureLogsTable(): void
    {
        if (!$this->schema->hasTable($this->table)) {
            $table = $this->schema->table($this->table);

            // Core columns
            $table->bigInteger('id')->unsigned()->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('level', 20);
            $table->text('message');
            $table->json('context')->nullable();
            $table->decimal('exec_time', 10, 4)->nullable();
            $table->string('batch_uuid', 12)->nullable();
            $table->string('channel', 255);

            // Structured audit columns
            $table->string('action', 50)->nullable();
            $table->string('actor_id', 12)->nullable();
            $table->string('resource_type', 100)->nullable();
            $table->string('resource_id', 100)->nullable();

            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');

            // Add indexes
            $table->unique('uuid');
            $table->index('batch_uuid');
            $table->index('level');
            $table->index('channel');
            $table->index('created_at');

            // Structured audit indexes
            $table->index('action');
            $table->index('actor_id');
            $table->index('resource_type');
            $table->index(['actor_id', 'action']);
            $table->index(['resource_type', 'resource_id']);
            $table->index(['channel', 'action']);

            // Create the table
            $table->create();

            // Execute the operation
            $this->schema->execute();
        }
    }
}

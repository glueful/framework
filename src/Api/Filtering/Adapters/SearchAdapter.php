<?php

declare(strict_types=1);

namespace Glueful\Api\Filtering\Adapters;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Api\Filtering\Contracts\SearchAdapterInterface;
use Glueful\Api\Filtering\SearchResult;
use Glueful\Database\Connection;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Base search adapter with auto-migration for index tracking
 *
 * Provides common functionality for search adapters including:
 * - Auto-migration for search_index_log table (created on first index operation)
 * - Index tracking for change detection and incremental re-indexing
 * - Common configuration handling
 *
 * Following the pattern from DatabaseLogHandler, the tracking table is created
 * automatically at runtime when first needed.
 */
abstract class SearchAdapter implements SearchAdapterInterface
{
    protected SchemaBuilderInterface $schema;
    protected Connection $db;
    protected bool $tableEnsured = false;
    protected string $indexName;
    protected ?ApplicationContext $context;

    /**
     * @param string $indexName The name of the search index
     * @param Connection|null $connection Optional database connection
     * @param ApplicationContext|null $context Application context
     */
    public function __construct(
        string $indexName,
        ?Connection $connection = null,
        ?ApplicationContext $context = null
    ) {
        $this->indexName = $indexName;
        $this->context = $context;
        $this->db = $connection ?? Connection::fromContext($context);
        $this->schema = $this->db->getSchemaBuilder();
    }

    /**
     * {@inheritdoc}
     */
    abstract public function search(string $query, array $options = []): SearchResult;

    /**
     * {@inheritdoc}
     */
    public function index(string $id, array $document): void
    {
        $this->ensureTable();
        $this->doIndex($id, $document);
        $this->logIndexed($id, $document);
    }

    /**
     * {@inheritdoc}
     */
    public function update(string $id, array $document): void
    {
        $this->ensureTable();
        $this->doUpdate($id, $document);
        $this->logIndexed($id, $document);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $id): void
    {
        $this->ensureTable();
        $this->doDelete($id);
        $this->removeFromLog($id);
    }

    /**
     * {@inheritdoc}
     */
    public function bulkIndex(array $documents): void
    {
        $this->ensureTable();
        $this->doBulkIndex($documents);

        foreach ($documents as $id => $document) {
            $this->logIndexed((string) $id, $document);
        }
    }

    /**
     * Get the index name
     */
    public function getIndexName(): string
    {
        return $this->indexName;
    }

    /**
     * Perform the actual index operation
     *
     * @param string $id Document identifier
     * @param array<string, mixed> $document Document data
     */
    abstract protected function doIndex(string $id, array $document): void;

    /**
     * Perform the actual update operation
     *
     * @param string $id Document identifier
     * @param array<string, mixed> $document Updated document data
     */
    abstract protected function doUpdate(string $id, array $document): void;

    /**
     * Perform the actual delete operation
     *
     * @param string $id Document identifier
     */
    abstract protected function doDelete(string $id): void;

    /**
     * Perform the actual bulk index operation
     *
     * @param array<string, array<string, mixed>> $documents Map of ID => document data
     */
    protected function doBulkIndex(array $documents): void
    {
        foreach ($documents as $id => $document) {
            $this->doIndex((string) $id, $document);
        }
    }

    /**
     * Ensure search_index_log table exists
     *
     * Following the pattern from DatabaseLogHandler, the table is created
     * automatically at runtime when first needed for index tracking.
     */
    protected function ensureTable(): void
    {
        if ($this->tableEnsured) {
            return;
        }

        if (!$this->schema->hasTable('search_index_log')) {
            $table = $this->schema->table('search_index_log');

            // Define columns
            $table->bigInteger('id')->unsigned()->primary()->autoIncrement();
            $table->string('indexable_type', 255);    // Model class name
            $table->string('indexable_id', 36);       // Model ID (UUID or int)
            $table->string('index_name', 255);        // Search engine index name
            $table->timestamp('indexed_at')->default('CURRENT_TIMESTAMP');
            $table->string('checksum', 64)->nullable(); // For change detection

            // Composite unique index for upsert operations
            $table->unique(['indexable_type', 'indexable_id', 'index_name']);
            $table->index('indexed_at');
            $table->index('index_name');

            // Create the table
            $table->create();
            $this->schema->execute();
        }

        $this->tableEnsured = true;
    }

    /**
     * Log that a record has been indexed
     *
     * @param string $id Document identifier
     * @param array<string, mixed> $document Document data
     */
    protected function logIndexed(string $id, array $document): void
    {
        $type = $document['_type'] ?? 'document';

        // Check if record exists
        $existing = $this->db->table('search_index_log')
            ->where('indexable_type', $type)
            ->where('indexable_id', $id)
            ->where('index_name', $this->indexName)
            ->first();

        $data = [
            'indexable_type' => $type,
            'indexable_id' => $id,
            'index_name' => $this->indexName,
            'indexed_at' => date('Y-m-d H:i:s'),
            'checksum' => $this->calculateChecksum($document),
        ];

        if ($existing !== null) {
            $this->db->table('search_index_log')
                ->where('indexable_type', $type)
                ->where('indexable_id', $id)
                ->where('index_name', $this->indexName)
                ->update($data);
        } else {
            $this->db->table('search_index_log')->insert($data);
        }
    }

    /**
     * Remove a record from the index log
     *
     * @param string $id Document identifier
     */
    protected function removeFromLog(string $id): void
    {
        $this->db->table('search_index_log')
            ->where('indexable_id', $id)
            ->where('index_name', $this->indexName)
            ->delete();
    }

    /**
     * Calculate checksum for change detection
     *
     * @param array<string, mixed> $document
     */
    protected function calculateChecksum(array $document): string
    {
        // Remove internal fields before checksum
        $data = $document;
        unset($data['_type'], $data['_id'], $data['_index']);

        return md5(json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * Check if a document has changed since last index
     *
     * @param string $id Document identifier
     * @param array<string, mixed> $document Document data
     */
    public function hasChanged(string $id, array $document): bool
    {
        $this->ensureTable();

        $existing = $this->db->table('search_index_log')
            ->where('indexable_id', $id)
            ->where('index_name', $this->indexName)
            ->first();

        if ($existing === null) {
            return true;
        }

        $currentChecksum = $this->calculateChecksum($document);

        return ($existing['checksum'] ?? '') !== $currentChecksum;
    }

    /**
     * Get records that need re-indexing (indexed before given date)
     *
     * @param \DateTimeInterface $before
     * @return array<int, array<string, mixed>>
     */
    public function getStaleRecords(\DateTimeInterface $before): array
    {
        $this->ensureTable();

        return $this->db->table('search_index_log')
            ->where('index_name', $this->indexName)
            ->where('indexed_at', '<', $before->format('Y-m-d H:i:s'))
            ->get();
    }

    /**
     * Get search configuration
     *
     * @return array<string, mixed>
     */
    protected function getConfig(): array
    {
        if (function_exists('config') && $this->context !== null) {
            return (array) config($this->context, 'api.filtering.search', []);
        }

        return [];
    }

    /**
     * Get the schema builder (for testing)
     */
    public function getSchema(): SchemaBuilderInterface
    {
        return $this->schema;
    }

    /**
     * Check if table has been ensured
     */
    public function isTableEnsured(): bool
    {
        return $this->tableEnsured;
    }

    /**
     * Force table check on next operation
     */
    public function resetTableEnsured(): void
    {
        $this->tableEnsured = false;
    }
}

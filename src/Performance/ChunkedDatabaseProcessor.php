<?php

namespace Glueful\Performance;

/**
 * A utility class for processing database results in a memory-efficient way
 *
 * Supports PDO and mysqli connection types for chunked processing of large datasets.
 */
class ChunkedDatabaseProcessor
{
    private mixed $connection;
    private int $defaultChunkSize;

    /**
     * Create a new chunked database processor
     *
     * @param mixed $connection The database connection (PDO or mysqli)
     * @param int $defaultChunkSize Default chunk size for operations
     */
    public function __construct($connection, int $defaultChunkSize = 1000)
    {
        $this->connection = $connection;
        $this->defaultChunkSize = $defaultChunkSize;
    }

    /**
     * Execute a select query and process results in chunks to reduce memory usage
     *
     * @param string $query The SQL query to execute
     * @param callable $processor Function to process each row
     * @param array<int|string, mixed> $params Parameters for the prepared statement
     * @param int|null $chunkSize Size of each chunk (null for default)
     * @return array<mixed> Results from the processor for each chunk
     */
    public function processSelectQuery(
        string $query,
        callable $processor,
        array $params = [],
        ?int $chunkSize = null
    ): array {
        $chunkSize = $chunkSize ?? $this->defaultChunkSize;

        // Adapt based on the connection type
        if ($this->connection instanceof \PDO) {
            return $this->processPdoQuery($query, $processor, $params, $chunkSize);
        } elseif ($this->connection instanceof \mysqli) {
            return $this->processMysqliQuery($query, $processor, $params, $chunkSize);
        } else {
            throw new \InvalidArgumentException('Unsupported connection type. Only PDO and mysqli are supported.');
        }
    }

    /**
     * Process a PDO query in chunks
     *
     * @param string $query The SQL query to execute
     * @param callable $processor Function to process each chunk
     * @param array<int|string, mixed> $params Parameters for the prepared statement
     * @param int $chunkSize Size of each chunk
     * @return array<mixed> Results from the processor for each chunk
     */
    private function processPdoQuery(string $query, callable $processor, array $params, int $chunkSize): array
    {
        $stmt = $this->connection->prepare($query);
        $stmt->execute($params);

        return MemoryEfficientIterators::processInChunks(
            MemoryEfficientIterators::databaseResults($stmt),
            $processor,
            $chunkSize
        );
    }

    /**
     * Process a mysqli query in chunks
     *
     * @param string $query The SQL query to execute
     * @param callable $processor Function to process each chunk
     * @param array<int|string, mixed> $params Parameters for the prepared statement
     * @param int $chunkSize Size of each chunk
     * @return array<mixed> Results from the processor for each chunk
     */
    private function processMysqliQuery(string $query, callable $processor, array $params, int $chunkSize): array
    {
        $stmt = $this->connection->prepare($query);

        if ($params !== []) {
            $types = '';
            $bindParams = [];

            foreach ($params as $param) {
                if (is_int($param)) {
                    $types .= 'i';
                } elseif (is_float($param)) {
                    $types .= 'd';
                } elseif (is_string($param)) {
                    $types .= 's';
                } else {
                    $types .= 'b';
                }
                $bindParams[] = $param;
            }

            $stmt->bind_param($types, ...$bindParams);
        }

        $stmt->execute();

        return MemoryEfficientIterators::processInChunks(
            MemoryEfficientIterators::databaseResults($stmt),
            $processor,
            $chunkSize
        );
    }


    /**
     * Process a large table in chunks using an ID-based approach
     *
     * @param string $table The table name to process
     * @param callable $processor Function to process each chunk of rows
     * @param string $idColumn The primary key column (default: 'id')
     * @param array<string, mixed> $conditions Additional WHERE conditions
     * @param int|null $chunkSize Size of each chunk (null for default)
     * @return array<mixed> Results from the processor for each chunk
     */
    public function processTableInChunks(
        string $table,
        callable $processor,
        string $idColumn = 'id',
        array $conditions = [],
        ?int $chunkSize = null
    ): array {
        $chunkSize = $chunkSize ?? $this->defaultChunkSize;
        $results = [];

        // For Laravel connections, use the chunkById method
        if (method_exists($this->connection, 'table')) {
            $query = $this->connection->table($table);

            foreach ($conditions as $column => $value) {
                $query->where($column, $value);
            }

            $query->chunkById($chunkSize, function ($chunk) use ($processor, &$results) {
                $results[] = $processor($chunk->toArray());
            }, $idColumn);

            return $results;
        }

        // For other connections, implement manual chunking
        $lastId = 0;
        $done = false;

        while (!$done) {
            // Build the query with conditions
            $sql = "SELECT * FROM {$table} WHERE {$idColumn} > ? ";
            $params = [$lastId];

            foreach ($conditions as $column => $value) {
                $sql .= "AND {$column} = ? ";
                $params[] = $value;
            }

            $sql .= "ORDER BY {$idColumn} ASC LIMIT {$chunkSize}";

            // Execute the query
            if ($this->connection instanceof \PDO) {
                $stmt = $this->connection->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            } elseif ($this->connection instanceof \mysqli) {
                $stmt = $this->connection->prepare($sql);

                $types = '';
                foreach ($params as $param) {
                    if (is_int($param)) {
                        $types .= 'i';
                    } elseif (is_float($param)) {
                        $types .= 'd';
                    } elseif (is_string($param)) {
                        $types .= 's';
                    } else {
                        $types .= 'b';
                    }
                }

                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $result = $stmt->get_result();
                $rows = $result->fetch_all(MYSQLI_ASSOC);
            } else {
                throw new \InvalidArgumentException('Unsupported connection type');
            }

            // Process the chunk
            if (count($rows) > 0) {
                $results[] = $processor($rows);
                $lastId = end($rows)[$idColumn];
            }

            // Check if we're done
            if (count($rows) < $chunkSize) {
                $done = true;
            }
        }

        return $results;
    }
}

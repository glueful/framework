<?php

namespace Glueful\Performance;

use Glueful\Database\Connection;

/**
 * A utility class for processing database results in a memory-efficient way
 *
 * Supports Glueful Connection, PDO and mysqli connection types for chunked
 * processing of large datasets.
 */
class ChunkedDatabaseProcessor
{
    private Connection|\PDO|\mysqli $connection;
    private int $defaultChunkSize;

    /**
     * Create a new chunked database processor
     *
     * @param Connection|\PDO|\mysqli $connection The database connection
     * @param int $defaultChunkSize Default chunk size for operations
     */
    public function __construct(Connection|\PDO|\mysqli $connection, int $defaultChunkSize = 1000)
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
        if ($this->connection instanceof \mysqli) {
            return $this->processMysqliQuery($this->connection, $query, $processor, $params, $chunkSize);
        }

        $pdo = $this->connection instanceof Connection ? $this->connection->getPDO() : $this->connection;

        return $this->processPdoQuery($pdo, $query, $processor, $params, $chunkSize);
    }

    /**
     * Process a PDO query in chunks
     *
     * @param \PDO $pdo The PDO handle
     * @param string $query The SQL query to execute
     * @param callable $processor Function to process each chunk
     * @param array<int|string, mixed> $params Parameters for the prepared statement
     * @param int $chunkSize Size of each chunk
     * @return array<mixed> Results from the processor for each chunk
     */
    private function processPdoQuery(
        \PDO $pdo,
        string $query,
        callable $processor,
        array $params,
        int $chunkSize
    ): array {
        $stmt = $pdo->prepare($query);
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
     * @param \mysqli $mysqli The mysqli handle
     * @param string $query The SQL query to execute
     * @param callable $processor Function to process each chunk
     * @param array<int|string, mixed> $params Parameters for the prepared statement
     * @param int $chunkSize Size of each chunk
     * @return array<mixed> Results from the processor for each chunk
     */
    private function processMysqliQuery(
        \mysqli $mysqli,
        string $query,
        callable $processor,
        array $params,
        int $chunkSize
    ): array {
        $stmt = $mysqli->prepare($query);
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare statement: ' . $mysqli->error);
        }

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

        if ($this->connection instanceof Connection) {
            return $this->processConnectionTableInChunks($table, $processor, $idColumn, $conditions, $chunkSize);
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
                if ($stmt === false) {
                    throw new \RuntimeException('Failed to prepare statement: ' . $this->connection->error);
                }

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
                if ($result === false) {
                    throw new \RuntimeException('Failed to get result set: ' . $this->connection->error);
                }
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

    /**
     * Keyset-paginated chunking through the Glueful query builder (table hooks,
     * identifier validation and typed exceptions all apply).
     *
     * @param array<string, mixed> $conditions
     * @return array<mixed>
     */
    private function processConnectionTableInChunks(
        string $table,
        callable $processor,
        string $idColumn,
        array $conditions,
        int $chunkSize
    ): array {
        /** @var Connection $connection */
        $connection = $this->connection;
        $results = [];
        $lastId = null;

        do {
            $query = $connection->table($table);
            foreach ($conditions as $column => $value) {
                $query->where($column, $value);
            }
            if ($lastId !== null) {
                $query->where($idColumn, '>', $lastId);
            }
            $rows = $query->orderBy($idColumn, 'ASC')->limit($chunkSize)->get();

            if ($rows !== []) {
                $results[] = $processor($rows);
                $lastId = $rows[count($rows) - 1][$idColumn] ?? null;
                if ($lastId === null) {
                    throw new \InvalidArgumentException(
                        "Chunking column '{$idColumn}' is missing from the rows of '{$table}'."
                    );
                }
            }
        } while (count($rows) === $chunkSize);

        return $results;
    }
}

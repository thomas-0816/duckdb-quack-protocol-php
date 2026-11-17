<?php

namespace DuckDB\Quack;

use Closure;
use DuckDB\Quack\Chunk\DataChunk;
use DuckDB\Quack\Connection\ClientConnection;
use DuckDB\Quack\Error\QuackError;
use DuckDB\Quack\Exceptions\ProtocolException;
use DuckDB\Quack\Messages\FetchResponseMessage;
use DuckDB\Quack\Messages\PrepareResponseMessage;
use DuckDB\Quack\Sql\SqlFormatter;
use DuckDB\Quack\Sql\SqlParameters;
use DuckDB\Quack\Transport\Transport;
use Generator;
use Throwable;

/**
 * One session on a Quack server.
 *
 * A client maps to exactly one server-side connection id. The server runs one
 * statement per connection and a new PREPARE supersedes the current result
 * stream, so finish or discard a stream before sending another query.
 * Concurrent work needs separate clients or separate processes.
 */
final class QuackClient
{
    public function __construct(
        private readonly ClientConnection $connection,
    ) {
    }

    public function __destruct()
    {
        $this->connection->disconnect();
    }

    /**
     * Opens a session, negotiating a supported Quack protocol version.
     *
     * @param array<string,scalar|null> $configuration
     *
     * @throws QuackError if the connection cannot be established or a setting is rejected.
     */
    public static function connect(
        string $uri,
        string $authToken,
        int $httpConnectTimeout,
        int $httpTimeout,
        array $configuration = [],
        ?string $sslCertificate = null,
    ): self {
        $settings = [];
        foreach ($configuration as $name => $value) {
            $literal = match (true) {
                $value === null => 'NULL',
                is_bool($value) => $value ? 'TRUE' : 'FALSE',
                is_int($value) => $value,
                is_float($value) => (string) $value,
                default => "'" . str_replace("'", "''", $value) . "'",
            };
            $settings[] = 'SET ' . $name . ' = ' . $literal . ';';
        }
        $transport = Transport::new($uri, $authToken, $httpConnectTimeout, $httpTimeout, $sslCertificate);
        $connection = ClientConnection::connect($transport);

        $client = new self($connection);
        if ($settings !== []) {
            $client->execute(implode('', $settings));
        }

        return $client;
    }

    public function getConnection(): ClientConnection
    {
        return $this->connection;
    }

    /**
     * Run a statement and return the number of changed rows.
     *
     * @param array<int|string,mixed> $params
     */
    public function execute(string $sql, array $params = []): int
    {
        if ($this->connection->isClosed()) {
            throw new ProtocolException('Quack client is not connected');
        }
        $query = SqlFormatter::formatSql($sql, SqlParameters::coerce($params));
        // Changed-row results have a single Count column, so only the first
        // value matters. Returning early is safe because the next PREPARE
        // resets the server's result cursor.
        foreach ($this->fetchChunks($this->connection, $query) as $chunk) {
            if (($chunk->columnNames[0] ?? null) !== 'Count') {
                return 0;
            }
            $count = $chunk->columns[0]->values[0] ?? 0;
            return is_int($count) ? $count : 0;
        }
        return 0;
    }

    /**
     * Starts a transaction on the server-side connection.
     */
    public function beginTransaction(): void
    {
        $this->execute('BEGIN TRANSACTION');
    }

    /**
     * Commits the active transaction.
     */
    public function commit(): void
    {
        $this->execute('COMMIT');
    }

    /**
     * Rolls back the active transaction.
     */
    public function rollback(): void
    {
        $this->execute('ROLLBACK');
    }

    /**
     * Runs the callback in a transaction, committing on success and rolling
     * back when it throws. The callback receives this client.
     *
     * @param Closure(self):mixed $callback
     */
    public function transaction(Closure $callback): mixed
    {
        $this->execute('BEGIN TRANSACTION');
        try {
            $result = $callback($this);
        } catch (Throwable $e) {
            try {
                $this->execute('ROLLBACK');
            } catch (Throwable) {
                // Keep the callback failure; the connection may already be unusable.
            }
            throw $e;
        }
        $this->execute('COMMIT');
        return $result;
    }

    /**
     * Runs a statement and returns all rows (associative).
     *
     * @param array<int|string,mixed> $params
     * @return list<array<string,mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        if ($this->connection->isClosed()) {
            throw new ProtocolException('Quack client is not connected');
        }
        $query = SqlFormatter::formatSql($sql, SqlParameters::coerce($params));
        $rows = [];
        foreach ($this->fetchChunks($this->connection, $query) as $chunk) {
            $names = $chunk->columnNames;
            for ($rowIndex = 0; $rowIndex < $chunk->rowCount; $rowIndex++) {
                $row = [];
                foreach ($chunk->columns as $columnIndex => $column) {
                    $name = $names[$columnIndex] ?? "column{$columnIndex}";
                    $row[$name] = $column->values[$rowIndex] ?? null;
                }
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * Runs a statement and returns all rows (numeric).
     *
     * @param array<int|string,mixed> $params
     * @return list<array<int,mixed>>
     */
    public function fetchAllNumeric(string $sql, array $params = []): array
    {
        if ($this->connection->isClosed()) {
            throw new ProtocolException('Quack client is not connected');
        }
        $query = SqlFormatter::formatSql($sql, SqlParameters::coerce($params));
        $rows = [];
        foreach ($this->fetchChunks($this->connection, $query) as $chunk) {
            for ($rowIndex = 0; $rowIndex < $chunk->rowCount; $rowIndex++) {
                $row = [];
                foreach ($chunk->columns as $columnIndex => $column) {
                    $row[$columnIndex] = $column->values[$rowIndex] ?? null;
                }
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * Runs a statement and returns its second column keyed by its first column.
     *
     * @param array<int|string,mixed> $params
     * @return array<int|string,mixed>
     */
    public function fetchAllKeyValue(string $sql, array $params = []): array
    {
        if ($this->connection->isClosed()) {
            throw new ProtocolException('Quack client is not connected');
        }
        $query = SqlFormatter::formatSql($sql, SqlParameters::coerce($params));
        $result = [];
        foreach ($this->fetchChunks($this->connection, $query) as $chunk) {
            $keys = $chunk->columns[0]->values ?? [];
            $values = $chunk->columns[1]->values ?? [];
            foreach ($keys as $rowIndex => $phpKey) {
                if (!is_int($phpKey) && !is_string($phpKey)) {
                    throw new ProtocolException('key column must contain integers or strings');
                }
                $result[$phpKey] = $values[$rowIndex] ?? null;
            }
        }
        return $result;
    }

    /**
     * Runs a statement and returns its first row (associative), or null when it has no rows.
     *
     * @param array<int|string,mixed> $params
     * @return array<string,mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        if ($this->connection->isClosed()) {
            throw new ProtocolException('Quack client is not connected');
        }
        $query = SqlFormatter::formatSql($sql, SqlParameters::coerce($params));
        foreach ($this->fetchChunks($this->connection, $query) as $chunk) {
            $names = $chunk->columnNames;
            for ($rowIndex = 0; $rowIndex < $chunk->rowCount; $rowIndex++) {
                $row = [];
                foreach ($chunk->columns as $columnIndex => $column) {
                    $name = $names[$columnIndex] ?? "column{$columnIndex}";
                    $row[$name] = $column->values[$rowIndex] ?? null;
                }
                return $row;
            }
        }
        return null;
    }

    /**
     * Runs a statement and returns its first row (numeric), or null when it has no rows.
     *
     * @param array<int|string,mixed> $params
     * @return array<int,mixed>|null
     */
    public function fetchOneNumeric(string $sql, array $params = []): ?array
    {
        if ($this->connection->isClosed()) {
            throw new ProtocolException('Quack client is not connected');
        }
        $query = SqlFormatter::formatSql($sql, SqlParameters::coerce($params));
        foreach ($this->fetchChunks($this->connection, $query) as $chunk) {
            for ($rowIndex = 0; $rowIndex < $chunk->rowCount; $rowIndex++) {
                $row = [];
                foreach ($chunk->columns as $columnIndex => $column) {
                    $row[$columnIndex] = $column->values[$rowIndex] ?? null;
                }
                return $row;
            }
        }
        return null;
    }

    /**
     * Runs a statement and yields its rows (associative).
     *
     * @param array<int|string,mixed> $params
     * @return Generator<int,array<string,mixed>>
     */
    public function fetchIterator(string $sql, array $params = []): Generator
    {
        if ($this->connection->isClosed()) {
            throw new ProtocolException('Quack client is not connected');
        }
        $query = SqlFormatter::formatSql($sql, SqlParameters::coerce($params));
        foreach ($this->fetchChunks($this->connection, $query) as $chunk) {
            $names = $chunk->columnNames;
            for ($rowIndex = 0; $rowIndex < $chunk->rowCount; $rowIndex++) {
                $row = [];
                foreach ($chunk->columns as $columnIndex => $column) {
                    $name = $names[$columnIndex] ?? "column{$columnIndex}";
                    $row[$name] = $column->values[$rowIndex] ?? null;
                }
                yield $row;
            }
        }
    }

    /**
     * Runs a statement and yields its rows (numeric).
     *
     * @param array<int|string,mixed> $params
     * @return Generator<int,array<int,mixed>>
     */
    public function fetchIteratorNumeric(string $sql, array $params = []): Generator
    {
        if ($this->connection->isClosed()) {
            throw new ProtocolException('Quack client is not connected');
        }
        $query = SqlFormatter::formatSql($sql, SqlParameters::coerce($params));
        foreach ($this->fetchChunks($this->connection, $query) as $chunk) {
            for ($rowIndex = 0; $rowIndex < $chunk->rowCount; $rowIndex++) {
                $row = [];
                foreach ($chunk->columns as $columnIndex => $column) {
                    $row[$columnIndex] = $column->values[$rowIndex] ?? null;
                }
                yield $row;
            }
        }
    }

    /**
     * Runs a statement and returns the values of its first column.
     *
     * @param array<int|string,mixed> $params
     * @return list<mixed>
     */
    public function fetchColumn(string $sql, array $params = []): array
    {
        if ($this->connection->isClosed()) {
            throw new ProtocolException('Quack client is not connected');
        }
        $query = SqlFormatter::formatSql($sql, SqlParameters::coerce($params));
        $values = [];
        foreach ($this->fetchChunks($this->connection, $query) as $chunk) {
            foreach ($chunk->columns[0]->values ?? [] as $value) {
                $values[] = $value;
            }
        }
        return $values;
    }

    /**
     * Runs a statement and returns the first value of its first column.
     *
     * @param array<int|string,mixed> $params
     */
    public function fetchValue(string $sql, array $params = []): mixed
    {
        if ($this->connection->isClosed()) {
            throw new ProtocolException('Quack client is not connected');
        }
        $query = SqlFormatter::formatSql($sql, SqlParameters::coerce($params));
        foreach ($this->fetchChunks($this->connection, $query) as $chunk) {
            foreach ($chunk->columns[0]->values ?? [] as $value) {
                return $value;
            }
        }
        return null;
    }

    /**
     * Prepares a query and streams its result chunks.
     *
     * @return Generator<int,DataChunk>
     */
    private function fetchChunks(ClientConnection $connection, string $query): Generator
    {
        $response = $connection->prepare($query, null);
        if (!$response instanceof PrepareResponseMessage) {
            throw new ProtocolException('expected PREPARE_RESPONSE, got ' . $response->messageType()->name);
        }

        $needsMoreFetch = $response->needsMoreFetch;
        $completed = false;

        try {
            foreach ($response->results as $chunk) {
                $chunk->columnNames = $response->resultNames;
                /* TODO add method to emulate getColumnMeta:
                    ["native_type"]=> "decimal"
                    ["php_type"]=> "string"
                    ["duckdb:decl_type"]=> "DECIMAL(10,2)"
                    ["name"]=> "foo"
                */
                $chunk->columnTypes = $response->resultTypes;
                yield $chunk;
            }

            $nextBatchIndex = 1;
            $ackIndex = 0;

            while ($needsMoreFetch) {
                $fetchResponse = $connection->fetch($response->resultUuid, $nextBatchIndex, $ackIndex);
                if (!$fetchResponse instanceof FetchResponseMessage) {
                    throw new ProtocolException('expected FETCH_RESPONSE, got ' . $fetchResponse->messageType()->name);
                }

                $results = $fetchResponse->results;
                $batchIndex = $fetchResponse->batchIndex;
                if ($batchIndex !== null) {
                    if ($batchIndex !== $nextBatchIndex) {
                        throw new ProtocolException("expected FETCH_RESPONSE batch {$nextBatchIndex}, got {$batchIndex}");
                    }
                    if ($results === []) {
                        throw new ProtocolException('FETCH_RESPONSE batch did not include any chunks');
                    }
                    $ackIndex = $batchIndex;
                    $nextBatchIndex++;
                } elseif ($results === []) {
                    $totalBatches = $fetchResponse->totalBatches;
                    if ($totalBatches !== null && $totalBatches !== $ackIndex) {
                        throw new ProtocolException("FETCH_RESPONSE ended after {$ackIndex} batches but announced {$totalBatches}");
                    }
                } else {
                    throw new ProtocolException('FETCH_RESPONSE with chunks did not include a batch index');
                }
                $needsMoreFetch = ($batchIndex !== null);

                foreach ($results as $chunk) {
                    $chunk->columnNames = $response->resultNames;
                    $chunk->columnTypes = $response->resultTypes;
                    yield $chunk;
                }
            }
            $completed = true;
        } finally {
            // A generator's finally also runs when its consumer abandons it
            // (an early return or break), so stop the server-side producer.
            if (!$completed && $needsMoreFetch && !$connection->isClosed()) {
                try {
                    $connection->cancel($response->resultUuid);
                } catch (Throwable) {
                    // The caller abandoned the stream; a failed cancel must not
                    // hide its result or the exception that ended the stream.
                }
            }
        }
    }
}

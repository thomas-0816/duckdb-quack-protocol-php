<?php

namespace DuckDB\Quack;

use DuckDB\Quack\Chunk\DataChunk;
use DuckDB\Quack\Connection\ClientConnection;
use DuckDB\Quack\Error\QuackError;
use DuckDB\Quack\Exceptions\ProtocolException;
use DuckDB\Quack\Messages\FetchResponseMessage;
use DuckDB\Quack\Messages\PrepareResponseMessage;
use DuckDB\Quack\Sql\SqlFormatter;
use DuckDB\Quack\Sql\SqlParameters;
use DuckDB\Quack\Transport\Transport;

/**
 * One session on a Quack server.
 *
 * A client maps to exactly one server-side connection id. Queries are
 * serialized by a busy flag: a result stream keeps the client busy until it is
 * drained or destroyed, because a PREPARE resets the server's result cursor.
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
        if ($this->connection->isBusy()) {
            // An active result stream still owns this client and will release it.
            return;
        }
        $this->connection->disconnectOnDrop();
    }

    /**
     * Open a session, negotiating a supported Quack protocol version.
     *
     * @throws QuackError if the connection cannot be established.
     */
    public static function connect(
        string $uri,
        string $authToken,
        float $connectTimeout = 10,
        float $timeout = 120,
        ?string $sslCertificate = null,
    ): self {
        $transport = Transport::new($uri, $authToken, $connectTimeout, $timeout, $sslCertificate);
        $connection = ClientConnection::connect($transport);
        return new self($connection);
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
        if ($this->connection->isBusy()) {
            throw new ProtocolException('Quack client is already running a query');
        }
        $query = SqlFormatter::formatSql($sql, SqlParameters::coerce($params));
        $this->connection->setBusy(true);
        try {
            // Changed-row results have a single Count column, so only the first
            // value matters. Returning early is safe because the next PREPARE
            // resets the server's result cursor.
            foreach ($this->fetchChunks($this->connection, $query) as $chunk) {
                if (($chunk->resultNames[0] ?? null) !== 'Count') {
                    return 0;
                }
                $count = $chunk->columns[0]->values[0] ?? 0;
                return is_int($count) ? $count : 0;
            }
            return 0;
        } finally {
            $this->connection->setBusy(false);
        }
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
     * @param \Closure(self):mixed $callback
     */
    public function transaction(\Closure $callback): mixed
    {
        $this->beginTransaction();
        try {
            $result = $callback($this);
        } catch (\Throwable $e) {
            try {
                $this->rollback();
            } catch (\Throwable) {
                // Keep the callback failure; the connection may already be unusable.
            }
            throw $e;
        }
        $this->commit();
        return $result;
    }

    /**
     * Runs a statement and returns all rows.
     *
     * @param array<int|string,mixed> $params
     * @return list<array<string,mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        if ($this->connection->isClosed()) {
            throw new ProtocolException('Quack client is not connected');
        }
        if ($this->connection->isBusy()) {
            throw new ProtocolException('Quack client is already running a query');
        }
        $query = SqlFormatter::formatSql($sql, SqlParameters::coerce($params));
        $this->connection->setBusy(true);
        try {
            $rows = [];
            foreach ($this->fetchChunks($this->connection, $query) as $chunk) {
                $names = $chunk->resultNames;
                for ($rowIndex = 0; $rowIndex < $chunk->rowCount; $rowIndex++) {
                    $result = [];
                    foreach ($chunk->columns as $columnIndex => $column) {
                        $name = $names[$columnIndex] ?? "column{$columnIndex}";
                        $result[$name] = $column->values[$rowIndex] ?? null;
                    }
                    $rows[] = $result;
                }
            }
            return $rows;
        } finally {
            $this->connection->setBusy(false);
        }
    }

    /**
     * Runs a statement and returns its first row, or null when it has no rows.
     *
     * @param array<int|string,mixed> $params
     * @return array<string,mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        if ($this->connection->isClosed()) {
            throw new ProtocolException('Quack client is not connected');
        }
        if ($this->connection->isBusy()) {
            throw new ProtocolException('Quack client is already running a query');
        }
        $query = SqlFormatter::formatSql($sql, SqlParameters::coerce($params));
        $this->connection->setBusy(true);
        try {
            foreach ($this->fetchChunks($this->connection, $query) as $chunk) {
                $names = $chunk->resultNames;
                for ($rowIndex = 0; $rowIndex < $chunk->rowCount; $rowIndex++) {
                    $result = [];
                    foreach ($chunk->columns as $columnIndex => $column) {
                        $name = $names[$columnIndex] ?? "column{$columnIndex}";
                        $result[$name] = $column->values[$rowIndex] ?? null;
                    }
                    return $result;
                }
            }
            return null;
        } finally {
            $this->connection->setBusy(false);
        }
    }

    /**
     * Runs a statement and yields its rows.
     *
     * @param array<int|string,mixed> $params
     * @return \Generator<int,array<string,mixed>>
     */
    public function fetchIterator(string $sql, array $params = []): \Generator
    {
        if ($this->connection->isClosed()) {
            throw new ProtocolException('Quack client is not connected');
        }
        if ($this->connection->isBusy()) {
            throw new ProtocolException('Quack client is already running a query');
        }
        $query = SqlFormatter::formatSql($sql, SqlParameters::coerce($params));
        $this->connection->setBusy(true);
        try {
            foreach ($this->fetchChunks($this->connection, $query) as $chunk) {
                $names = $chunk->resultNames;
                for ($rowIndex = 0; $rowIndex < $chunk->rowCount; $rowIndex++) {
                    $result = [];
                    foreach ($chunk->columns as $columnIndex => $column) {
                        $name = $names[$columnIndex] ?? "column{$columnIndex}";
                        $result[$name] = $column->values[$rowIndex] ?? null;
                    }
                    yield $result;
                }
            }
        } finally {
            $this->connection->setBusy(false);
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
        if ($this->connection->isBusy()) {
            throw new ProtocolException('Quack client is already running a query');
        }
        $query = SqlFormatter::formatSql($sql, SqlParameters::coerce($params));
        $this->connection->setBusy(true);
        try {
            $values = [];
            foreach ($this->fetchChunks($this->connection, $query) as $chunk) {
                foreach ($chunk->columns[0]->values ?? [] as $value) {
                    $values[] = $value;
                }
            }
            return $values;
        } finally {
            $this->connection->setBusy(false);
        }
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
        if ($this->connection->isBusy()) {
            throw new ProtocolException('Quack client is already running a query');
        }
        $query = SqlFormatter::formatSql($sql, SqlParameters::coerce($params));
        $this->connection->setBusy(true);
        try {
            foreach ($this->fetchChunks($this->connection, $query) as $chunk) {
                foreach ($chunk->columns[0]->values ?? [] as $value) {
                    return $value;
                }
            }
            return null;
        } finally {
            $this->connection->setBusy(false);
        }
    }

    /**
     * Runs a statement and returns its second column keyed by its first column.
     *
     * @param array<int|string,mixed> $params
     * @return array<int|string,mixed>
     */
    public function fetchKeyValue(string $sql, array $params = []): array
    {
        if ($this->connection->isClosed()) {
            throw new ProtocolException('Quack client is not connected');
        }
        if ($this->connection->isBusy()) {
            throw new ProtocolException('Quack client is already running a query');
        }
        $query = SqlFormatter::formatSql($sql, SqlParameters::coerce($params));
        $this->connection->setBusy(true);
        try {
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
        } finally {
            $this->connection->setBusy(false);
        }
    }

    /**
     * Prepares a query and streams its result chunks.
     *
     * @return \Generator<int,DataChunk>
     */
    private function fetchChunks(ClientConnection $connection, string $query): \Generator
    {
        $response = $connection->prepare($query);
        if (!$response instanceof PrepareResponseMessage) {
            throw $connection->protocolFailure('expected PREPARE_RESPONSE, got ' . $response->messageType()->name);
        }

        $needsMoreFetch = $response->needsMoreFetch;
        $nextBatchIndex = 1;
        $ackIndex = 0;
        $firstChunk = $response->results[0] ?? null;
        $resultTypes = $firstChunk->types ?? [];
        $resultNames = $firstChunk->resultNames ?? [];

        foreach ($response->results as $chunk) {
            yield $chunk;
        }

        while ($needsMoreFetch) {
            $fetchResponse = $connection->fetch(
                $response->resultUuid,
                $nextBatchIndex,
                $ackIndex,
                $resultTypes,
                $resultNames,
            );
            if (!$fetchResponse instanceof FetchResponseMessage) {
                throw $connection->protocolFailure('expected FETCH_RESPONSE, got ' . $fetchResponse->messageType()->name);
            }

            $results = $fetchResponse->results;
            $batchIndex = $fetchResponse->batchIndex;
            if ($batchIndex !== null) {
                if ($batchIndex !== $nextBatchIndex) {
                    throw $connection->protocolFailure("expected FETCH_RESPONSE batch {$nextBatchIndex}, got {$batchIndex}");
                }
                if ($results === []) {
                    throw $connection->protocolFailure('FETCH_RESPONSE batch did not include any chunks');
                }
                $ackIndex = $batchIndex;
                $nextBatchIndex++;
            } elseif ($results === []) {
                $totalBatches = $fetchResponse->totalBatches;
                if ($totalBatches !== null && $totalBatches !== $ackIndex) {
                    throw $connection->protocolFailure("FETCH_RESPONSE ended after {$ackIndex} batches but announced {$totalBatches}");
                }
            } else {
                throw $connection->protocolFailure('FETCH_RESPONSE with chunks did not include a batch index');
            }
            $needsMoreFetch = $batchIndex !== null;

            foreach ($results as $chunk) {
                yield $chunk;
            }
        }
    }
}

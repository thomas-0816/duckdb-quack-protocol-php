<?php

namespace DuckDB\Quack;

use DuckDB\Quack\Chunk\DataChunk;
use DuckDB\Quack\Connection\ClientConnection;
use DuckDB\Quack\Error\QuackError;
use DuckDB\Quack\Exceptions\ProtocolException;
use DuckDB\Quack\Query\QueryPool;
use DuckDB\Quack\Query\QueryStream;
use DuckDB\Quack\Sql\SqlFormatter;
use DuckDB\Quack\Sql\SqlParameters;
use DuckDB\Quack\Transport\Transport;
use Generator;

/**
 * A Quack client with one or more server-side sessions.
 *
 * The first connection backs the synchronous helpers. fetchAllParallel() runs
 * $maxConnections queries in parallel.
 * Temp tables and transactions are per connection.
 */
final class QuackClient
{
    /**
     * @var list<ClientConnection>
     */
    private array $pool;

    public function __construct(
        private readonly ClientConnection $connection,
        private readonly Transport $transport,
    ) {
        $this->pool = [$connection];
    }

    public function __destruct()
    {
        foreach ($this->pool as $connection) {
            $connection->disconnectOnDrop();
        }
    }

    /**
     * Opens a session, negotiating a supported Quack protocol version.
     *
     * Extra connections for fetchAllParallel() are opened on first use.
     *
     * @throws QuackError if the connection cannot be established.
     */
    public static function connect(
        string $uri,
        string $authToken,
        float $connectTimeout = 10,
        float $queryTimeout = 30,
        int $maxConnections = 1,
        ?string $sslCertificate = null,
    ): self {
        $transport = Transport::new($uri, $authToken, $connectTimeout, $queryTimeout, $maxConnections, $sslCertificate);
        $connection = ClientConnection::connect($transport);

        return new self($connection, $transport);
    }

    public function getConnection(): ClientConnection
    {
        return $this->connection;
    }

    /**
     * Formats a statement, rendering its placeholders as literals.
     *
     * @param array<int|string,mixed> $params
     */
    public function prepare(string $sql, array $params = []): string
    {
        return SqlFormatter::formatSql($sql, SqlParameters::coerce($params));
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
            if (($chunk->resultNames[0] ?? null) !== 'Count') {
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
     * @param \Closure(self):mixed $callback
     */
    public function transaction(\Closure $callback): mixed
    {
        $this->execute('BEGIN TRANSACTION');
        try {
            $result = $callback($this);
        } catch (\Throwable $e) {
            try {
                $this->execute('ROLLBACK');
            } catch (\Throwable) {
                // Keep the callback failure; the connection may already be unusable.
            }
            throw $e;
        }
        $this->execute('COMMIT');
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
        $query = SqlFormatter::formatSql($sql, SqlParameters::coerce($params));
        $rows = [];
        foreach ($this->fetchChunks($this->connection, $query) as $chunk) {
            $names = $chunk->resultNames;
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
     * Runs all queries concurrently on the connection pool and returns every
     * result set keyed like the input.
     *
     * Each query runs on its own connection, so at most the configured number
     * of connections run at a time. Queries must not depend on temp tables or
     * transactions created by another query, because they may land on a
     * different connection.
     *
     * @param array<int|string,string> $queries
     * @return array<int|string,list<array<string,mixed>>>
     */
    public function fetchAllParallel(array $queries): array
    {
        if ($this->connection->isClosed()) {
            throw new ProtocolException('Quack client is not connected');
        }
        if ($queries === []) {
            return [];
        }

        $sql = [];
        foreach ($queries as $key => $query) {
            $sql[$key] = SqlFormatter::formatSql($query, []);
        }

        $connectionCount = min($this->transport->maxConnections(), count($sql));
        while (count($this->pool) < $connectionCount) {
            $this->pool[] = ClientConnection::connect($this->transport);
        }

        $chunks = QueryPool::fetchChunks($this->pool, $sql);
        $rows = [];
        foreach ($chunks as $key => $resultChunks) {
            foreach ($resultChunks as $chunk) {
                $names = $chunk->resultNames;
                for ($rowIndex = 0; $rowIndex < $chunk->rowCount; $rowIndex++) {
                    $row = [];
                    foreach ($chunk->columns as $columnIndex => $column) {
                        $name = $names[$columnIndex] ?? "column{$columnIndex}";
                        $row[$name] = $column->values[$rowIndex] ?? null;
                    }
                    $rows[$key][] = $row;
                }
            }
        }

        return $rows;
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
        $query = SqlFormatter::formatSql($sql, SqlParameters::coerce($params));
        foreach ($this->fetchChunks($this->connection, $query) as $chunk) {
            $names = $chunk->resultNames;
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
        $query = SqlFormatter::formatSql($sql, SqlParameters::coerce($params));
        foreach ($this->fetchChunks($this->connection, $query) as $chunk) {
            $names = $chunk->resultNames;
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
     * Prepares a query and streams its result chunks.
     *
     * @return \Generator<int,DataChunk>
     */
    private function fetchChunks(ClientConnection $connection, string $query): Generator
    {
        $stream = new QueryStream($connection, $query);
        $request = $stream->start();
        while ($request !== null) {
            [$request, $chunks] = $stream->advance($request->wait());
            foreach ($chunks as $chunk) {
                yield $chunk;
            }
        }
    }
}

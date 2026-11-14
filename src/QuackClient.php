<?php

namespace DuckDB\Quack;

use DuckDB\Quack\Chunk\DataChunk;
use DuckDB\Quack\Connection\ClientConnection;
use DuckDB\Quack\Error\QuackError;
use DuckDB\Quack\Exceptions\ProtocolException;
use DuckDB\Quack\Messages\FetchResponseMessage;
use DuckDB\Quack\Messages\PrepareResponseMessage;
use DuckDB\Quack\Sql\QuerySql;
use DuckDB\Quack\Sql\SqlFormatter;
use DuckDB\Quack\Sql\SqlParameters;
use DuckDB\Quack\Transport\Transport;
use DuckDB\Quack\Types\Value;

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
        $querySql = new QuerySql(SqlFormatter::formatSql($sql, SqlParameters::coerce($params)));
        $this->connection->setBusy(true);
        try {
            $response = $this->connection->prepare($querySql->asString());
            if (!$response instanceof PrepareResponseMessage) {
                throw $this->connection->protocolFailure('expected PREPARE_RESPONSE, got ' . $response->messageType()->name);
            }
            $types = [];
            foreach ($response->resultNames as $key => $name) {
                $type = $response->resultTypes[$key] ?? null;
                if ($type !== null) {
                    $types[$name] = $type;
                }
            }
            $names = array_keys($types);
            foreach ($this->chunks($response) as $chunk) { // TODO check again
                for ($rowIndex = 0; $rowIndex < $chunk->rowCount; $rowIndex++) {
                    $result = [];
                    foreach ($chunk->columns as $columnIndex => $column) {
                        $name = $names[$columnIndex] ?? "column{$columnIndex}";
                        $value = $column->values[$rowIndex] ?? Value::null();
                        $result[$name] = $value->toPhp($types[$name] ?? null);
                    }
                    $count = $result['Count'] ?? 0;
                    return is_int($count) ? $count : 0;
                }
            }
            return 0;
        } finally {
            $this->connection->setBusy(false);
        }
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
        $querySql = new QuerySql(SqlFormatter::formatSql($sql, SqlParameters::coerce($params)));
        $this->connection->setBusy(true);
        try {
            $response = $this->connection->prepare($querySql->asString());
            if (!$response instanceof PrepareResponseMessage) {
                throw $this->connection->protocolFailure('expected PREPARE_RESPONSE, got ' . $response->messageType()->name);
            }
            $types = [];
            foreach ($response->resultNames as $key => $name) {
                $type = $response->resultTypes[$key] ?? null;
                if ($type !== null) {
                    $types[$name] = $type;
                }
            }
            $names = array_keys($types);
            $rows = [];
            foreach ($this->chunks($response) as $chunk) {
                for ($rowIndex = 0; $rowIndex < $chunk->rowCount; $rowIndex++) {
                    $result = [];
                    foreach ($chunk->columns as $columnIndex => $column) {
                        $name = $names[$columnIndex] ?? "column{$columnIndex}";
                        $value = $column->values[$rowIndex] ?? Value::null();
                        $result[$name] = $value->toPhp($types[$name] ?? null);
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
        $querySql = new QuerySql(SqlFormatter::formatSql($sql, SqlParameters::coerce($params)));
        $this->connection->setBusy(true);
        try {
            $response = $this->connection->prepare($querySql->asString());
            if (!$response instanceof PrepareResponseMessage) {
                throw $this->connection->protocolFailure('expected PREPARE_RESPONSE, got ' . $response->messageType()->name);
            }
            $types = [];
            foreach ($response->resultNames as $key => $name) {
                $type = $response->resultTypes[$key] ?? null;
                if ($type !== null) {
                    $types[$name] = $type;
                }
            }
            $names = array_keys($types);
            foreach ($this->chunks($response) as $chunk) {
                for ($rowIndex = 0; $rowIndex < $chunk->rowCount; $rowIndex++) {
                    $result = [];
                    foreach ($chunk->columns as $columnIndex => $column) {
                        $name = $names[$columnIndex] ?? "column{$columnIndex}";
                        $value = $column->values[$rowIndex] ?? Value::null();
                        $result[$name] = $value->toPhp($types[$name] ?? null);
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
        $querySql = new QuerySql(SqlFormatter::formatSql($sql, SqlParameters::coerce($params)));
        $this->connection->setBusy(true);
        try {
            $response = $this->connection->prepare($querySql->asString());
            if (!$response instanceof PrepareResponseMessage) {
                throw $this->connection->protocolFailure('expected PREPARE_RESPONSE, got ' . $response->messageType()->name);
            }
            $types = [];
            foreach ($response->resultNames as $key => $name) {
                $type = $response->resultTypes[$key] ?? null;
                if ($type !== null) {
                    $types[$name] = $type;
                }
            }
            $names = array_keys($types);
            foreach ($this->chunks($response) as $chunk) {
                for ($rowIndex = 0; $rowIndex < $chunk->rowCount; $rowIndex++) {
                    $result = [];
                    foreach ($chunk->columns as $columnIndex => $column) {
                        $name = $names[$columnIndex] ?? "column{$columnIndex}";
                        $value = $column->values[$rowIndex] ?? Value::null();
                        $result[$name] = $value->toPhp($types[$name] ?? null);
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
        $querySql = new QuerySql(SqlFormatter::formatSql($sql, SqlParameters::coerce($params)));
        $this->connection->setBusy(true);
        try {
            $response = $this->connection->prepare($querySql->asString());
            if (!$response instanceof PrepareResponseMessage) {
                throw $this->connection->protocolFailure('expected PREPARE_RESPONSE, got ' . $response->messageType()->name);
            }
            $type = $response->resultTypes[0] ?? null;
            $values = [];
            foreach ($this->chunks($response) as $chunk) {
                foreach ($chunk->columns[0]->values ?? [] as $value) {
                    $values[] = $value->toPhp($type);
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
        $querySql = new QuerySql(SqlFormatter::formatSql($sql, SqlParameters::coerce($params)));
        $this->connection->setBusy(true);
        try {
            $response = $this->connection->prepare($querySql->asString());
            if (!$response instanceof PrepareResponseMessage) {
                throw $this->connection->protocolFailure('expected PREPARE_RESPONSE, got ' . $response->messageType()->name);
            }
            $type = $response->resultTypes[0] ?? null;
            foreach ($this->chunks($response) as $chunk) {
                foreach ($chunk->columns[0]->values ?? [] as $value) {
                    return $value->toPhp($type);
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
        $querySql = new QuerySql(SqlFormatter::formatSql($sql, SqlParameters::coerce($params)));
        $this->connection->setBusy(true);
        try {
            $response = $this->connection->prepare($querySql->asString());
            if (!$response instanceof PrepareResponseMessage) {
                throw $this->connection->protocolFailure('expected PREPARE_RESPONSE, got ' . $response->messageType()->name);
            }
            $keyType = $response->resultTypes[0] ?? null;
            $valueType = $response->resultTypes[1] ?? null;
            $result = [];
            foreach ($this->chunks($response) as $chunk) {
                $keys = $chunk->columns[0]->values ?? [];
                $values = $chunk->columns[1]->values ?? [];
                foreach ($keys as $rowIndex => $keyValue) {
                    $phpKey = $keyValue->toPhp($keyType);
                    if (!is_int($phpKey) && !is_string($phpKey)) {
                        throw new ProtocolException('key column must contain integers or strings');
                    }
                    $phpValue = $values[$rowIndex] ?? Value::null();
                    $result[$phpKey] = $phpValue->toPhp($valueType);
                }
            }
            return $result;
        } finally {
            $this->connection->setBusy(false);
        }
    }

    /**
     * @return \Generator<int,DataChunk>
     */
    private function chunks(PrepareResponseMessage $response): \Generator
    {
        $needsMoreFetch = $response->needsMoreFetch;
        $nextBatchIndex = 1;
        $ackIndex = 0;

        foreach ($response->results as $chunk) {
            yield $chunk;
        }

        while ($needsMoreFetch) {
            $fetchResponse = $this->connection->fetch(
                $response->resultUuid,
                $nextBatchIndex,
                $ackIndex,
            );
            if (!$fetchResponse instanceof FetchResponseMessage) {
                throw $this->connection->protocolFailure('expected FETCH_RESPONSE, got ' . $fetchResponse->messageType()->name);
            }

            $results = $fetchResponse->results;
            $batchIndex = $fetchResponse->batchIndex;
            if ($batchIndex !== null) {
                if ($batchIndex !== $nextBatchIndex) {
                    throw $this->connection->protocolFailure("expected FETCH_RESPONSE batch {$nextBatchIndex}, got {$batchIndex}");
                }
                if ($results === []) {
                    throw $this->connection->protocolFailure('FETCH_RESPONSE batch did not include any chunks');
                }
                $ackIndex = $batchIndex;
                $nextBatchIndex++;
            } elseif ($results === []) {
                $totalBatches = $fetchResponse->totalBatches;
                if ($totalBatches !== null && $totalBatches !== $ackIndex) {
                    throw $this->connection->protocolFailure("FETCH_RESPONSE ended after {$ackIndex} batches but announced {$totalBatches}");
                }
            } else {
                throw $this->connection->protocolFailure('FETCH_RESPONSE with chunks did not include a batch index');
            }
            $needsMoreFetch = $batchIndex !== null;

            foreach ($results as $chunk) {
                yield $chunk;
            }
        }
    }
}

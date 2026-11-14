<?php

namespace DuckDB\Quack;

use DuckDB\Quack\Chunk\DataChunk;
use DuckDB\Quack\Connection\ClientConnection;
use DuckDB\Quack\Exceptions\ProtocolException;
use DuckDB\Quack\Messages\FetchResponseMessage;
use DuckDB\Quack\Messages\PrepareResponseMessage;
use DuckDB\Quack\Types\LogicalType;
use DuckDB\Quack\Types\Value;

/**
 * A lazily fetched query result.
 *
 * The stream keeps its client's query slot until it is fully consumed,
 * explicitly closed, or destroyed.
 *
 * @implements \IteratorAggregate<int,array<string,mixed>>
 */
final class QuackStatement implements \IteratorAggregate
{
    private bool $started = false;

    private bool $closed = false;

    /**
     * @var \Generator<int,array<string,mixed>>|null
     */
    private ?\Generator $rowCursor = null;

    private int $nextBatchIndex = 1;

    private int $ackIndex = 0;

    private bool $needsMoreFetch;

    public function __construct(
        private readonly ClientConnection $connection,
        private readonly PrepareResponseMessage $response,
        private ?\Closure $release = null,
    ) {
        $this->needsMoreFetch = $response->needsMoreFetch;
        foreach ($response->results as $chunk) {
            $chunk->columnNames = $response->resultNames;
        }
    }

    /**
     * @return array<string,LogicalType>
     */
    public function columns(): array
    {
        $columns = [];
        foreach ($this->response->resultNames as $key => $name) {
            $type = $this->response->resultTypes[$key] ?? null;
            if ($type === null) {
                continue;
            }
            $columns[$name] = $type;
        }
        return $columns;
    }

    /**
     * @return \Generator<int,array<string,mixed>>
     */
    public function getIterator(): \Generator
    {
        try {
            if ($this->closed) {
                throw new ProtocolException('result stream is already closed');
            }
            while (($row = $this->fetchRow()) !== null) {
                yield $row;
            }
        } finally {
            $this->close();
        }
    }

    /**
     * Fetches the next row, or null once the result is exhausted.
     *
     * @return array<string,mixed>|null
     */
    public function fetchRow(): ?array
    {
        if ($this->closed) {
            return null;
        }
        $cursor = $this->rowCursor ??= $this->decodeRows();
        if (!$cursor->valid()) {
            $this->close();
            return null;
        }
        $row = $cursor->current();
        $cursor->next();
        if (!$cursor->valid()) {
            $this->close();
        }
        return $row;
    }

    /**
     * Fetches all rows.
     *
     * @return list<array<string,mixed>>
     */
    public function fetchAll(): array
    {
        if ($this->closed) {
            throw new ProtocolException('result stream is already closed');
        }
        if ($this->started) {
            throw new ProtocolException('result stream has already been consumed');
        }
        try {
            $types = $this->columns();
            $names = array_keys($types);
            $rows = [];
            $chunks = $this->response->results;
            while (true) {
                foreach ($chunks as $chunk) {
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
                if (!$this->needsMoreFetch) {
                    break;
                }
                $chunks = $this->fetchNextChunks();
            }
            return $rows;
        } finally {
            $this->close();
        }
    }

    /**
     * Fetches the values of the named column.
     *
     * @return list<mixed>
     */
    public function fetchColumn(string $name): array
    {
        if ($this->closed) {
            throw new ProtocolException('result stream is already closed');
        }
        if ($this->started) {
            throw new ProtocolException('result stream has already been consumed');
        }
        $types = $this->columns();
        $index = array_search($name, array_keys($types), true);
        if ($index === false) {
            throw new ProtocolException("result has no column named {$name}");
        }
        $type = $types[$name];
        try {
            $values = [];
            $chunks = $this->response->results;
            while (true) {
                foreach ($chunks as $chunk) {
                    foreach ($chunk->columns[$index]->values ?? [] as $value) {
                        $values[] = $value->toPhp($type);
                    }
                }
                if (!$this->needsMoreFetch) {
                    break;
                }
                $chunks = $this->fetchNextChunks();
            }
            return $values;
        } finally {
            $this->close();
        }
    }

    /**
     * Fetches a value from the first row, by default from the first column.
     */
    public function fetchValue(?string $name = null): mixed
    {
        try {
            $row = $this->fetchRow();
            if ($row === null) {
                return null;
            }
            $name ??= array_key_first($this->columns()) ?? '';
            return $row[$name] ?? null;
        } finally {
            $this->close();
        }
    }

    /**
     * @return \Generator<int,array<string,mixed>>
     */
    private function decodeRows(): \Generator // TODO inline code
    {
        if ($this->closed) {
            throw new ProtocolException('result stream is already closed');
        }
        if ($this->started) {
            throw new ProtocolException('result stream has already been consumed');
        }
        $this->started = true;
        try {
            $types = $this->columns();
            $names = array_keys($types);

            foreach ($this->response->results as $chunk) {
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
            while ($this->needsMoreFetch) {
                foreach ($this->fetchNextChunks() as $chunk) {
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
            }
        } finally {
            $this->close();
        }
    }

    /**
     * @return list<DataChunk>
     */
    private function fetchNextChunks(): array
    {
        if (!$this->needsMoreFetch) {
            return [];
        }

        $response = $this->connection->fetch(
            $this->response->resultUuid,
            $this->nextBatchIndex,
            $this->ackIndex,
        );
        if (!$response instanceof FetchResponseMessage) {
            throw $this->connection->protocolFailure(
                'expected FETCH_RESPONSE, got ' . $response->messageType()->name,
            );
        }

        $results = $response->results;
        $batchIndex = $response->batchIndex;
        if ($batchIndex !== null) {
            if ($batchIndex !== $this->nextBatchIndex) {
                throw $this->connection->protocolFailure(
                    "expected FETCH_RESPONSE batch {$this->nextBatchIndex}, got {$batchIndex}",
                );
            }
            if ($results === []) {
                throw $this->connection->protocolFailure(
                    'FETCH_RESPONSE batch did not include any chunks',
                );
            }
            $this->ackIndex = $batchIndex;
            $this->nextBatchIndex++;
        } elseif ($results === []) {
            $totalBatches = $response->totalBatches;
            if ($totalBatches !== null && $totalBatches !== $this->ackIndex) {
                throw $this->connection->protocolFailure(
                    "FETCH_RESPONSE ended after {$this->ackIndex} batches "
                    . "but announced {$totalBatches}",
                );
            }
        } else {
            throw $this->connection->protocolFailure(
                'FETCH_RESPONSE with chunks did not include a batch index',
            );
        }
        $this->needsMoreFetch = $batchIndex !== null;

        foreach ($results as $chunk) {
            $chunk->columnNames = $this->response->resultNames;
        }

        return $results;
    }

    /**
     * Releases the client's query slot. Safe to call more than once.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->needsMoreFetch = false;
        $this->rowCursor = null;
        $release = $this->release;
        $this->release = null;
        if ($release !== null) {
            $release();
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}

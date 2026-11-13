<?php

namespace Quack\Protocol;

use Quack\Protocol\Chunk\ColumnDefinition;
use Quack\Protocol\Chunk\DataChunk;
use Quack\Protocol\Chunk\DataChunkCodec;
use Quack\Protocol\Exceptions\ProtocolException;
use Quack\Protocol\Sql\FetchState;

/**
 * A lazily fetched query result.
 *
 * The stream keeps its client's query slot until it is fully consumed or
 * destroyed. Consuming helpers such as first() and one() drain the remaining
 * FETCH responses before returning.
 */
final class QuackResult
{
    private bool $started = false;

    private bool $closed = false;

    /**
     * @param list<ColumnDefinition> $columns
     * @param list<DataChunk>        $initialChunks
     */
    public function __construct(
        public readonly array $columns,
        private array $initialChunks,
        private ?FetchState $fetchState,
        private ?\Closure $release = null,
    ) {
    }

    /**
     * @return \Generator<int,DataChunk>
     */
    private function chunks(): \Generator
    {
        if ($this->closed) {
            throw new ProtocolException('result stream is already closed');
        }
        if ($this->started) {
            throw new ProtocolException('result stream has already been consumed');
        }
        $this->started = true;
        try {
            foreach ($this->initialChunks as $chunk) {
                yield $chunk;
            }
            $this->initialChunks = [];
            while ($this->fetchState !== null && $this->fetchState->needsMoreFetch()) {
                foreach ($this->fetchState->fetchNext() as $chunk) {
                    yield $chunk;
                }
            }
        } finally {
            $this->close();
        }
    }

    /**
     * @return \Generator<int,array<string,mixed>>
     */
    public function rows(): \Generator
    {
        $names = array_map(
            static fn (ColumnDefinition $column): string => $column->name,
            $this->columns,
        );
        $types = [];
        foreach ($this->columns as $column) {
            $types[$column->name] = $column->logicalType;
        }
        foreach ($this->chunks() as $chunk) {
            foreach (DataChunkCodec::rowsFromChunkWithNames($chunk, $names) as $row) {
                $result = [];
                foreach ($row as $name => $value) {
                    $result[$name] = $value->toPhp($types[$name] ?? null);
                }
                yield $result;
            }
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    public function first(): ?array
    {
        try {
            foreach ($this->rows() as $row) {
                return $row;
            }
            return null;
        } finally {
            $this->close();
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function one(): array
    {
        try {
            $rows = [];
            foreach ($this->rows() as $row) {
                $rows[] = $row;
            }
            if (count($rows) !== 1) {
                throw new ProtocolException(
                    'expected exactly one row, got ' . count($rows),
                );
            }
            return $rows[0];
        } finally {
            $this->close();
        }
    }

    /**
     * @return list<mixed>
     */
    public function column(string $name): array
    {
        try {
            $values = [];
            foreach ($this->rows() as $row) {
                $values[] = $row[$name] ?? null;
            }
            return $values;
        } finally {
            $this->close();
        }
    }

    /**
     * Releases the client's query slot. Safe to call more than once.
     */
    private function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->fetchState = null;
        $this->initialChunks = [];
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

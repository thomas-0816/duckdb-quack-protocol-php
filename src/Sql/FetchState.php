<?php

namespace Quack\Protocol\Sql;

use Quack\Protocol\Chunk\DataChunk;
use Quack\Protocol\Connection\ClientConnection;
use Quack\Protocol\Exceptions\ProtocolException;
use Quack\Protocol\Messages\FetchResponseMessage;
use Quack\Protocol\QuackVersion;
use Quack\Protocol\Types\BigInt;
use Quack\Protocol\Types\HugeIntParts;

/**
 * Cursor state for the FETCH requests that continue a prepared result.
 */
final class FetchState
{
    /**
     * @param list<string> $columnNames
     */
    public function __construct(
        public readonly ClientConnection $connection,
        public readonly QuerySql $sql,
        public readonly HugeIntParts $resultUuid,
        public readonly QuackVersion $quackVersion,
        public readonly array $columnNames,
        private bool $needsMoreFetch,
        private int|string $nextBatchIndex = 1,
        private int|string $ackIndex = 0,
        private int $rowsDelivered = 0,
    ) {
    }

    public function needsMoreFetch(): bool
    {
        return $this->needsMoreFetch;
    }

    /**
     * @return list<DataChunk>
     */
    public function fetchNext(): array
    {
        if (!$this->needsMoreFetch) {
            return [];
        }

        $response = $this->connection->fetch(
            $this->resultUuid,
            $this->nextBatchIndex,
            $this->ackIndex,
        );
        if (!$response instanceof FetchResponseMessage) {
            throw $this->connection->protocolFailure(
                'expected FETCH_RESPONSE, got ' . $response->messageType()->name,
            );
        }

        $results = $response->results;
        if ($this->quackVersion->number() === 3) {
            $batchIndex = $response->batchIndex;
            if ($batchIndex !== null) {
                if ((string) $batchIndex !== (string) $this->nextBatchIndex) {
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
                $this->nextBatchIndex = $this->incrementIndex($batchIndex);
            } elseif ($results === []) {
                $totalBatches = $response->totalBatches;
                if ($totalBatches !== null
                    && (string) $totalBatches !== (string) $this->ackIndex) {
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
        } else {
            $this->needsMoreFetch = $results !== [];
        }

        foreach ($results as $chunk) {
            $chunk->columnNames = $this->columnNames;
            $this->rowsDelivered += $chunk->rowCount;
        }

        return $results;
    }

    public function rowsDelivered(): int
    {
        return $this->rowsDelivered;
    }

    private function incrementIndex(int|string $value): int|string
    {
        try {
            return BigInt::coerce($value)
                ->add(BigInt::one())
                ->toIntOrString();
        } catch (\Throwable) {
            throw new ProtocolException('FETCH_RESPONSE batch index overflow');
        }
    }
}

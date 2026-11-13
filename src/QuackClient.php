<?php

namespace Quack\Protocol;

use Quack\Protocol\Chunk\ColumnDefinition;
use Quack\Protocol\Chunk\DataChunk;
use Quack\Protocol\Connection\ClientConnection;
use Quack\Protocol\Connection\ConnectionState;
use Quack\Protocol\Error\QuackError;
use Quack\Protocol\Exceptions\ProtocolException;
use Quack\Protocol\Messages\PrepareResponseMessage;
use Quack\Protocol\Sql\FetchState;
use Quack\Protocol\Sql\QuerySql;
use Quack\Protocol\Sql\SqlFormatter;
use Quack\Protocol\Sql\SqlParameters;
use Quack\Protocol\Transport\Transport;

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
    private bool $busy = false;

    public function __construct(
        private readonly ClientConnection $connection,
        private readonly ConnectionState $state,
        public readonly ?QuackConnectionInfo $info,
    ) {
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
        $transport = Transport::new(
            $uri,
            $connectTimeout,
            $timeout,
            $sslCertificate,
        );
        [$connection, $info] = ClientConnection::connect($transport, $authToken);
        return new self($connection, $connection->state(), $info);
    }

    /**
     * Run a statement and discard its result.
     */
    public function execute(string $sql): void
    {
        $stream = $this->query($sql);
        foreach ($stream->rows() as $_) {
            // Discard result rows.
        }
    }

    /**
     * @param array<int|string,mixed> $params
     */
    public function query(
        string $sql,
        array $params = [],
    ): QuackResult {
        return $this->queryInner($sql, SqlParameters::coerce($params));
    }

    public function __destruct()
    {
        if ($this->busy) {
            // An active result stream still owns this client and will release it.
            return;
        }
        $this->connection->disconnectOnDrop();
    }

    /**
     * @param list<DataChunk> $chunks
     * @param list<string> $names
     */
    private function attachColumnNames(array $chunks, array $names): void
    {
        foreach ($chunks as $chunk) {
            $chunk->columnNames = $names;
        }
    }

    private function queryInner(
        string $sql,
        ?SqlParameters $params,
    ): QuackResult {
        $querySql = new QuerySql(SqlFormatter::formatSql($sql, $params));
        $this->beginQuery();
        try {
            $response = $this->connection->prepare($querySql->asString());
            if (!$response instanceof PrepareResponseMessage) {
                throw $this->connection->protocolFailure(
                    'expected PREPARE_RESPONSE, got ' . $response->messageType()->name,
                );
            }

            $chunks = $response->results;
            $this->attachColumnNames($chunks, $response->resultNames);
            $columns = [];
            $count = min(count($response->resultNames), count($response->resultTypes));
            for ($index = 0; $index < $count; $index++) {
                $type = $response->resultTypes[$index];
                $columns[] = new ColumnDefinition(
                    $response->resultNames[$index],
                    $type,
                );
            }
            $columnNames = array_map(
                static fn (ColumnDefinition $column): string => $column->name,
                $columns,
            );

            $fetchState = new FetchState(
                connection: $this->connection,
                sql: $querySql,
                resultUuid: $response->resultUuid,
                quackVersion: $this->connection->quackVersion(),
                columnNames: $columnNames,
                needsMoreFetch: $response->needsMoreFetch,
            );

            return new QuackResult(
                $columns,
                $chunks,
                $fetchState,
                function (): void {
                    $this->releaseQuery();
                },
            );
        } catch (\Throwable $error) {
            $this->releaseQuery();
            throw $error;
        }
    }

    private function beginQuery(): void
    {
        if ($this->state->isClosed()) {
            throw new ProtocolException('Quack client is not connected');
        }
        if ($this->busy) {
            throw new ProtocolException('Quack client is already running a query');
        }
        $this->busy = true;
    }

    private function releaseQuery(): void
    {
        $this->busy = false;
    }
}

<?php

namespace DuckDB\Quack;

use DuckDB\Quack\Connection\ClientConnection;
use DuckDB\Quack\Connection\ConnectionState;
use DuckDB\Quack\Error\QuackError;
use DuckDB\Quack\Exceptions\ProtocolException;
use DuckDB\Quack\Messages\PrepareResponseMessage;
use DuckDB\Quack\Sql\QuerySql;
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
     * Run a statement and return the number of changed rows.
     */
    public function execute(string $sql): int
    {
        $changed = 0;
        foreach ($this->query($sql) as $row) {
            $count = $row['Count'] ?? 0;
            if (is_int($count)) {
                $changed += $count;
            }
        }
        return $changed;
    }

    /**
     * @param array<int|string,mixed> $params
     */
    public function query(
        string $sql,
        array $params = [],
    ): QuackStatement {
        if ($this->state->isClosed()) {
            throw new ProtocolException('Quack client is not connected');
        }
        if ($this->busy) {
            throw new ProtocolException('Quack client is already running a query');
        }
        $querySql = new QuerySql(SqlFormatter::formatSql($sql, SqlParameters::coerce($params)));
        $this->busy = true;
        try {
            $response = $this->connection->prepare($querySql->asString());
            if (!$response instanceof PrepareResponseMessage) {
                throw $this->connection->protocolFailure(
                    'expected PREPARE_RESPONSE, got ' . $response->messageType()->name,
                );
            }

            return new QuackStatement(
                $this->connection,
                $response,
                function (): void {
                    $this->busy = false;
                },
            );
        } catch (\Throwable $error) {
            $this->busy = false;
            throw $error;
        }
    }

    public function __destruct()
    {
        if ($this->busy) {
            // An active result stream still owns this client and will release it.
            return;
        }
        $this->connection->disconnectOnDrop();
    }
}

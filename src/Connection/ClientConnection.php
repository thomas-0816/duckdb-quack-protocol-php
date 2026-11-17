<?php

namespace DuckDB\Quack\Connection;

use DuckDB\Quack\Error\QuackError;
use DuckDB\Quack\Exceptions\ProtocolException;
use DuckDB\Quack\Exceptions\ServerException;
use DuckDB\Quack\Exceptions\TransportException;
use DuckDB\Quack\Messages\CancelMessage;
use DuckDB\Quack\Messages\ConnectionRequestMessage;
use DuckDB\Quack\Messages\ConnectionResponseMessage;
use DuckDB\Quack\Messages\DisconnectMessage;
use DuckDB\Quack\Messages\FetchRequestMessage;
use DuckDB\Quack\Messages\MessageHeader;
use DuckDB\Quack\Messages\MessageType;
use DuckDB\Quack\Messages\PrepareRequestMessage;
use DuckDB\Quack\Messages\QuackMessage;
use DuckDB\Quack\Transport\Transport;
use DuckDB\Quack\Types\HugeIntParts;

/**
 * One negotiated Quack session, minus the public client facade.
 *
 * Protocol v3 leases expire when a session stays silent for the agreed
 * heartbeat timeout. This synchronous port requests a lease with headroom
 * over the longest request it allows, so ordinary traffic renews the lease
 * without a background timer. It sends a best-effort DISCONNECT when it is
 * dropped. A connection-fatal error retires the session, which is never
 * reused or replayed automatically.
 */
final class ClientConnection
{
    /**
     * @var list<int>
     */
    private const QUACK_VERSIONS = [3];

    private int $queryCounter = 1;

    private int $queryUuidCounter = 1;

    private bool $closed = false;

    private bool $disconnected = false;

    private function __construct(
        private readonly Transport $transport,
        private readonly string $connectionId,
        private readonly ?string $serverDuckdbVersion,
        private readonly ?string $serverPlatform,
        private readonly int $quackVersion,
    ) {
    }

    public static function connect(Transport $transport): self
    {
        $response = $transport->send(
            new ConnectionRequestMessage(
                header: MessageHeader::new(MessageType::ConnectionRequest),
                authString: $transport->getAuthToken(),
                clientPlatform: 'quack-php',
                minSupportedQuackVersion: min(self::QUACK_VERSIONS),
                maxSupportedQuackVersion: max(self::QUACK_VERSIONS),
                heartbeatTimeoutSeconds: 90,
            ),
            max(self::QUACK_VERSIONS),
        );
        if (!$response instanceof ConnectionResponseMessage) {
            throw new ProtocolException('expected CONNECTION_RESPONSE, got ' . $response->messageType()->name);
        }
        $connectionId = $response->header->connectionId;
        if ($connectionId === null || $connectionId === '') {
            throw new ProtocolException('CONNECTION_RESPONSE did not include a connection id');
        }
        $selectedVersion = (int) $response->quackVersion;
        if (!in_array($selectedVersion, self::QUACK_VERSIONS, true)) {
            $selected = (string) $response->quackVersion;
            throw new ProtocolException("server selected Quack protocol version {$selected}, expected " . implode(' or ', self::QUACK_VERSIONS));
        }

        return new self(
            $transport,
            $connectionId,
            $response->serverDuckdbVersion,
            $response->serverPlatform,
            $selectedVersion,
        );
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function getQuackVersion(): int
    {
        return $this->quackVersion;
    }

    public function getServerDuckdbVersion(): ?string
    {
        return $this->serverDuckdbVersion;
    }

    public function getServerPlatform(): ?string
    {
        return $this->serverPlatform;
    }

    public function getConnectionId(): string
    {
        return $this->connectionId;
    }

    public function getTransport(): Transport
    {
        return $this->transport;
    }

    public function prepare(string $sql, ?int $inlineRows): QuackMessage
    {
        if ($this->closed) {
            throw new ProtocolException('Quack client is not connected');
        }
        $message = new PrepareRequestMessage(
            header: MessageHeader::new(MessageType::PrepareRequest)
                ->withConnection($this->connectionId)
                ->withClientQueryId($this->queryCounter++),
            sql: $sql,
            queryUuid: new HugeIntParts(0, $this->queryUuidCounter++),
            inlineRows: $inlineRows,
        );
        try {
            return $this->transport->send($message, $this->quackVersion);
        } catch (ServerException $error) {
            if ($error->serverError()->isFatal()) {
                $this->closed = true;
            }
            throw $error;
        } catch (TransportException $error) {
            $this->cancel($message->queryUuid);
            throw $error;
        }
    }

    public function fetch(HugeIntParts $resultUuid, int $batchIndex, int $ackIndex): QuackMessage
    {
        if ($this->closed) {
            throw new ProtocolException('Quack client is not connected');
        }
        $message = new FetchRequestMessage(
            header: MessageHeader::new(MessageType::FetchRequest)
                ->withConnection($this->connectionId)
                ->withClientQueryId($this->queryCounter++),
            resultUuid: $resultUuid,
            batchIndex: $batchIndex,
            ackIndex: $ackIndex,
        );
        try {
            return $this->transport->send($message, $this->quackVersion);
        } catch (ServerException $error) {
            if ($error->serverError()->isFatal()) {
                $this->closed = true;
            }
            throw $error;
        } catch (TransportException $error) {
            $this->cancel($message->resultUuid);
            throw $error;
        }
    }

    /**
     * Cancels the query running, or still producing results, on this connection.
     *
     * A null uuid sends the zero wildcard, which cancels whatever is active.
     */
    public function cancel(HugeIntParts $queryUuid): QuackMessage
    {
        if ($this->closed) {
            throw new ProtocolException('Quack client is not connected');
        }
        $message = new CancelMessage(
            header: MessageHeader::new(MessageType::CancelRequest)
                ->withConnection($this->connectionId)
                ->withClientQueryId($this->queryCounter++),
            queryUuid: $queryUuid,
        );
        try {
            return $this->transport->setTimeout(3)->send($message, $this->quackVersion);
        } catch (ServerException $error) {
            if ($error->serverError()->isFatal()) {
                $this->closed = true;
            }
            throw $error;
        }
    }

    /**
     * Best-effort cleanup used by the client destructor.
     *
     * A server exception closes the connection for further queries, but the server-side
     * session can still exist. Always make one attempt to disconnect,
     * so the server drops the session instead of keeping
     * it until the heartbeat lease expires.
     */
    public function disconnect(): void
    {
        if ($this->disconnected) {
            return;
        }
        $message = new DisconnectMessage(
            MessageHeader::new(MessageType::DisconnectMessage)
                ->withConnection($this->connectionId)
                ->withClientQueryId($this->queryCounter++),
        );
        try {
            $this->transport->setTimeout(3)->send($message, $this->quackVersion);
        } catch (QuackError) {
            // The caller is gone; nothing useful can be reported.
        } finally {
            $this->disconnected = true;
            $this->closed = true;
        }
    }
}

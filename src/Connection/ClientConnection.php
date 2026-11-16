<?php

namespace DuckDB\Quack\Connection;

use DuckDB\Quack\Error\QuackError;
use DuckDB\Quack\Exceptions\ProtocolException;
use DuckDB\Quack\Messages\ConnectionRequestMessage;
use DuckDB\Quack\Messages\ConnectionResponseMessage;
use DuckDB\Quack\Messages\DisconnectMessage;
use DuckDB\Quack\Messages\FetchRequestMessage;
use DuckDB\Quack\Messages\MessageHeader;
use DuckDB\Quack\Messages\MessageType;
use DuckDB\Quack\Messages\PrepareRequestMessage;
use DuckDB\Quack\Messages\QuackMessage;
use DuckDB\Quack\Messages\SuccessResponseMessage;
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
                authString: $transport->authToken(),
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

    public function quackVersion(): int
    {
        return $this->quackVersion;
    }

    public function serverDuckdbVersion(): ?string
    {
        return $this->serverDuckdbVersion;
    }

    public function serverPlatform(): ?string
    {
        return $this->serverPlatform;
    }

    public function connectionId(): string
    {
        return $this->connectionId;
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
        } catch (QuackError $error) {
            if ($error->isConnectionFatal()) {
                $this->closed = true;
            }
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
        } catch (QuackError $error) {
            if ($error->isConnectionFatal()) {
                $this->closed = true;
            }
            throw $error;
        }
    }

    /**
     * Best-effort cleanup used by the client destructor.
     */
    public function disconnectOnDrop(): void
    {
        if ($this->closed) {
            return;
        }
        $message = new DisconnectMessage(
            MessageHeader::new(MessageType::DisconnectMessage)
                ->withConnection($this->connectionId)
                ->withClientQueryId($this->queryCounter++),
        );
        try {
            $this->transport->withTimeout(3)->send($message, $this->quackVersion);
        } catch (QuackError $e) {
            // The caller is gone; nothing useful can be reported.
        } finally {
            $this->closed = true;
        }
    }
}

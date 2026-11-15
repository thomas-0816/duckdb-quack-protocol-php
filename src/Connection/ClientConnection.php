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
use DuckDB\Quack\Transport\PendingRequest;
use DuckDB\Quack\Transport\Transport;
use DuckDB\Quack\Types\HugeIntParts;
use DuckDB\Quack\Types\LogicalType;

/**
 * One negotiated Quack session, minus the public client facade.
 *
 * This synchronous port has no background keepalive: a client sends a
 * best-effort DISCONNECT when it is dropped.
 */
final class ClientConnection
{
    private const QUACK_VERSION = 3;

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
        $response = $transport->createRequest(
            new ConnectionRequestMessage(
                header: MessageHeader::new(MessageType::ConnectionRequest),
                authString: $transport->authToken(),
                clientPlatform: 'quack-php',
                minSupportedQuackVersion: self::QUACK_VERSION,
                maxSupportedQuackVersion: self::QUACK_VERSION,
                heartbeatTimeoutSeconds: (int) ceil($transport->timeout()),
            ),
            self::QUACK_VERSION,
        )->wait();

        if (!$response instanceof ConnectionResponseMessage) {
            throw new ProtocolException('expected CONNECTION_RESPONSE, got ' . $response->messageType()->name);
        }
        $connectionId = $response->header->connectionId;
        if ($connectionId === null || $connectionId === '') {
            throw new ProtocolException('CONNECTION_RESPONSE did not include a connection id');
        }
        if ($response->quackVersion !== self::QUACK_VERSION) {
            $selected = $response->quackVersion === null ? 'none' : (string) $response->quackVersion;
            throw new ProtocolException("server selected Quack protocol version {$selected}, expected " . self::QUACK_VERSION);
        }

        return new self(
            $transport,
            $connectionId,
            $response->serverDuckdbVersion,
            $response->serverPlatform,
            self::QUACK_VERSION,
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

    /**
     * @param list<LogicalType> $resultTypes schema for decoding result chunks
     * @param list<string>      $resultNames column names for decoding result chunks
     */
    public function send(
        QuackMessage $message,
        array $resultTypes = [],
        array $resultNames = [],
    ): QuackMessage {
        if ($this->closed) {
            throw new ProtocolException('Quack client is not connected');
        }
        return $this->transport->send(
            $message,
            $this->quackVersion,
            $resultTypes,
            $resultNames,
        );
    }

    /**
     * Starts a PREPARE without waiting for its response.
     */
    public function beginPrepare(string $sql): PendingRequest
    {
        if ($this->closed) {
            throw new ProtocolException('Quack client is not connected');
        }
        $queryUuid = new HugeIntParts(0, $this->queryUuidCounter++);
        return $this->transport->createRequest(
            new PrepareRequestMessage(
                header: MessageHeader::new(MessageType::PrepareRequest)
                    ->withConnection($this->connectionId)
                    ->withClientQueryId($this->queryCounter++),
                sql: $sql,
                queryUuid: $queryUuid,
                inlineRows: null,
            ),
            $this->quackVersion,
        );
    }

    /**
     * Starts a FETCH without waiting for its response.
     *
     * @param list<LogicalType> $resultTypes schema for decoding result chunks
     * @param list<string>      $resultNames column names for decoding result chunks
     */
    public function beginFetch(
        HugeIntParts $resultUuid,
        int $batchIndex,
        int $ackIndex,
        array $resultTypes = [],
        array $resultNames = [],
    ): PendingRequest {
        if ($this->closed) {
            throw new ProtocolException('Quack client is not connected');
        }
        return $this->transport->createRequest(
            new FetchRequestMessage(
                header: MessageHeader::new(MessageType::FetchRequest)
                    ->withConnection($this->connectionId)
                    ->withClientQueryId($this->queryCounter++),
                resultUuid: $resultUuid,
                batchIndex: $batchIndex,
                ackIndex: $ackIndex,
            ),
            $this->quackVersion,
            $resultTypes,
            $resultNames,
        );
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
            $this->transport->withTimeout(10.0)->send($message, $this->quackVersion);
        } catch (QuackError) {
            // The caller is gone; nothing useful can be reported.
        } finally {
            $this->closed = true;
        }
    }
}

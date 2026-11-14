<?php

namespace DuckDB\Quack\Connection;

use DuckDB\Quack\Error\QuackError;
use DuckDB\Quack\Exceptions\HttpStatusException;
use DuckDB\Quack\Exceptions\ProtocolException;
use DuckDB\Quack\Exceptions\ServerException;
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
use DuckDB\Quack\Types\LogicalType;

/**
 * One negotiated Quack session, minus the public client facade.
 *
 * This synchronous port has no background keepalive: a client sends a
 * best-effort DISCONNECT when it is dropped.
 */
final class ClientConnection
{
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
        $quackVersion = 3;
        $response = $transport->send(
            new ConnectionRequestMessage(
                header: MessageHeader::new(MessageType::ConnectionRequest),
                authString: $transport->authToken(),
                clientPlatform: 'quack-php',
                minSupportedQuackVersion: $quackVersion,
                maxSupportedQuackVersion: $quackVersion,
                heartbeatTimeoutSeconds: (int) ceil($transport->timeout()),
            ),
            $quackVersion,
        );

        if (!$response instanceof ConnectionResponseMessage) {
            throw new ProtocolException('expected CONNECTION_RESPONSE, got ' . $response->messageType()->name);
        }
        $connectionId = $response->header->connectionId;
        if ($connectionId === null || $connectionId === '') {
            throw new ProtocolException('CONNECTION_RESPONSE did not include a connection id');
        }
        if ($response->quackVersion !== $quackVersion) {
            $selected = $response->quackVersion === null ? 'none' : (string) $response->quackVersion;
            throw new ProtocolException("server selected Quack protocol version {$selected}, expected {$quackVersion}");
        }

        return new self(
            $transport,
            $connectionId,
            $response->serverDuckdbVersion,
            $response->serverPlatform,
            $quackVersion,
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
        $this->ensureOpen();
        return $this->transport->send(
            $message,
            $this->quackVersion,
            $resultTypes,
            $resultNames,
        );
    }

    public function prepare(string $sql): QuackMessage
    {
        $this->ensureOpen();
        $queryUuid = new HugeIntParts(0, $this->queryUuidCounter++);
        return $this->send(new PrepareRequestMessage(
            header: $this->scopedHeader(MessageType::PrepareRequest),
            sql: $sql,
            queryUuid: $queryUuid,
            inlineRows: null,
        ));
    }

    /**
     * @param list<LogicalType> $resultTypes schema for decoding result chunks
     * @param list<string>      $resultNames column names for decoding result chunks
     */
    public function fetch(
        HugeIntParts $resultUuid,
        int $batchIndex,
        int $ackIndex,
        array $resultTypes = [],
        array $resultNames = [],
    ): QuackMessage {
        $this->ensureOpen();
        return $this->send(new FetchRequestMessage(
            header: $this->scopedHeader(MessageType::FetchRequest),
            resultUuid: $resultUuid,
            batchIndex: $batchIndex,
            ackIndex: $ackIndex,
        ), $resultTypes, $resultNames);
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
            $this->scopedHeader(MessageType::DisconnectMessage),
        );
        try {
            $this->transport->withTimeout(10.0)->send($message, $this->quackVersion);
        } catch (QuackError) {
            // The caller is gone; nothing useful can be reported.
        } finally {
            $this->closed = true;
        }
    }

    public function protocolFailure(string $message): ProtocolException
    {
        return new ProtocolException($message);
    }

    private function scopedHeader(MessageType $messageType): MessageHeader
    {
        return MessageHeader::new($messageType)
            ->withConnection($this->connectionId)
            ->withClientQueryId($this->queryCounter++);
    }

    private function ensureOpen(): void
    {
        if ($this->closed) {
            throw new ProtocolException('Quack client is not connected');
        }
    }

    /**
     * Whether an error means the server probably does not understand the
     * protocol version that was attempted, so a lower version may work.
     */
    public static function isVersionNegotiationError(QuackError $error): bool
    {
        if ($error instanceof HttpStatusException) {
            return $error->status() === 500;
        }
        $message = match (true) {
            $error instanceof ProtocolException => $error->getMessage(),
            $error instanceof ServerException => $error->serverError()->message(),
            default => null,
        };
        if ($message === null) {
            return false;
        }
        $message = strtolower($message);
        return str_contains($message, 'quack version')
            || str_contains($message, 'protocol version')
            || str_contains($message, 'deserialize')
            || str_contains($message, 'end of object')
            || str_contains($message, 'unexpected field');
    }
}

<?php

declare(strict_types=1);

namespace Quack\Protocol\Connection;

use Quack\Protocol\Error\QuackError;
use Quack\Protocol\Exceptions\HttpStatusException;
use Quack\Protocol\Exceptions\ProtocolException;
use Quack\Protocol\Exceptions\ServerException;
use Quack\Protocol\Messages\ConnectionRequestMessage;
use Quack\Protocol\Messages\ConnectionResponseMessage;
use Quack\Protocol\Messages\DisconnectMessage;
use Quack\Protocol\Messages\FetchRequestMessage;
use Quack\Protocol\Messages\MessageHeader;
use Quack\Protocol\Messages\MessageType;
use Quack\Protocol\Messages\PrepareRequestMessage;
use Quack\Protocol\Messages\QuackMessage;
use Quack\Protocol\QuackConnectionInfo;
use Quack\Protocol\QuackVersion;
use Quack\Protocol\Transport\Transport;
use Quack\Protocol\Types\HugeIntParts;

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

    private function __construct(
        private readonly Transport $transport,
        private readonly string $connectionId,
        private readonly QuackVersion $quackVersion,
        private readonly ConnectionState $state,
    ) {
    }

    /**
     * @return array{0:self,1:QuackConnectionInfo}
     */
    public static function connect(
        Transport $transport,
        string $authToken,
    ): array {
        $minVersion = QuackVersion::fromNumber(Constants::MIN_QUACK_VERSION);
        $maxVersion = QuackVersion::fromNumber(Constants::MAX_QUACK_VERSION);
        if ($minVersion === null || $maxVersion === null) {
            throw new ProtocolException('invalid supported Quack protocol version range');
        }
        if ($minVersion->number() > $maxVersion->number()) {
            throw new ProtocolException(
                "minimum Quack protocol version {$minVersion} exceeds maximum {$maxVersion}",
            );
        }

        $versions = array_values(array_filter(
            QuackVersion::supported(),
            static fn (QuackVersion $version): bool => $version->number() >= $minVersion->number()
                && $version->number() <= $maxVersion->number(),
        ));
        if ($versions === []) {
            throw new ProtocolException(
                'supported Quack protocol versions are 1 and 3, requested range was '
                . "{$minVersion}..{$maxVersion}",
            );
        }

        $previousError = null;
        $lastIndex = count($versions) - 1;
        foreach ($versions as $index => $version) {
            try {
                $response = $transport->send(
                    new ConnectionRequestMessage(
                        header: MessageHeader::new(MessageType::ConnectionRequest),
                        authString: $authToken,
                        clientPlatform: 'quack-php',
                        minSupportedQuackVersion: $version->number(),
                        maxSupportedQuackVersion: $version->number(),
                        heartbeatTimeoutSeconds: (int) ceil($transport->timeout()),
                    ),
                    $version,
                );
            } catch (QuackError $error) {
                if ($index < $lastIndex
                    && $version->number() === 3
                    && self::isVersionNegotiationError($error)) {
                    $previousError = $error;
                    continue;
                }
                if ($previousError !== null && self::isVersionNegotiationError($error)) {
                    throw $previousError;
                }
                throw $error;
            }

            if (!$response instanceof ConnectionResponseMessage) {
                throw new ProtocolException(
                    'expected CONNECTION_RESPONSE, got ' . $response->messageType()->name,
                );
            }
            $connectionId = $response->header->connectionId;
            if ($connectionId === null || $connectionId === '') {
                throw new ProtocolException(
                    'CONNECTION_RESPONSE did not include a connection id',
                );
            }
            if ((string) $response->quackVersion !== (string) $version->number()) {
                $selected = $response->quackVersion === null
                    ? 'none'
                    : (string) $response->quackVersion;
                throw new ProtocolException(
                    "server selected Quack protocol version {$selected}, expected {$version}",
                );
            }

            $state = new ConnectionState();
            $connection = new self(
                $transport,
                $connectionId,
                $version,
                $state,
            );
            return [
                $connection,
                new QuackConnectionInfo(
                    serverDuckdbVersion: $response->serverDuckdbVersion,
                    serverPlatform: $response->serverPlatform,
                    quackVersion: $version,
                ),
            ];
        }

        throw new ProtocolException(
            'no compatible Quack protocol version was attempted',
        );
    }

    public function state(): ConnectionState
    {
        return $this->state;
    }

    public function quackVersion(): QuackVersion
    {
        return $this->quackVersion;
    }

    public function connectionId(): string
    {
        return $this->connectionId;
    }

    public function send(QuackMessage $message): QuackMessage
    {
        $this->ensureOpen();
        return $this->transport->send($message, $this->quackVersion);
    }

    public function prepare(string $sql): QuackMessage
    {
        $this->ensureOpen();
        $queryUuid = new HugeIntParts(0, $this->queryUuidCounter++);
        return $this->send(new PrepareRequestMessage(
            header: $this->scopedHeader(MessageType::PrepareRequest),
            sql: $sql,
            queryUuid: $this->quackVersion->number() === 3 ? $queryUuid : null,
            inlineRows: null,
        ));
    }

    public function fetch(
        HugeIntParts $resultUuid,
        int|string $batchIndex,
        int|string $ackIndex,
    ): QuackMessage {
        $this->ensureOpen();
        return $this->send(new FetchRequestMessage(
            header: $this->scopedHeader(MessageType::FetchRequest),
            resultUuid: $resultUuid,
            batchIndex: $this->quackVersion->number() === 3 ? $batchIndex : null,
            ackIndex: $this->quackVersion->number() === 3 ? $ackIndex : null,
        ));
    }

    /**
     * Best-effort cleanup used by the client destructor.
     */
    public function disconnectOnDrop(): void
    {
        if ($this->state->isClosed()) {
            return;
        }
        $message = new DisconnectMessage(
            $this->scopedHeader(MessageType::DisconnectMessage),
        );
        $timeout = min(
            $this->transport->timeout(),
            (float) Constants::DISCONNECT_ON_DROP_TIMEOUT_SECS,
        );
        try {
            $this->transport->withTimeout($timeout)->send($message, $this->quackVersion);
        } catch (QuackError) {
            // The caller is gone; nothing useful can be reported.
        } finally {
            $this->state->close();
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
        if ($this->state->isClosed()) {
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

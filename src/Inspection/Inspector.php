<?php

namespace DuckDB\Quack\Inspection;

use DuckDB\Quack\Error\QuackError;
use DuckDB\Quack\Error\ServerError;
use DuckDB\Quack\Exceptions\InspectionException;
use DuckDB\Quack\Messages\CancelMessage;
use DuckDB\Quack\Messages\ConnectionResponseMessage;
use DuckDB\Quack\Messages\DisconnectMessage;
use DuckDB\Quack\Messages\ErrorMessage;
use DuckDB\Quack\Messages\MessageCodec;
use DuckDB\Quack\Messages\MessageHeader;
use DuckDB\Quack\Messages\MessageType;
use DuckDB\Quack\Messages\QuackMessage;
use DuckDB\Quack\Messages\SuccessResponseMessage;
use DuckDB\Quack\QuackVersion;
use DuckDB\Quack\Transport\BinaryReader;
use DuckDB\Quack\Types\HugeIntParts;

/**
 * Bounded metadata inspection of raw Quack messages; payloads remain opaque.
 *
 * Incomplete input is distinct from invalid input. Callers must stop
 * collecting a header at MAX_MESSAGE_HEADER_BYTES.
 */
final class Inspector
{
    public const MAX_MESSAGE_HEADER_BYTES = 4096;
    public const MAX_CONTROL_RESPONSE_BYTES = 16 * 1024;
    public const MAX_CONNECTION_ID_BYTES = 256;

    public static function inspectMessageHeader(string $prefix): InspectionMessageHeader
    {
        $bounded = substr($prefix, 0, self::MAX_MESSAGE_HEADER_BYTES);
        $reader = new BinaryReader($bounded);
        try {
            $header = MessageCodec::decodeHeader($reader);
        } catch (\Throwable) {
            if ($reader->isIncomplete()) {
                throw strlen($bounded) === self::MAX_MESSAGE_HEADER_BYTES
                    ? InspectionException::tooLarge()
                    : InspectionException::incomplete();
            }
            throw InspectionException::invalid();
        }

        $connectionId = $header->connectionId;
        if ($connectionId !== null
            && strlen($connectionId) > self::MAX_CONNECTION_ID_BYTES) {
            throw InspectionException::tooLarge();
        }

        return new InspectionMessageHeader(
            $header->messageType,
            $connectionId,
            $header->clientQueryId,
            strlen($bounded) - $reader->remaining(),
        );
    }

    /**
     * Encodes a CANCEL for whatever query runs on the connection.
     */
    public static function encodeCancel(string $connectionId): string
    {
        return self::encodeControl(new CancelMessage(
            self::controlHeader(MessageType::CancelRequest, $connectionId),
            new HugeIntParts(0, 0),
        ));
    }

    /**
     * Encodes a DISCONNECT that closes the connection on a v1 or v3 server.
     */
    public static function encodeDisconnect(string $connectionId): string
    {
        return self::encodeControl(new DisconnectMessage(
            self::controlHeader(MessageType::DisconnectMessage, $connectionId),
        ));
    }

    public static function inspectControlResponse(string $bytes): ControlResponse
    {
        if (strlen($bytes) > self::MAX_CONTROL_RESPONSE_BYTES) {
            throw InspectionException::tooLarge();
        }
        $header = self::inspectMessageHeader($bytes);
        if (!in_array($header->operation, [
            MessageType::ConnectionResponse,
            MessageType::SuccessResponse,
            MessageType::ErrorResponse,
        ], true)) {
            throw InspectionException::invalid();
        }

        if ($header->operation === MessageType::ErrorResponse) {
            $reader = new BinaryReader(substr($bytes, $header->encodedLen));
            try {
                $error = $reader->readObject(
                    static fn (BinaryReader $object): ServerError => MessageCodec::readErrorFields($object),
                );
                $reader->assertEof();
            } catch (\Throwable) {
                throw InspectionException::invalid();
            }
            return ControlResponse::error($error);
        }

        try {
            $message = MessageCodec::decodeMessageForVersion($bytes, QuackVersion::v3());
        } catch (QuackError) {
            throw InspectionException::invalid();
        }

        if ($message instanceof ConnectionResponseMessage) {
            $quackVersion = $message->quackVersion === null
                ? null
                : QuackVersion::fromNumber($message->quackVersion);
            if ($message->quackVersion !== null && $quackVersion === null) {
                throw InspectionException::invalid();
            }
            $heartbeatTimeout = null;
            if ($quackVersion !== null
                && $quackVersion->number() === 3
                && $message->heartbeatTimeoutSeconds !== null) {
                $seconds = (int) $message->heartbeatTimeoutSeconds;
                if ($seconds >= 1) {
                    $heartbeatTimeout = $seconds;
                }
            }
            $connectionId = $message->header->connectionId;
            if ($connectionId === null || $connectionId === '') {
                throw InspectionException::invalid();
            }
            return ControlResponse::connected(
                $connectionId,
                $quackVersion,
                $heartbeatTimeout,
            );
        }

        if ($message instanceof SuccessResponseMessage) {
            return ControlResponse::success();
        }

        throw InspectionException::invalid();
    }

    /**
     * Encodes a bounded error response using the protocol's shared codec.
     */
    public static function encodeErrorResponse(
        InspectionMessageHeader $header,
        ServerError $error,
    ): string {
        if ($header->operation !== MessageType::ErrorResponse) {
            throw InspectionException::invalid();
        }
        if ($header->connectionId !== null
            && strlen($header->connectionId) > self::MAX_CONNECTION_ID_BYTES) {
            throw InspectionException::tooLarge();
        }

        $total = strlen($error->message())
            + strlen($error->exceptionTypeRaw() ?? '');
        foreach ($error->extraInfo() as $key => $value) {
            $total += strlen($key) + strlen($value);
        }
        if ($total > self::MAX_CONTROL_RESPONSE_BYTES) {
            throw InspectionException::tooLarge();
        }

        $bytes = self::encodeControl(new ErrorMessage(
            new MessageHeader(
                MessageType::ErrorResponse,
                $header->connectionId,
                $header->clientQueryId,
            ),
            $error,
        ));
        if (strlen($bytes) > self::MAX_CONTROL_RESPONSE_BYTES) {
            throw InspectionException::tooLarge();
        }
        return $bytes;
    }

    private static function controlHeader(
        MessageType $operation,
        string $connectionId,
    ): MessageHeader {
        if ($connectionId === '') {
            throw InspectionException::invalid();
        }
        if (strlen($connectionId) > self::MAX_CONNECTION_ID_BYTES) {
            throw InspectionException::tooLarge();
        }
        return MessageHeader::new($operation)->withConnection($connectionId);
    }

    private static function encodeControl(QuackMessage $message): string
    {
        try {
            return MessageCodec::encodeMessageForVersion(
                $message,
                QuackVersion::v3(),
            );
        } catch (QuackError) {
            throw InspectionException::invalid();
        }
    }
}

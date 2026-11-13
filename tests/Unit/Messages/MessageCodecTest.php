<?php

namespace DuckDB\Quack\Tests\Unit\Messages;

use DuckDB\Quack\Error\ServerError;
use DuckDB\Quack\Messages\CancelMessage;
use DuckDB\Quack\Messages\ConnectionRequestMessage;
use DuckDB\Quack\Messages\DisconnectMessage;
use DuckDB\Quack\Messages\ErrorMessage;
use DuckDB\Quack\Messages\FetchRequestMessage;
use DuckDB\Quack\Messages\HeartbeatRequestMessage;
use DuckDB\Quack\Messages\MessageCodec;
use DuckDB\Quack\Messages\MessageHeader;
use DuckDB\Quack\Messages\MessageType;
use DuckDB\Quack\Messages\PrepareRequestMessage;
use DuckDB\Quack\QuackVersion;
use DuckDB\Quack\Types\HugeIntParts;
use PHPUnit\Framework\TestCase;

final class MessageCodecTest extends TestCase
{
    public function testConnectionRequestRoundTripsOverV3(): void
    {
        $message = new ConnectionRequestMessage(
            new MessageHeader(MessageType::ConnectionRequest, 'conn-1'),
            authString: 'secret',
            clientDuckdbVersion: '1.5.0',
            clientPlatform: 'php',
            minSupportedQuackVersion: 3,
            maxSupportedQuackVersion: 3,
            clientId: 'client-1',
            heartbeatTimeoutSeconds: 60,
        );

        $decoded = MessageCodec::decodeMessageForVersion(
            MessageCodec::encodeMessageForVersion($message, QuackVersion::v3()),
            QuackVersion::v3(),
        );

        self::assertInstanceOf(ConnectionRequestMessage::class, $decoded);
        self::assertEquals($message, $decoded);
    }

    public function testConnectionRequestRoundTripsOverV1(): void
    {
        $message = new ConnectionRequestMessage(
            new MessageHeader(MessageType::ConnectionRequest, 'conn-1'),
            authString: 'secret',
            clientDuckdbVersion: '1.5.0',
            clientPlatform: 'php',
            minSupportedQuackVersion: 1,
            maxSupportedQuackVersion: 1,
        );

        $decoded = MessageCodec::decodeMessageForVersion(
            MessageCodec::encodeMessageForVersion($message, QuackVersion::v1()),
            QuackVersion::v1(),
        );

        self::assertInstanceOf(ConnectionRequestMessage::class, $decoded);
        self::assertEquals($message, $decoded);
    }

    public function testPrepareRequestRoundTrips(): void
    {
        $message = new PrepareRequestMessage(
            new MessageHeader(MessageType::PrepareRequest, 'conn-1', 7),
            "SELECT 1; -- a 'quoted' comment",
            new HugeIntParts(0, 42),
            10,
        );

        $decoded = MessageCodec::decodeMessageForVersion(
            MessageCodec::encodeMessageForVersion($message, QuackVersion::v3()),
            QuackVersion::v3(),
        );

        self::assertInstanceOf(PrepareRequestMessage::class, $decoded);
        self::assertEquals($message, $decoded);
    }

    public function testHeartbeatCancelAndDisconnectRoundTrip(): void
    {
        $messages = [
            new HeartbeatRequestMessage(
                new MessageHeader(MessageType::HeartbeatRequest, 'conn-1'),
            ),
            new CancelMessage(
                new MessageHeader(MessageType::CancelRequest, 'conn-1'),
                new HugeIntParts(1, 2),
            ),
            new DisconnectMessage(
                new MessageHeader(MessageType::DisconnectMessage, 'conn-1'),
            ),
        ];

        foreach ($messages as $message) {
            $decoded = MessageCodec::decodeMessageForVersion(
                MessageCodec::encodeMessageForVersion($message, QuackVersion::v3()),
                QuackVersion::v3(),
            );

            self::assertEquals($message, $decoded);
        }
    }

    public function testFetchRequestRoundTrips(): void
    {
        $message = new FetchRequestMessage(
            new MessageHeader(MessageType::FetchRequest, 'conn-1', 9),
            new HugeIntParts(-1, 3),
            batchIndex: 2,
            ackIndex: 1,
        );

        $decoded = MessageCodec::decodeMessageForVersion(
            MessageCodec::encodeMessageForVersion($message, QuackVersion::v3()),
            QuackVersion::v3(),
        );

        self::assertInstanceOf(FetchRequestMessage::class, $decoded);
        self::assertEquals($message, $decoded);
    }

    public function testErrorMessagePreservesErrorMetadata(): void
    {
        $error = ServerError::new('catalog exploded')
            ->withExceptionType('Catalog')
            ->withExtraInfo('error_subtype', 'MISSING_ENTRY')
            ->withExtraInfo('position', '7')
            ->withInvalidation(true);
        $message = new ErrorMessage(
            new MessageHeader(MessageType::ErrorResponse, 'conn-1', 3),
            $error,
        );

        $decoded = MessageCodec::decodeMessageForVersion(
            MessageCodec::encodeMessageForVersion($message, QuackVersion::v3()),
            QuackVersion::v3(),
        );

        self::assertInstanceOf(ErrorMessage::class, $decoded);
        self::assertSame('catalog exploded', $decoded->error->message());
        self::assertSame('Catalog', $decoded->error->exceptionTypeRaw());
        self::assertSame('MISSING_ENTRY', $decoded->error->errorSubtypeRaw());
        self::assertTrue($decoded->error->mustInvalidate());
        self::assertSame(
            ['error_subtype' => 'MISSING_ENTRY', 'position' => '7'],
            $decoded->error->extraInfo(),
        );
    }
}

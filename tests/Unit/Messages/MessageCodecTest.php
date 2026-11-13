<?php

namespace Quack\Protocol\Tests\Unit\Messages;

use PHPUnit\Framework\TestCase;
use Quack\Protocol\Error\ServerError;
use Quack\Protocol\Messages\CancelMessage;
use Quack\Protocol\Messages\ConnectionRequestMessage;
use Quack\Protocol\Messages\DisconnectMessage;
use Quack\Protocol\Messages\ErrorMessage;
use Quack\Protocol\Messages\FetchRequestMessage;
use Quack\Protocol\Messages\HeartbeatRequestMessage;
use Quack\Protocol\Messages\MessageCodec;
use Quack\Protocol\Messages\MessageHeader;
use Quack\Protocol\Messages\MessageType;
use Quack\Protocol\Messages\PrepareRequestMessage;
use Quack\Protocol\QuackVersion;
use Quack\Protocol\Types\HugeIntParts;

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

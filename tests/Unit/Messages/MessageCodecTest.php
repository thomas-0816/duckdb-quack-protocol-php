<?php

namespace DuckDB\Quack\Tests\Unit\Messages;

use DuckDB\Quack\Chunk\DataChunk;
use DuckDB\Quack\Chunk\DecodedVector;
use DuckDB\Quack\Error\ServerError;
use DuckDB\Quack\Messages\AcknowledgementMessage;
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
use DuckDB\Quack\Messages\PrepareResponseMessage;
use DuckDB\Quack\Types\HugeIntParts;
use DuckDB\Quack\Types\LogicalTypes;
use DuckDB\Quack\Types\Value;
use DuckDB\Quack\Types\VectorType;
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
            MessageCodec::encodeMessageForVersion($message, 3),
            3,
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
            MessageCodec::encodeMessageForVersion($message, 3),
            3,
        );

        self::assertInstanceOf(PrepareRequestMessage::class, $decoded);
        self::assertEquals($message, $decoded);
    }

    public function testPrepareResponseRoundTripsResultMetadataThroughChunks(): void
    {
        $type = LogicalTypes::integer();
        $chunk = new DataChunk(
            rowCount: 1,
            types: [$type],
            columns: [new DecodedVector(VectorType::Flat, [Value::int(42)])],
            resultNames: ['answer'],
        );
        $message = new PrepareResponseMessage(
            new MessageHeader(MessageType::PrepareResponse, 'conn-1', 11),
            false,
            [$chunk],
            new HugeIntParts(0, 5),
        );

        $decoded = MessageCodec::decodeMessageForVersion(
            MessageCodec::encodeMessageForVersion($message, 3),
            3,
        );

        self::assertInstanceOf(PrepareResponseMessage::class, $decoded);
        self::assertCount(1, $decoded->results);
        self::assertSame([42], $decoded->results[0]->columns[0]->values);
        self::assertEquals([$type], $decoded->results[0]->types);
        self::assertSame(['answer'], $decoded->results[0]->resultNames);
    }

    public function testPrepareResponseRetainsResultNamesWithoutChunks(): void
    {
        $message = new PrepareResponseMessage(
            new MessageHeader(MessageType::PrepareResponse, 'conn-1', 11),
            true,
            [],
            new HugeIntParts(0, 5),
            ['answer'],
        );

        $decoded = MessageCodec::decodeMessageForVersion(
            MessageCodec::encodeMessageForVersion($message, 3),
            3,
        );

        self::assertInstanceOf(PrepareResponseMessage::class, $decoded);
        self::assertTrue($decoded->needsMoreFetch);
        self::assertSame([], $decoded->results);
        self::assertSame(['answer'], $decoded->resultNames);
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
            new AcknowledgementMessage(
                new MessageHeader(MessageType::Acknowledgement, 'conn-1'),
                new HugeIntParts(1, 2),
            ),
            new AcknowledgementMessage(
                new MessageHeader(MessageType::Acknowledgement, 'conn-1'),
                new HugeIntParts(0, 0),
            ),
            new DisconnectMessage(
                new MessageHeader(MessageType::DisconnectMessage, 'conn-1'),
            ),
        ];

        foreach ($messages as $message) {
            $decoded = MessageCodec::decodeMessageForVersion(
                MessageCodec::encodeMessageForVersion($message, 3),
                3,
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
            MessageCodec::encodeMessageForVersion($message, 3),
            3,
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
            MessageCodec::encodeMessageForVersion($message, 3),
            3,
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

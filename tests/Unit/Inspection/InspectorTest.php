<?php

namespace DuckDB\Quack\Tests\Unit\Inspection;

use DuckDB\Quack\Error\InspectionErrorKind;
use DuckDB\Quack\Error\ServerError;
use DuckDB\Quack\Exceptions\InspectionException;
use DuckDB\Quack\Inspection\ControlResponseKind;
use DuckDB\Quack\Inspection\InspectionMessageHeader;
use DuckDB\Quack\Inspection\Inspector;
use DuckDB\Quack\Messages\MessageType;
use DuckDB\Quack\Transport\BinaryWriter;
use PHPUnit\Framework\TestCase;

final class InspectorTest extends TestCase
{
    public function testInspectsCancelHeaderWithoutDecodingTheBody(): void
    {
        $bytes = Inspector::encodeCancel('conn-1');
        $header = Inspector::inspectMessageHeader($bytes);

        self::assertSame(MessageType::CancelRequest, $header->operation);
        self::assertSame('conn-1', $header->connectionId);
        self::assertNull($header->clientQueryId);
        self::assertGreaterThan(0, $header->encodedLen);
        self::assertLessThan(strlen($bytes), $header->encodedLen);
    }

    public function testInspectsDisconnectHeader(): void
    {
        $bytes = Inspector::encodeDisconnect('conn-2');
        $header = Inspector::inspectMessageHeader($bytes);

        self::assertSame(MessageType::DisconnectMessage, $header->operation);
        self::assertSame('conn-2', $header->connectionId);
    }

    public function testInspectsErrorControlResponse(): void
    {
        $error = ServerError::new('bad query')
            ->withExceptionType('Parser')
            ->withExtraInfo('error_subtype', 'SYNTAX_ERROR');
        $header = new InspectionMessageHeader(
            MessageType::ErrorResponse,
            'conn-1',
            7,
            0,
        );

        $bytes = Inspector::encodeErrorResponse($header, $error);
        $response = Inspector::inspectControlResponse($bytes);

        self::assertSame(ControlResponseKind::Error, $response->kind);
        self::assertNotNull($response->error);
        self::assertSame('bad query', $response->error->message());
        self::assertSame('Parser', $response->error->exceptionTypeRaw());
        self::assertSame('SYNTAX_ERROR', $response->error->errorSubtypeRaw());
    }

    public function testRejectsWrongOperationForErrorResponse(): void
    {
        $this->expectException(InspectionException::class);

        Inspector::encodeErrorResponse(
            new InspectionMessageHeader(MessageType::SuccessResponse, null, null, 0),
            ServerError::new('nope'),
        );
    }

    public function testIncompleteHeaderIsReportedSeparately(): void
    {
        try {
            Inspector::inspectMessageHeader(substr(Inspector::encodeCancel('conn-1'), 0, 2));
            self::fail('accepted incomplete header');
        } catch (InspectionException $error) {
            self::assertSame(InspectionErrorKind::Incomplete, $error->kind());
        }
    }

    public function testInvalidHeaderIsReported(): void
    {
        try {
            Inspector::inspectMessageHeader("\xff\xff\xff\xff");
            self::fail('accepted invalid header');
        } catch (InspectionException $error) {
            self::assertSame(InspectionErrorKind::Invalid, $error->kind());
        }
    }

    public function testOversizedHeaderIsRejected(): void
    {
        $writer = BinaryWriter::new();
        $writer->writeObject(static function (BinaryWriter $object): void {
            $object->writeField(1, static fn (BinaryWriter $w) => $w->writeUleb(1));
            // Claim a connection id longer than the bounded prefix can hold.
            $object->writeField(2, static fn (BinaryWriter $w) => $w->writeUleb(5000));
        });
        $bytes = str_pad(
            $writer->intoBytes(),
            Inspector::MAX_MESSAGE_HEADER_BYTES,
            "\0",
        );

        try {
            Inspector::inspectMessageHeader($bytes);
            self::fail('accepted oversized header');
        } catch (InspectionException $error) {
            self::assertSame(InspectionErrorKind::TooLarge, $error->kind());
        }
    }

    public function testOversizedControlResponseIsRejected(): void
    {
        $this->expectException(InspectionException::class);

        Inspector::inspectControlResponse(str_repeat('x', Inspector::MAX_CONTROL_RESPONSE_BYTES + 1));
    }

    public function testEmptyConnectionIdIsRejectedWhenEncodingControls(): void
    {
        $this->expectException(InspectionException::class);

        Inspector::encodeCancel('');
    }
}

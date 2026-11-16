<?php

namespace DuckDB\Quack\Messages;

use DuckDB\Quack\Chunk\DataChunk;
use DuckDB\Quack\Chunk\DataChunkCodec;
use DuckDB\Quack\Error\ServerError;
use DuckDB\Quack\Exceptions\ProtocolException;
use DuckDB\Quack\Transport\BinaryReader;
use DuckDB\Quack\Transport\BinaryWriter;
use DuckDB\Quack\Types\HugeIntParts;
use DuckDB\Quack\Types\LogicalTypeCodec;

/**
 * Encodes and decodes Quack protocol version 3 messages.
 */
final class MessageCodec
{
    /** u64::MAX, used as the sentinel for an absent optional index. */
    public const OPTIONAL_INDEX_INVALID = '18446744073709551615';

    public static function encodeMessageForVersion(
        QuackMessage $message,
        int $version,
    ): string {
        $writer = BinaryWriter::new();
        self::encodeHeader($writer, $message->header());
        match ($version) {
            3 => self::encodeBodyV3($writer, $message),
            default => throw new ProtocolException(
                "unsupported Quack protocol version {$version}",
            ),
        };
        return $writer->intoBytes();
    }

    public static function decodeMessageForVersion(
        string $bytes,
        int $version,
    ): QuackMessage {
        $reader = new BinaryReader($bytes);
        $header = self::decodeHeader($reader);
        $message = match ($version) {
            3 => self::decodeBodyV3($reader, $header),
            default => throw new ProtocolException("unsupported Quack protocol version {$version}"),
        };
        $reader->assertEof();
        return $message;
    }

    public static function encodeHeader(BinaryWriter $writer, MessageHeader $header): void
    {
        $writer->writeObject(static function (BinaryWriter $object) use ($header): void {
            $object->writeField(
                1,
                static fn (BinaryWriter $w) => $w->writeUleb($header->messageType->value),
            );
            if ($header->connectionId !== null && $header->connectionId !== '') {
                $object->writeField(
                    2,
                    static fn (BinaryWriter $w) => $w->writeString($header->connectionId),
                );
            }
            $object->writeField(
                3,
                static fn (BinaryWriter $w) => $w->writeUleb(
                    $header->clientQueryId ?? self::OPTIONAL_INDEX_INVALID,
                ),
            );
        });
    }

    public static function decodeHeader(BinaryReader $reader): MessageHeader
    {
        return $reader->readObject(static function (BinaryReader $object): MessageHeader {
            $messageType = MessageType::fromId(
                $object->readRequiredField(
                    1,
                    static fn (BinaryReader $r) => $r->readUlebU64(),
                ),
            );
            $connectionId = $object->readOptionalField(
                2,
                static fn (BinaryReader $r) => $r->readString(),
                null,
            );
            if ($connectionId === '') {
                $connectionId = null;
            }
            $rawClientQueryId = $object->readRequiredField(
                3,
                static fn (BinaryReader $r) => $r->readUlebU64(),
            );
            $clientQueryId = (string) $rawClientQueryId === self::OPTIONAL_INDEX_INVALID
                ? null
                : $rawClientQueryId;
            return new MessageHeader($messageType, $connectionId, $clientQueryId);
        });
    }

    private static function encodeBodyV3(BinaryWriter $writer, QuackMessage $message): void
    {
        if ($message instanceof ConnectionRequestMessage) {
            $writer->writeObject(static function (BinaryWriter $object) use ($message): void {
                self::writeOptionalString($object, 1, $message->authString);
                self::writeOptionalString($object, 2, $message->clientDuckdbVersion);
                self::writeOptionalString($object, 3, $message->clientPlatform);
                self::writeOptionalIndexDefaultZero(
                    $object,
                    4,
                    $message->minSupportedQuackVersion,
                );
                self::writeOptionalIndexDefaultZero(
                    $object,
                    5,
                    $message->maxSupportedQuackVersion,
                );
                self::writeOptionalString($object, 6, $message->clientId);
                self::writeOptionalIndexDefaultZero(
                    $object,
                    7,
                    $message->heartbeatTimeoutSeconds,
                );
            });
            return;
        }

        if ($message instanceof PrepareRequestMessage) {
            if ($message->queryUuid === null) {
                throw new ProtocolException('protocol v3 PREPARE_REQUEST requires query_uuid');
            }
            $writer->writeObject(static function (BinaryWriter $object) use ($message): void {
                self::writeOptionalString($object, 1, $message->sql);
                $object->writeField(
                    2,
                    static fn (BinaryWriter $w) => $w->writeHugeIntParts($message->queryUuid),
                );
                if ($message->inlineRows !== null) {
                    $object->writeField(
                        3,
                        static fn (BinaryWriter $w) => $w->writeUleb($message->inlineRows),
                    );
                }
            });
            return;
        }

        if ($message instanceof FetchRequestMessage) {
            if ($message->batchIndex === null) {
                throw new ProtocolException('protocol v3 FETCH_REQUEST requires batch_index');
            }
            $writer->writeObject(static function (BinaryWriter $object) use ($message): void {
                $object->writeField(
                    1,
                    static fn (BinaryWriter $w) => $w->writeHugeIntParts($message->resultUuid),
                );
                self::writeOptionalIndexDefaultZero($object, 2, $message->batchIndex);
                self::writeOptionalIndexDefaultZero(
                    $object,
                    3,
                    $message->ackIndex ?? 0,
                );
            });
            return;
        }

        if ($message instanceof DisconnectMessage || $message instanceof HeartbeatRequestMessage) {
            $writer->writeObject(static function (BinaryWriter $_): void {
            });
            return;
        }

        if ($message instanceof CancelMessage) {
            $writer->writeObject(static function (BinaryWriter $object) use ($message): void {
                $object->writeField(
                    1,
                    static fn (BinaryWriter $w) => $w->writeHugeIntParts($message->queryUuid),
                );
            });
            return;
        }

        if ($message instanceof AcknowledgementMessage) {
            $writer->writeObject(static function (BinaryWriter $object) use ($message): void {
                if ($message->queryUuid->upper !== 0 || (string) $message->queryUuid->lower !== '0') {
                    $object->writeField(
                        1,
                        static fn (BinaryWriter $w) => $w->writeHugeIntParts($message->queryUuid),
                    );
                }
            });
            return;
        }

        if ($message instanceof ErrorMessage) {
            $writer->writeObject(
                static fn (BinaryWriter $object) => self::writeErrorFields(
                    $object,
                    $message->error,
                ),
            );
            return;
        }

        throw new ProtocolException('unsupported Quack message ' . $message::class);
    }

    private static function decodeBodyV3(BinaryReader $reader, MessageHeader $header): QuackMessage
    {
        return match ($header->messageType) {
            MessageType::ConnectionRequest => $reader->readObject(
                static function (BinaryReader $object) use ($header): ConnectionRequestMessage {
                    return new ConnectionRequestMessage(
                        header: $header,
                        authString: self::readOptionalString($object, 1),
                        clientDuckdbVersion: self::readOptionalString($object, 2),
                        clientPlatform: self::readOptionalString($object, 3),
                        minSupportedQuackVersion: $object->readOptionalField(
                            4,
                            static fn (BinaryReader $r) => $r->readUlebU64(),
                            0,
                        ),
                        maxSupportedQuackVersion: $object->readOptionalField(
                            5,
                            static fn (BinaryReader $r) => $r->readUlebU64(),
                            0,
                        ),
                        clientId: self::readOptionalString($object, 6),
                        heartbeatTimeoutSeconds: $object->readOptionalField(
                            7,
                            static fn (BinaryReader $r) => $r->readUlebU64(),
                            0,
                        ),
                    );
                },
            ),
            MessageType::ConnectionResponse => $reader->readObject(
                static function (BinaryReader $object) use ($header): ConnectionResponseMessage {
                    return new ConnectionResponseMessage(
                        header: $header,
                        serverDuckdbVersion: self::readOptionalString($object, 1),
                        serverPlatform: self::readOptionalString($object, 2),
                        quackVersion: $object->readOptionalField(
                            3,
                            static fn (BinaryReader $r) => $r->readUlebU64(),
                            null,
                        ),
                        heartbeatTimeoutSeconds: $object->readOptionalField(
                            4,
                            static fn (BinaryReader $r) => $r->readUlebU64(),
                            null,
                        ),
                    );
                },
            ),
            MessageType::PrepareRequest => $reader->readObject(
                static function (BinaryReader $object) use ($header): PrepareRequestMessage {
                    return new PrepareRequestMessage(
                        header: $header,
                        sql: $object->readOptionalField(
                            1,
                            static fn (BinaryReader $r) => $r->readString(),
                            '',
                        ),
                        queryUuid: $object->readRequiredField(
                            2,
                            static fn (BinaryReader $r) => $r->readHugeIntParts(),
                        ),
                        inlineRows: self::readOptionalIndex($object, 3),
                    );
                },
            ),
            MessageType::PrepareResponse => $reader->readObject(
                static fn (BinaryReader $object) => self::decodePrepareResponse($object, $header),
            ),
            MessageType::FetchRequest => $reader->readObject(
                static function (BinaryReader $object) use ($header): FetchRequestMessage {
                    return new FetchRequestMessage(
                        header: $header,
                        resultUuid: $object->readRequiredField(
                            1,
                            static fn (BinaryReader $r) => $r->readHugeIntParts(),
                        ),
                        batchIndex: self::readOptionalIndex($object, 2),
                        ackIndex: $object->readOptionalField(
                            3,
                            static fn (BinaryReader $r) => $r->readUlebU64(),
                            0,
                        ),
                    );
                },
            ),
            MessageType::FetchResponse => self::decodeFetchResponseV3($reader, $header),
            MessageType::SuccessResponse => $reader->readObject(
                static fn (): SuccessResponseMessage => new SuccessResponseMessage($header),
            ),
            MessageType::DisconnectMessage => $reader->readObject(
                static fn (): DisconnectMessage => new DisconnectMessage($header),
            ),
            MessageType::CancelRequest => $reader->readObject(
                static fn (BinaryReader $object) => new CancelMessage(
                    $header,
                    $object->readRequiredField(
                        1,
                        static fn (BinaryReader $r) => $r->readHugeIntParts(),
                    ),
                ),
            ),
            MessageType::Acknowledgement => $reader->readObject(
                static fn (BinaryReader $object) => new AcknowledgementMessage(
                    $header,
                    $object->readOptionalField(
                        1,
                        static fn (BinaryReader $r) => $r->readHugeIntParts(),
                        new HugeIntParts(0, 0),
                    ),
                ),
            ),
            MessageType::HeartbeatRequest => $reader->readObject(
                static fn (): HeartbeatRequestMessage => new HeartbeatRequestMessage($header),
            ),
            MessageType::ErrorResponse => $reader->readObject(
                static fn (BinaryReader $object) => new ErrorMessage(
                    $header,
                    self::readErrorFields($object),
                ),
            ),
            default => throw new ProtocolException("cannot decode unsupported message type {$header->messageType->name}"),
        };
    }

    private static function decodePrepareResponse(
        BinaryReader $object,
        MessageHeader $header,
    ): PrepareResponseMessage {
        $resultTypes = $object->readOptionalField(
            1,
            static fn (BinaryReader $r) => $r->readList(
                static fn (BinaryReader $rr) => LogicalTypeCodec::decodeLogicalType($rr),
            ),
            [],
        );
        $resultNames = $object->readOptionalField(
            2,
            static fn (BinaryReader $r) => $r->readList(
                static fn (BinaryReader $rr) => $rr->readString(),
            ),
            [],
        );
        $needsMoreFetch = $object->readOptionalField(
            3,
            static fn (BinaryReader $r) => $r->readBool(),
            false,
        );
        $results = $object->readOptionalField(
            4,
            static fn (BinaryReader $r) => self::readChunkPointerList($r),
            [],
        );
        $resultUuid = $object->readRequiredField(
            5,
            static fn (BinaryReader $r) => $r->readHugeIntParts(),
        );
        return new PrepareResponseMessage(
            $header,
            $needsMoreFetch,
            $results,
            $resultUuid,
            $resultTypes,
            $resultNames,
        );
    }

    private static function decodeFetchResponseV3(BinaryReader $reader, MessageHeader $header): FetchResponseMessage
    {
        [$chunkCount, $totalBatches, $batchIndex] = $reader->readObject(
            static function (BinaryReader $object): array {
                return [
                    $object->readOptionalField(
                        1,
                        static fn (BinaryReader $r) => $r->readUlebU64(),
                        0,
                    ),
                    self::readOptionalIndex($object, 2),
                    self::readOptionalIndex($object, 3),
                ];
            },
        );
        $count = (int) $chunkCount;
        if ((string) $count !== (string) $chunkCount) {
            throw new ProtocolException('FETCH_RESPONSE chunk_count is too large');
        }
        $results = [];
        for ($index = 0; $index < $count; $index++) {
            $results[] = DataChunkCodec::decodeDataChunkToPhp($reader);
        }
        return new FetchResponseMessage($header, $results, $totalBatches, $batchIndex);
    }

    /**
     * @return list<DataChunk>
     */
    private static function readChunkPointerList(BinaryReader $reader): array
    {
        return $reader->readList(
            static function (BinaryReader $r): DataChunk {
                $chunk = $r->readNullable(
                    static fn (BinaryReader $rr) => DataChunkCodec::decodeDataChunkWrapperToPhp($rr),
                );
                if ($chunk === null) {
                    throw new ProtocolException(
                        'encountered null DataChunk pointer in result list',
                    );
                }
                return $chunk;
            },
        );
    }

    private static function writeOptionalString(
        BinaryWriter $writer,
        int $fieldId,
        ?string $value,
    ): void {
        if ($value !== null && $value !== '') {
            $writer->writeField(
                $fieldId,
                static fn (BinaryWriter $w) => $w->writeString($value),
            );
        }
    }

    private static function writeOptionalIndexDefaultZero(
        BinaryWriter $writer,
        int $fieldId,
        int|string $value,
    ): void {
        if ((string) $value !== '0') {
            $writer->writeField(
                $fieldId,
                static fn (BinaryWriter $w) => $w->writeUleb($value),
            );
        }
    }

    private static function readOptionalIndex(
        BinaryReader $reader,
        int $fieldId,
    ): ?int {
        $value = $reader->readOptionalField(
            $fieldId,
            static fn (BinaryReader $r) => $r->readUlebU64(),
            null,
        );
        return $value === null ? null : self::toOptionalIndex($value);
    }

    private static function toOptionalIndex(int|string $value): ?int
    {
        if ((string) $value === self::OPTIONAL_INDEX_INVALID) {
            return null;
        }
        $index = (int) $value;
        if ((string) $index !== (string) $value) {
            throw new ProtocolException('batch index is too large');
        }
        return $index;
    }

    private static function readOptionalString(BinaryReader $reader, int $fieldId): ?string
    {
        return $reader->readOptionalField(
            $fieldId,
            static fn (BinaryReader $r) => $r->readString(),
            null,
        );
    }

    public static function readErrorFields(BinaryReader $object): ServerError
    {
        $message = $object->readOptionalField(
            1,
            static fn (BinaryReader $r) => $r->readString(),
            '',
        );
        $exception = $object->readOptionalField(
            2,
            static fn (BinaryReader $r) => $r->readString(),
            '',
        );
        $error = ServerError::new($message)->withExceptionType($exception);
        $object->readOptionalField(
            3,
            static function (BinaryReader $r) use ($error): void {
                $length = $r->readUlebUsize();
                for ($index = 0; $index < $length; $index++) {
                    [$key, $value] = self::readErrorMetadataEntry($r);
                    if (array_key_exists($key, $error->extraInfo())) {
                        throw new ProtocolException('duplicate server error metadata key');
                    }
                    $error->withExtraInfo($key, $value);
                }
            },
            null,
        );
        $mustInvalidate = $object->readOptionalField(
            4,
            static fn (BinaryReader $r) => $r->readBool(),
            false,
        );
        return $error->withInvalidation($mustInvalidate);
    }

    /**
     * @return array{0:string,1:string}
     */
    private static function readErrorMetadataEntry(BinaryReader $reader): array
    {
        return $reader->readObject(static function (BinaryReader $entry): array {
            $key = $entry->readRequiredField(
                0,
                static fn (BinaryReader $e) => $e->readString(),
            );
            $value = $entry->readRequiredField(
                1,
                static fn (BinaryReader $e) => $e->readString(),
            );

            return [$key, $value];
        });
    }

    public static function writeErrorFields(BinaryWriter $object, ServerError $error): void
    {
        self::writeOptionalString($object, 1, $error->message());
        self::writeOptionalString($object, 2, $error->exceptionTypeRaw());
        if ($error->extraInfo() !== []) {
            $object->writeField(3, static function (BinaryWriter $w) use ($error): void {
                $w->writeUleb(count($error->extraInfo()));
                foreach ($error->extraInfo() as $key => $value) {
                    $w->writeObject(static function (BinaryWriter $entry) use ($key, $value): void {
                        $entry->writeField(
                            0,
                            static fn (BinaryWriter $e) => $e->writeString($key),
                        );
                        $entry->writeField(
                            1,
                            static fn (BinaryWriter $e) => $e->writeString($value),
                        );
                    });
                }
            });
        }
        if ($error->mustInvalidate()) {
            $object->writeField(4, static fn (BinaryWriter $w) => $w->writeBool(true));
        }
    }
}

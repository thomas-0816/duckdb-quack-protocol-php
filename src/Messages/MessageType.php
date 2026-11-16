<?php

namespace DuckDB\Quack\Messages;

use DuckDB\Quack\Exceptions\ProtocolException;

enum MessageType: int
{
    case Invalid = 0;
    case ConnectionRequest = 1;
    case ConnectionResponse = 2;
    case PrepareRequest = 3;
    case PrepareResponse = 4;
    case FetchRequest = 7;
    case FetchResponse = 8;
    case SuccessResponse = 10;
    case DisconnectMessage = 11;
    case CancelRequest = 12;
    case Acknowledgement = 15;
    case HeartbeatRequest = 16;
    case ErrorResponse = 100;

    public static function fromId(int|string $value): self
    {
        return self::tryFrom((int) $value)
            ?? throw new ProtocolException("unknown message type {$value}");
    }
}

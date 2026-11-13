<?php

declare(strict_types=1);

namespace Quack\Protocol\Error;

use Quack\Protocol\Exceptions\ProtocolException;
use Quack\Protocol\Exceptions\UnsupportedTypeException;
use Quack\Protocol\Transport\Transport;

/**
 * Base class for every error raised by this package.
 */
class QuackError extends \RuntimeException
{
    public static function protocol(string $message): ProtocolException
    {
        return new ProtocolException($message);
    }

    public static function unsupported(string $message): UnsupportedTypeException
    {
        return new UnsupportedTypeException($message);
    }

    /**
     * The status and raw body of a non-success HTTP reply, when this error is
     * an HTTP status failure.
     *
     * @return array{0:int,1:string}|null
     */
    public function httpStatus(): ?array
    {
        return null;
    }

    /**
     * Whether the connection that produced this error is no longer usable.
     *
     * Transport and framing failures leave no way to tell what the server did
     * with the request, so the connection must not be used again. Errors
     * raised without touching the wire - bad arguments, unsupported types -
     * are not counted.
     */
    public function isConnectionFatal(): bool
    {
        return false;
    }
}

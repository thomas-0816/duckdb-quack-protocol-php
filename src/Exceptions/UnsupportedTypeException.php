<?php

declare(strict_types=1);

namespace Quack\Protocol\Exceptions;

use Quack\Protocol\Error\QuackError;

/** A value or logical type this implementation cannot encode or decode. */
final class UnsupportedTypeException extends QuackError
{
    public function __construct(string $message)
    {
        parent::__construct('unsupported quack type: ' . $message);
    }
}

<?php

declare(strict_types=1);

namespace Quack\Protocol\Exceptions;

use Quack\Protocol\Error\QuackError;

/** A malformed Quack URI. */
final class UrlException extends QuackError
{
    public function __construct(string $message)
    {
        parent::__construct('url parse error: ' . $message);
    }
}

<?php

namespace DuckDB\Quack\Error;

/** What part of an HTTP exchange failed. */
enum TransportErrorKind: string
{
    case Connect = 'connect';
    case Timeout = 'timeout';
    case Other = 'other';
}

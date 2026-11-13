<?php

namespace Quack\Protocol\Error;

/** What part of an HTTP exchange failed. */
enum TransportErrorKind: string
{
    case Connect = 'connect';
    case Timeout = 'timeout';
    case Other = 'other';
}

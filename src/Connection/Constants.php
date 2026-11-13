<?php

declare(strict_types=1);

namespace Quack\Protocol\Connection;

/**
 * Protocol constants shared by the client, codecs, and inspection helpers.
 */
final class Constants
{
    /** Oldest Quack protocol version this client can negotiate (v1). */
    public const MIN_QUACK_VERSION = 1;

    /** Newest Quack protocol version this client can negotiate (v3). */
    public const MAX_QUACK_VERSION = 3;

    /** HTTP path every Quack request is posted to. */
    public const QUACK_ENDPOINT = '/quack';

    /** MIME type used for Quack request and response bodies. */
    public const DUCKDB_MIME_TYPE = 'application/duckdb';

    /** Sentinel field id that terminates a serialized object. */
    public const FIELD_END = 0xffff;

    /**
     * u64::MAX. Kept as a string because it exceeds PHP's signed int range.
     */
    public const OPTIONAL_INDEX_INVALID = '18446744073709551615';

    /** Default request timeout in seconds, used when no override is given. */
    public const DEFAULT_REQUEST_TIMEOUT_SECS = 300;

    /** Default connect timeout in seconds, used when no override is given. */
    public const DEFAULT_CONNECT_TIMEOUT_SECS = 10;

    /** Upper bound for the best-effort DISCONNECT sent by the destructor. */
    public const DISCONNECT_ON_DROP_TIMEOUT_SECS = 10;

    /** Maximum HTTP response body retained for a failed request. */
    public const MAX_HTTP_STATUS_BODY_BYTES = 16 * 1024;

    /** Default connection limit of the Rust pool, kept for parity. */
    public const DEFAULT_MAX_CONNECTIONS = 4;
}

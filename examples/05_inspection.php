<?php

/**
 * Bounded message inspection.
 *
 * Inspector decodes only the small, trusted control surfaces of the protocol:
 * message headers, CANCEL/DISCONNECT requests, and connected/success/error
 * control responses. Query payloads stay opaque.
 *
 * Run: php examples/05_inspection.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Quack\Protocol\Error\ServerError;
use Quack\Protocol\Exceptions\InspectionException;
use Quack\Protocol\Inspection\ControlResponseKind;
use Quack\Protocol\Inspection\InspectionMessageHeader;
use Quack\Protocol\Inspection\Inspector;
use Quack\Protocol\Messages\MessageType;

$connectionId = 'example-connection';

// A header is a bounded prefix: only the type, connection id, and query id are
// decoded. encodedLen tells a proxy how many bytes the header occupied.
$cancel = Inspector::encodeCancel($connectionId);
$header = Inspector::inspectMessageHeader($cancel);
echo 'CANCEL header:', PHP_EOL;
echo '  operation: ', $header->operation->name, PHP_EOL;
echo '  connection: ', $header->connectionId ?? 'none', PHP_EOL;
echo '  client query id: ', $header->clientQueryId ?? 'none', PHP_EOL;
echo '  header bytes: ', $header->encodedLen, ' / ', strlen($cancel), PHP_EOL;

$disconnect = Inspector::encodeDisconnect($connectionId);
$disconnectHeader = Inspector::inspectMessageHeader($disconnect);
echo PHP_EOL, 'DISCONNECT operation: ', $disconnectHeader->operation->name, PHP_EOL;

// Error responses can be built and inspected without a live server.
$errorHeader = new InspectionMessageHeader(
    MessageType::ErrorResponse,
    $connectionId,
    42,
    0,
);
$errorBytes = Inspector::encodeErrorResponse(
    $errorHeader,
    ServerError::new('table "missing" does not exist')
        ->withExceptionType('Catalog')
        ->withExtraInfo('error_subtype', 'MISSING_ENTRY'),
);
$response = Inspector::inspectControlResponse($errorBytes);
echo PHP_EOL, 'control response: ', $response->kind->name, PHP_EOL;
if ($response->kind === ControlResponseKind::Error && $response->error !== null) {
    echo '  message: ', $response->error->message(), PHP_EOL;
    echo '  exception: ', $response->error->exceptionTypeRaw() ?? 'none', PHP_EOL;
    foreach ($response->error->extraInfo() as $key => $value) {
        echo '  ', $key, ' = ', $value, PHP_EOL;
    }
}

// Inspection distinguishes truncation from malformed input.
try {
    Inspector::inspectMessageHeader(substr($cancel, 0, 2));
} catch (InspectionException $error) {
    echo PHP_EOL, 'truncated header: ', $error->kind()->value, PHP_EOL;
}

echo PHP_EOL, 'done', PHP_EOL;

<?php

declare(strict_types=1);

/**
 * TLS certificate pinning.
 *
 * A pin is the SHA-256 fingerprint of the server certificate, spelled the same
 * way DuckDB's ssl_fingerprint setting expects it. Pin implies HTTPS and trusts
 * exactly that certificate, so self-signed servers work without a CA bundle.
 *
 * Run: php examples/06_tls_pin.php 'AA:BB:...' ['/path/to/certificate.pem']
 */

require __DIR__ . '/../vendor/autoload.php';

use Quack\Protocol\Exceptions\ProtocolException;
use Quack\Protocol\QuackClient;
use Quack\Protocol\Transport\CertificatePin;

$fingerprint = $argv[1] ?? '';
if (trim($fingerprint) === '') {
    echo 'Pass the server certificate SHA-256 fingerprint as the first argument.', PHP_EOL;
    echo 'The accepted forms are:', PHP_EOL;
    echo '  AA:BB:CC:...  (64 hex digits, colons or spaces optional)', PHP_EOL;
    echo '  sha256:aabbcc...', PHP_EOL;
    echo PHP_EOL, 'Example (self-signed certificate):', PHP_EOL;
    echo '  openssl s_client -connect host:9494 -showcerts </dev/null 2>/dev/null \\', PHP_EOL;
    echo '    | openssl x509 -fingerprint -sha256 -noout', PHP_EOL;
    echo PHP_EOL, 'done', PHP_EOL;
    exit(0);
}

try {
    $pin = CertificatePin::parse($fingerprint);
} catch (ProtocolException $error) {
    echo 'invalid fingerprint: ', $error->getMessage(), PHP_EOL;
    exit(1);
}

echo 'pin: ', $pin->hex(), PHP_EOL;

// A PEM certificate can be checked locally before connecting.
$pemFile = $argv[2] ?? null;
if ($pemFile !== null && is_file($pemFile)) {
    $pem = file_get_contents($pemFile);
    if ($pem === false) {
        echo 'could not read ', $pemFile, PHP_EOL;
        exit(1);
    }
    echo 'local certificate matches: ', $pin->matchesPem($pem) ? 'yes' : 'no', PHP_EOL;
}

$client = QuackClient::connect(
    'quack:127.0.0.1:9494',
    'secret',
    10.0,
    300.0,
    true,
    60,
    $pin->hex(),
);

echo 'connected over pinned TLS to quack:127.0.0.1:9494', PHP_EOL;
echo 'server DuckDB: ', $client->info?->serverDuckdbVersion ?? 'unknown', PHP_EOL;
$row = $client->query('SELECT 1 AS pinned')->one();
echo 'query result: ';
var_dump($row['pinned'] ?? null);

echo PHP_EOL, 'done', PHP_EOL;

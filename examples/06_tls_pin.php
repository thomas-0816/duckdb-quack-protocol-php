<?php

/**
 * TLS with a self-signed certificate.
 *
 * Pass the server certificate as a PEM file. The client hands its contents to
 * curl as a trusted CA blob, so a self-signed server works without a CA bundle.
 * The URL scheme selects HTTPS, and the hostname must match the certificate;
 * DuckDB generates one for localhost.
 *
 * Run: php examples/06_tls_pin.php '/path/to/certificate.pem'
 */

require __DIR__ . '/../vendor/autoload.php';

use Quack\Protocol\QuackClient;

$pemFile = $argv[1] ?? '';
if ($pemFile === '') {
    echo 'Usage: php examples/06_tls_pin.php <certificate.pem>', PHP_EOL;
    echo PHP_EOL, 'Example (self-signed certificate):', PHP_EOL;
    echo '  openssl s_client -connect localhost:9494 -showcerts </dev/null 2>/dev/null \\', PHP_EOL;
    echo '    | openssl x509 -outform PEM > server.pem', PHP_EOL;
    echo PHP_EOL, 'done', PHP_EOL;
    exit(0);
}

$pem = file_get_contents($pemFile);
if ($pem === false) {
    echo 'could not read ', $pemFile, PHP_EOL;
    exit(1);
}

$client = QuackClient::connect(
    'https://localhost:9494',
    'secret',
    10.0,
    300.0,
    sslCertificate: $pem,
);

echo 'connected over TLS to https://localhost:9494', PHP_EOL;
echo 'server DuckDB: ', $client->info?->serverDuckdbVersion ?? 'unknown', PHP_EOL;
$row = $client->query('SELECT 1 AS pinned')->one();
echo 'query result: ';
var_dump($row['pinned'] ?? null);

echo PHP_EOL, 'done', PHP_EOL;

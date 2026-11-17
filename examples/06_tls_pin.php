<?php

/**
 * TLS validation with a self-signed certificate
 *
 * Pass the server certificate as a PEM file (public key).
 * The Quack client hands its contents to curl as a trusted CA blob, so a self-signed server
 * works without a CA bundle. The URL scheme selects HTTPS, and the hostname must match the certificate;
 * DuckDB generates one for localhost.
 *
 * Start the DuckDB server:
 * duckdb --cmd "CALL quack_serve('quack:127.0.0.1:9495', token='secret', disable_ssl=false);"
 *
 * Run: php examples/06_tls_pin.php
 */

use DuckDB\Quack\QuackClient;

error_reporting(E_ALL);
ini_set('display_errors', 1);

require __DIR__ . '/../vendor/autoload.php';

$pem = file_get_contents(getenv('HOME') . '/.duckdb/extension_data/quack/server.pem');

$db = QuackClient::connect('https://localhost:9495', 'secret', 3, 10, [], $pem);
echo $db->fetchValue('SELECT 42'), PHP_EOL;

# 42

<?php

/**
 * Monitoring
 *
 * Start the DuckDB server:
 * duckdb --cmd "CALL quack_serve('quack:127.0.0.1:9494', token='secret');"
 *
 * Run: php examples/07_monitoring.php
 */

use DuckDB\Quack\QuackClient;

error_reporting(E_ALL);
ini_set('display_errors', 1);

require __DIR__ . '/../vendor/autoload.php';

$db = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
print_r($db->fetchAll('SELECT * FROM quack_active_connections()'));

# 42

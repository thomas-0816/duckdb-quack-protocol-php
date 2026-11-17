<?php

/**
 * Basic querying
 *
 * Start the DuckDB server:
 * duckdb --cmd "CALL quack_serve('quack:127.0.0.1:9494', token='secret');"
 *
 * Run: php examples/01_basic_query.php
 */

use DuckDB\Quack\QuackClient;

error_reporting(E_ALL);
ini_set('display_errors', 1);

require __DIR__ . '/../vendor/autoload.php';

$db = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
$db->execute('CREATE TABLE IF NOT EXISTS table1 (id INTEGER, amount DECIMAL(10, 2), description VARCHAR)');

$db->execute('INSERT INTO table1 VALUES (?, ?, ?)', [1, 42.21, 'Hello DuckDB! 🐘 💓 🦆']);

print_r($db->fetchAll('SELECT * FROM table1'));

unset($db); // Disconnect

# Array
#     [0] => Array
#         [id] => 1
#         [amount] => 42.21
#         [description] => Hello DuckDB! 🐘 💓 🦆

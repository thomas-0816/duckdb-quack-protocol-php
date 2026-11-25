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

// http-connect-timeout 3s, http-timeout 10s
$db = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
$db->execute('CREATE TABLE IF NOT EXISTS table1 (id INTEGER, amount DECIMAL(10, 2), description VARCHAR)');

$db->beginTransaction();
$db->execute('INSERT INTO table1 VALUES (?, ?, ?)', [1, 42.21, 'Hello DuckDB! 🐘 💓 🦆']);
$db->execute('INSERT INTO table1 VALUES (?, ?, ?)', [2, 21.01, 'Hello PHP! 🐘']);
$db->commit();

print_r($db->fetchAll('SELECT * FROM table1'));

# Array
#     [0] => Array
#         [id] => 1
#         [amount] => 42.21
#         [description] => Hello DuckDB! 🐘 💓 🦆
#     [1] => Array
#         [id] => 2
#         [amount] => 21.01
#         [description] => Hello PHP! 🐘
#     [2] => Array
#         [id] => 3
#         [amount] => 12.34
#         [description] => bar

# Array
#     [id] => 1
#     [amount] => 42.21
#     [description] => Hello DuckDB! 🐘 💓 🦆

echo $db->fetchValue('SELECT id FROM table1'), PHP_EOL;

# 1

print_r($db->fetchAllKeyValue('SELECT id, amount FROM table1'));

# Array
#     [1] => 42.21
#     [2] => 21.01

print_r($db->fetchColumn('SELECT description FROM table1'));

# Array
#     [0] => Hello DuckDB! 🐘 💓 🦆
#     [1] => Hello PHP! 🐘

foreach ($db->fetchIterator('SELECT * FROM table1') as $row) {
    print_r($row);
}

# Array
#     [id] => 1
#     [amount] => 42.21
#     [description] => Hello DuckDB! 🐘 💓 🦆
# Array
#     [id] => 2
#     [amount] => 21.01
#     [description] => Hello PHP! 🐘

// named parameter binding
$db->execute("SET VARIABLE foo = 'bar'");
print_r($db->fetchOne('SELECT :id as a, :amount as b, $foo as c', ['id' => 1, 'amount' => 12.34]));

# Array
#     [a] => 1
#     [b] => 12.34
#     [c] => bar

unset($db); // Disconnect

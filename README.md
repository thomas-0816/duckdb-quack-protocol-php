# PHP client for DuckDB Quack Remote Protocol

<img width="500" height="273" alt="logo" src="logo.jpg" />

The Quack Remote Protocol unlocks multi-client access for [DuckDB](https://duckdb.org), turning DuckDB into a general-purpose database management system with client(s) and server.
Quack uses HTTP and can be combined with load balancers, firewalls, and reverse proxies (e.g. nginx).

This library provides a pure PHP implementation for the DuckDB Quack Remote Protocol (DuckDB client).\
It implements Quack Remote Protocol v3 and is tested against Duckdb 2.x.\
As a pure PHP implementation, it does not include FFI or C code.\
The only runtime requirement is the PHP curl extension for the HTTP(s) connections.

All DuckDB types are supported: Text, Numeric, Date, Time, Interval, JSON, Array, Struct, Map, List, Tuple, Type, Enum, Variant, Geometry, Union, Bitstring, Blob, and Boolean.

Supported PHP versions (nts & zts): 8.2, 8.3, 8.4, 8.5, 8.6

## Requirements

- PHP 8.2+
- curl extension

## Install and setup (client)

```bash
composer require thomas-0816/duckdb-quack-protocol-php
```

## Install and setup (server)

Install the DuckDB CLI:

https://duckdb.org/docs/2.0/installation?environment=cli

Start the DuckDB Server:

```bash
# localhost, HTTP
duckdb --cmd "CALL quack_serve('quack:127.0.0.1:9494', token='secret');"

# public server, HTTPs, creates self-signed certificates
duckdb --cmd "CALL quack_serve('quack:0.0.0.0:9495', token='secret', allow_other_hostname=true, disable_ssl=false);"
```

## Usage examples

```php
use DuckDB\Quack\QuackClient;

require __DIR__ . '/vendor/autoload.php';

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

print_r($db->fetchOne('SELECT * FROM table1'));

# Array
#     [id] => 1
#     [amount] => 42.21
#     [description] => Hello DuckDB! 🐘 💓 🦆

print_r($db->fetchValue('SELECT id FROM table1'));

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

unset($db); // Disconnect
```

more examples coming soon ...

## HTTPs Connections with self-signed certificates

```php
use DuckDB\Quack\QuackClient;

require __DIR__ . '/../vendor/autoload.php';

// read self-signed public key
$pem = file_get_contents(getenv('HOME') . '/.duckdb/extension_data/quack/server.pem');

$db = QuackClient::connect('https://localhost:9495', 'secret', 3, 10, [], $pem);
echo $db->fetchValue('SELECT 42'), PHP_EOL;
```

Read more about [Quack Security](https://duckdb.org/docs/2.0/quack/security).

## Timeouts

```php
// HTTP connect timeout 3s
// HTTP timeout 10s
$db = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
```

HTTP connect timeout and HTTP timeout (total request time) are applied per request.
One query can span over multiple requests.

## Monitoring

```php
use DuckDB\Quack\QuackClient;

require __DIR__ . '/vendor/autoload.php';

$db = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

print_r($db->fetchAll('SELECT * FROM quack_active_connections()'));

# Array
#     [0] => Array
#         [server_id] => quack:127.0.0.1:9494
#         [connection_id] => 83FA7408F5D392C23C6CADDDB1F579F2
#         [query] => SELECT * FROM quack_active_connections()
#         [state] => active
#         [query_started_at] => 2026-11-17 02:22:16.402055
```

## Why DuckDB?

https://duckdb.org/why_duckdb

DuckDB uses columnar storage and vectorized processing, running analytics 10–100x faster than traditional row-oriented databases.
It spills data to disk if needed, allowing to process datasets much larger than available system RAM.
DuckDB includes an advanced query optimizer that handles joins, subqueries, expressions and filters.\
It can directly query flat files (JSON, CSV, and Parquet) directly via SQL without needing to import the data first.
Flat files can be read directly from disk, network attached storage or S3 comatible cloud storage.\
Data is processed in cache-friendly batches on a multi-core architecture, allowing modern hardware to operate on arrays of data simultaneously.
For analytical queries that only require a few metrics, DuckDB reads only the relevant columns from disk/memory, saving I/O and CPU cycles.
This brings data warehouse-level performance to any laptop or server.

## FAQ

> How much RAM and CPU do I need for DuckDB?

DuckDB normally runs good with 1-4 GB RAM and 2-4 CPU cores.

> How good is the compression with Parquet and zstd?

For logs you normally achieve compression rates of 50-100x.

> Who is owning DuckDB?

The DuckDB project is owned by the [DuckDB Foundation](https://duckdb.foundation), a non-profit organization from Amsterdam.

> Can I get support for DuckDB?

Yes. Support is available on GitHub, see the [community support](https://duckdb.org/community_support) page for details.

> Can I get support for this project?

Yes. Just open an Issue or a Pull request.

> Is the PHP client for DuckDB Quack Remote Protocol developed by the DuckDB project?

No. This is a third-party open-source community project.

> Is DuckDB fully open-source?

Yes. DuckDB and all components are fully open-source under the MIT license.

## Development

```bash
composer install
composer check
```

## DuckDB Resources

- [Quack: Turn DuckDB into a client-server database](https://duckdb.org/quack/)
- [Quack Remote Protocol](https://duckdb.org/docs/2.0/quack/overview)

- [DuckDB documentation](https://duckdb.org/docs/current/)
- [DuckDB engineering blog](https://duckdb.org/news/)
- [Awesome DuckDB](https://github.com/davidgasquez/awesome-duckdb)
- [DuckDB books](https://duckdb.org/library/?type=book)
- [DuckDB talks](https://duckdb.org/library/?type=talk)

## AI Disclosure

The code is written by AI, reviewed and tested manually.

## License

MIT License

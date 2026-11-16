<?php

namespace DuckDB\Quack\Tests\Integration;

use DuckDB\Quack\QuackClient;
use PHPUnit\Framework\TestCase;

final class QuackExcelTest extends TestCase
{
    public function testExcelFile(): void
    {
        $excelFile = sys_get_temp_dir() . '/quack_excel_' . bin2hex(random_bytes(8)) . '.xlsx';

        try {
            $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
            $client->execute('INSTALL excel; LOAD excel');
            $client->execute('CREATE TEMP TABLE table1 (id INTEGER, text VARCHAR, amount DECIMAL(10, 2))');
            $client->execute('INSERT INTO table1 VALUES (?, ?, ?)', [1, 'Hello Excel 🦆', 42.21]);
            $client->execute("COPY (SELECT * FROM table1) TO '{$excelFile}'");

            self::assertSame(
                [['A1' => 1.0, 'B1' => 'Hello Excel 🦆', 'C1' => 42.21]],
                $client->fetchAll("SELECT * FROM '{$excelFile}'"),
            );
        } finally {
            @unlink($excelFile);
        }
    }
}

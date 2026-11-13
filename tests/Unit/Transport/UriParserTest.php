<?php

namespace Quack\Protocol\Tests\Unit\Transport;

use PHPUnit\Framework\TestCase;
use Quack\Protocol\Error\QuackError;
use Quack\Protocol\Transport\ParsedQuackUri;
use Quack\Protocol\Transport\UriParser;

final class UriParserTest extends TestCase
{
    public function testParsesListenUrls(): void
    {
        $cases = [
            ['http://localhost:9494', 'http://localhost:9494', 'localhost', 9494, false],
            ['https://localhost:9495', 'https://localhost:9495', 'localhost', 9495, true],
            ['http://localhost', 'http://localhost:80', 'localhost', 80, false],
            ['https://localhost', 'https://localhost:443', 'localhost', 443, true],
            ['http://[::1]:9494', 'http://[::1]:9494', '::1', 9494, false],
            ['https://[::1]', 'https://[::1]:443', '::1', 443, true],
            ['  http://localhost:9494  ', 'http://localhost:9494', 'localhost', 9494, false],
        ];

        foreach ($cases as [$input, $baseUrl, $host, $port, $ssl]) {
            self::assertEquals(
                new ParsedQuackUri($baseUrl, $host, $port, $ssl),
                UriParser::parseQuackUri($input),
            );
        }
    }

    public function testInvalidUrisAreRejected(): void
    {
        $cases = [
            '',
            'localhost:9494',
            'quack:localhost:9494',
            'quack://localhost:9000',
            'ftp://localhost:9494',
            'http://',
            'http://localhost:0',
            'http://localhost:abc',
            'http://localhost:65536',
            'http://[::1',
        ];

        foreach ($cases as $input) {
            try {
                UriParser::parseQuackUri($input);
                self::fail("accepted invalid URI \"{$input}\"");
            } catch (QuackError) {
                self::addToAssertionCount(1);
            }
        }
    }
}

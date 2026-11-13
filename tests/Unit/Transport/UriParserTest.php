<?php

declare(strict_types=1);

namespace Quack\Protocol\Tests\Unit\Transport;

use PHPUnit\Framework\TestCase;
use Quack\Protocol\Exceptions\ProtocolException;
use Quack\Protocol\Transport\ParsedQuackUri;
use Quack\Protocol\Transport\UriParser;

final class UriParserTest extends TestCase
{
    public function testParsesDocumentedUriForms(): void
    {
        $cases = [
            ['localhost:9494', null, 'http://localhost:9494', 'localhost', 9494, false],
            ['localhost', null, 'http://localhost:9494', 'localhost', 9494, false],
            ['quack:localhost', null, 'http://localhost:9494', 'localhost', 9494, false],
            ['quack://localhost:9000', null, 'http://localhost:9000', 'localhost', 9000, false],
            ['quack:[::1]:9494', null, 'http://[::1]:9494', '::1', 9494, false],
            ['http://localhost:9000', null, 'http://localhost:9000', 'localhost', 9000, false],
            ['https://localhost', null, 'https://localhost:443', 'localhost', 443, true],
            ['localhost:9494', true, 'https://localhost:9494', 'localhost', 9494, true],
        ];

        foreach ($cases as [$input, $sslOverride, $baseUrl, $host, $port, $ssl]) {
            self::assertEquals(
                new ParsedQuackUri($baseUrl, $host, $port, $ssl),
                UriParser::parseQuackUri($input, $sslOverride),
            );
        }
    }

    public function testInvalidUrisAreRejected(): void
    {
        $cases = [
            '',
            'localhost:0',
            'localhost:abc',
            'localhost:65536',
            '[::1',
            'a:b:c',
            ':9494',
        ];

        foreach ($cases as $input) {
            try {
                UriParser::parseQuackUri($input);
                self::fail("accepted invalid URI \"{$input}\"");
            } catch (ProtocolException) {
                self::addToAssertionCount(1);
            }
        }
    }
}

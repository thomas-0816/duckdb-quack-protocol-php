<?php

declare(strict_types=1);

namespace Quack\Protocol\Tests\Unit\Transport;

use PHPUnit\Framework\TestCase;
use Quack\Protocol\Exceptions\ProtocolException;
use Quack\Protocol\Transport\CertificatePin;
use Quack\Protocol\Transport\DerReader;

final class CertificatePinTest extends TestCase
{
    private const CERTIFICATE_DER_BASE64 = 'MIIBDzCBtgIBATAKBggqhkjOPQQDAjAUMRIwEAYDVQQDDAlsb2NhbGhvc3QwHhcNMjYwOTI5MDAwMDAwWhcNMzYwOTI2MDAwMDAwWjAUMRIwEAYDVQQDDAlsb2NhbGhvc3QwWTATBgcqhkjOPQIBBggqhkjOPQMBBwNCAATaL8xhykSUxEqKOvz+4EFKfPJedyL2XSdzCfzBBA+qWZ0Z6pl9E1hTusDMxucugFwBFkztYNQZlBAOZ+sOJSzgMAoGCCqGSM49BAMCA0gAMEUCIHwdlz4Hm3/8YOet4hFnJLWdIFJLLKrkfMBfGhCIZNAgAiEA2Zg2m4ddHilY4wQ3uxFv8+L707I55E8xHf1WIOLjtSY=';

    private const SPKI_DER_BASE64 = 'MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAE2i/MYcpElMRKijr8/uBBSnzyXnci9l0ncwn8wQQPqlmdGeqZfRNYU7rAzMbnLoBcARZM7WDUGZQQDmfrDiUs4A==';

    private const FINGERPRINT = '01:50:96:0A:D8:17:B9:61:A1:A9:08:AB:4A:96:D0:A0:10:C3:02:87:B1:DB:AC:EE:33:92:4F:15:C1:2B:53:B1';

    public function testParsesDuckDbFingerprintSpellings(): void
    {
        $expected = CertificatePin::parse(self::FINGERPRINT);

        $spellings = [
            self::FINGERPRINT,
            str_replace(':', '', self::FINGERPRINT),
            strtolower(str_replace(':', '', self::FINGERPRINT)),
            'sha256:' . self::FINGERPRINT,
            'SHA256:' . str_replace(':', '', self::FINGERPRINT),
            '  ' . str_replace(':', ' ', self::FINGERPRINT) . ' ',
        ];

        foreach ($spellings as $spelling) {
            self::assertTrue(
                CertificatePin::parse($spelling)->matchesDer(self::certificateDer()),
                "failed to parse spelling {$spelling}",
            );
        }

        self::assertSame(strtoupper(str_replace(':', '', self::FINGERPRINT)), $expected->hex());
    }

    public function testFingerprintMatchesCertificateBytes(): void
    {
        self::assertTrue(
            CertificatePin::parse(self::FINGERPRINT)->matchesDer(self::certificateDer()),
        );
        self::assertFalse(
            CertificatePin::parse(self::FINGERPRINT)->matchesDer(self::certificateDer() . "\0"),
        );
    }

    public function testInvalidPinsAreRejected(): void
    {
        $cases = [
            '',
            '2B:31',
            self::FINGERPRINT . 'FF',
            str_replace('2B', 'ZZ', self::FINGERPRINT),
            'sha1:' . str_replace(':', '', self::FINGERPRINT),
        ];

        foreach ($cases as $input) {
            try {
                CertificatePin::parse($input);
                self::fail("accepted invalid fingerprint \"{$input}\"");
            } catch (ProtocolException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testExtractsSubjectPublicKeyInfo(): void
    {
        self::assertSame(
            base64_decode(self::SPKI_DER_BASE64, true),
            DerReader::subjectPublicKeyInfo(self::certificateDer()),
        );
    }

    public function testMalformedCertificatesHaveNoSubjectPublicKeyInfo(): void
    {
        $der = self::certificateDer();

        self::assertNull(DerReader::subjectPublicKeyInfo(''));
        self::assertNull(DerReader::subjectPublicKeyInfo(substr($der, 0, intdiv(strlen($der), 2))));
        self::assertNull(DerReader::subjectPublicKeyInfo($der . "\0"));
    }

    public function testBuildsPinnedPublicKeyValue(): void
    {
        $pin = CertificatePin::publicKeyPin(self::certificateDer());

        self::assertIsString($pin);
        self::assertStringStartsWith('sha256//', $pin);
        self::assertSame(
            'sha256//' . base64_encode(hash('sha256', base64_decode(self::SPKI_DER_BASE64, true), true)),
            $pin,
        );
    }

    private static function certificateDer(): string
    {
        return base64_decode(self::CERTIFICATE_DER_BASE64, true);
    }
}

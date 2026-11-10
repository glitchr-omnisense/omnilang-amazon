<?php

namespace Omnilang\Amazon\Tests;

use Omnilang\Amazon\SignatureV4;
use PHPUnit\Framework\TestCase;

/** AWS's own Signature Version 4 test suite (aws-sig-v4-test-suite): its credentials, its moment, its expected signatures. */
final class SignatureV4Test extends TestCase
{
    public function testGetVanilla(): void
    {
        $headers = self::signer()->sign('GET', '/', [], ['Host' => 'example.amazonaws.com'], '', new \DateTimeImmutable('2015-08-30T12:36:00Z'));

        self::assertSame('20150830T123600Z', $headers['X-Amz-Date']);
        self::assertSame('AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, SignedHeaders=host;x-amz-date, Signature=5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31', $headers['Authorization']);
    }

    public function testPostVanilla(): void
    {
        $headers = self::signer()->sign('POST', '/', [], ['Host' => 'example.amazonaws.com'], '', new \DateTimeImmutable('2015-08-30T12:36:00Z'));

        self::assertSame('AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, SignedHeaders=host;x-amz-date, Signature=5da7c1a2acd57cee7505fc6676e4e544621c30862966e37dddb68e92efbe5d6b', $headers['Authorization']);
    }

    private static function signer(): SignatureV4
    {
        return new SignatureV4('AKIDEXAMPLE', 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY', 'us-east-1', 'service');
    }
}

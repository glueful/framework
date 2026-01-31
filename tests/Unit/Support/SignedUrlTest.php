<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support;

use Glueful\Support\SignedUrl;
use PHPUnit\Framework\TestCase;

final class SignedUrlTest extends TestCase
{
    private SignedUrl $signedUrl;
    private string $secretKey = 'test-secret-key-12345';

    protected function setUp(): void
    {
        $this->signedUrl = new SignedUrl($this->secretKey);
    }

    public function testGenerateCreatesValidSignedUrl(): void
    {
        $baseUrl = 'https://example.com/blobs/abc123';
        $url = $this->signedUrl->generate($baseUrl, 3600);

        $this->assertStringContainsString('expires=', $url);
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringStartsWith($baseUrl, $url);
    }

    public function testValidateAcceptsValidSignedUrl(): void
    {
        $baseUrl = 'https://example.com/blobs/abc123';
        $url = $this->signedUrl->generate($baseUrl, 3600);

        $this->assertTrue($this->signedUrl->validate($url));
    }

    public function testValidateRejectsExpiredUrl(): void
    {
        $baseUrl = 'https://example.com/blobs/abc123';
        $url = $this->signedUrl->generate($baseUrl, -1); // Already expired

        $this->assertFalse($this->signedUrl->validate($url));
    }

    public function testValidateRejectsTamperedSignature(): void
    {
        $baseUrl = 'https://example.com/blobs/abc123';
        $url = $this->signedUrl->generate($baseUrl, 3600);

        // Tamper with the signature
        $tamperedUrl = preg_replace('/signature=[^&]+/', 'signature=invalid', $url);

        $this->assertFalse($this->signedUrl->validate($tamperedUrl));
    }

    public function testValidateRejectsMissingExpires(): void
    {
        $url = 'https://example.com/blobs/abc123?signature=abc';
        $this->assertFalse($this->signedUrl->validate($url));
    }

    public function testValidateRejectsMissingSignature(): void
    {
        $expires = time() + 3600;
        $url = "https://example.com/blobs/abc123?expires={$expires}";
        $this->assertFalse($this->signedUrl->validate($url));
    }

    public function testValidateRejectsUrlWithoutQueryString(): void
    {
        $url = 'https://example.com/blobs/abc123';
        $this->assertFalse($this->signedUrl->validate($url));
    }

    public function testGenerateIncludesAdditionalParams(): void
    {
        $baseUrl = 'https://example.com/blobs/abc123';
        $url = $this->signedUrl->generate($baseUrl, 3600, ['width' => '200', 'height' => '200']);

        $this->assertStringContainsString('width=200', $url);
        $this->assertStringContainsString('height=200', $url);
        $this->assertTrue($this->signedUrl->validate($url));
    }

    public function testValidateRejectsTamperedParams(): void
    {
        $baseUrl = 'https://example.com/blobs/abc123';
        $url = $this->signedUrl->generate($baseUrl, 3600, ['width' => '200']);

        // Tamper with the width parameter
        $tamperedUrl = str_replace('width=200', 'width=400', $url);

        $this->assertFalse($this->signedUrl->validate($tamperedUrl));
    }

    public function testValidateParamsWithValidParams(): void
    {
        $path = '/blobs/abc123';
        $expires = time() + 3600;
        $params = ['expires' => (string) $expires];

        $dataToSign = $path . '?' . http_build_query($params);
        $signature = hash_hmac('sha256', $dataToSign, $this->secretKey);
        $params['signature'] = $signature;

        $this->assertTrue($this->signedUrl->validateParams($path, $params));
    }

    public function testValidateParamsRejectsExpiredParams(): void
    {
        $path = '/blobs/abc123';
        $expires = time() - 100; // Expired
        $params = ['expires' => (string) $expires];

        $dataToSign = $path . '?' . http_build_query($params);
        $signature = hash_hmac('sha256', $dataToSign, $this->secretKey);
        $params['signature'] = $signature;

        $this->assertFalse($this->signedUrl->validateParams($path, $params));
    }

    public function testValidateParamsRejectsMissingExpires(): void
    {
        $params = ['signature' => 'abc123'];
        $this->assertFalse($this->signedUrl->validateParams('/blobs/abc', $params));
    }

    public function testValidateParamsRejectsMissingSignature(): void
    {
        $params = ['expires' => (string) (time() + 3600)];
        $this->assertFalse($this->signedUrl->validateParams('/blobs/abc', $params));
    }

    public function testDifferentSecretsProduceDifferentSignatures(): void
    {
        $signedUrl2 = new SignedUrl('different-secret');
        $baseUrl = 'https://example.com/blobs/abc123';

        $url1 = $this->signedUrl->generate($baseUrl, 3600);
        $url2 = $signedUrl2->generate($baseUrl, 3600);

        // Signatures should be different
        preg_match('/signature=([^&]+)/', $url1, $match1);
        preg_match('/signature=([^&]+)/', $url2, $match2);

        $this->assertNotSame($match1[1], $match2[1]);

        // URL from one secret should not validate with the other
        $this->assertFalse($signedUrl2->validate($url1));
        $this->assertFalse($this->signedUrl->validate($url2));
    }

    public function testGeneratePreservesExistingQueryParams(): void
    {
        $baseUrl = 'https://example.com/blobs/abc123?format=webp';
        $url = $this->signedUrl->generate($baseUrl, 3600);

        $this->assertStringContainsString('format=webp', $url);
        $this->assertTrue($this->signedUrl->validate($url));
    }

    public function testGenerateWithPortInUrl(): void
    {
        $baseUrl = 'https://example.com:8443/blobs/abc123';
        $url = $this->signedUrl->generate($baseUrl, 3600);

        $this->assertStringContainsString(':8443', $url);
        $this->assertTrue($this->signedUrl->validate($url));
    }

    public function testCustomTtlRespected(): void
    {
        $baseUrl = 'https://example.com/blobs/abc123';

        // Test 1 hour TTL
        $url1Hour = $this->signedUrl->generate($baseUrl, 3600);
        preg_match('/expires=(\d+)/', $url1Hour, $match1);
        $expires1 = (int) $match1[1];

        // Test 1 day TTL
        $url1Day = $this->signedUrl->generate($baseUrl, 86400);
        preg_match('/expires=(\d+)/', $url1Day, $match2);
        $expires2 = (int) $match2[1];

        $this->assertEqualsWithDelta($expires2 - $expires1, 82800, 5);
    }
}

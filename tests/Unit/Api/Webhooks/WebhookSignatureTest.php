<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Api\Webhooks;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Glueful\Api\Webhooks\WebhookSignature;

class WebhookSignatureTest extends TestCase
{
    #[Test]
    public function generateCreatesValidSignature(): void
    {
        $payload = '{"event":"test"}';
        $secret = 'test_secret_123';
        $timestamp = 1700000000;

        $signature = WebhookSignature::generate($payload, $secret, $timestamp);

        $this->assertStringStartsWith('t=', $signature);
        $this->assertStringContainsString(',v1=', $signature);
        $this->assertStringContainsString('t=1700000000', $signature);
    }

    #[Test]
    public function generateProducesDeterministicOutput(): void
    {
        $payload = '{"test":"data"}';
        $secret = 'secret';
        $timestamp = 1700000000;

        $signature1 = WebhookSignature::generate($payload, $secret, $timestamp);
        $signature2 = WebhookSignature::generate($payload, $secret, $timestamp);

        $this->assertEquals($signature1, $signature2);
    }

    #[Test]
    public function generateProducesDifferentSignaturesForDifferentPayloads(): void
    {
        $secret = 'secret';
        $timestamp = 1700000000;

        $signature1 = WebhookSignature::generate('{"a":"1"}', $secret, $timestamp);
        $signature2 = WebhookSignature::generate('{"a":"2"}', $secret, $timestamp);

        $this->assertNotEquals($signature1, $signature2);
    }

    #[Test]
    public function generateProducesDifferentSignaturesForDifferentSecrets(): void
    {
        $payload = '{"test":"data"}';
        $timestamp = 1700000000;

        $signature1 = WebhookSignature::generate($payload, 'secret1', $timestamp);
        $signature2 = WebhookSignature::generate($payload, 'secret2', $timestamp);

        $this->assertNotEquals($signature1, $signature2);
    }

    #[Test]
    public function verifyReturnsTrueForValidSignature(): void
    {
        $payload = '{"event":"test"}';
        $secret = 'test_secret_123';
        $timestamp = time();

        $signature = WebhookSignature::generate($payload, $secret, $timestamp);

        $this->assertTrue(WebhookSignature::verify($payload, $signature, $secret));
    }

    #[Test]
    public function verifyReturnsFalseForInvalidSignature(): void
    {
        $payload = '{"event":"test"}';
        $secret = 'test_secret_123';

        $this->assertFalse(WebhookSignature::verify($payload, 'invalid_signature', $secret));
    }

    #[Test]
    public function verifyReturnsFalseForTamperedPayload(): void
    {
        $originalPayload = '{"event":"test"}';
        $secret = 'test_secret_123';
        $timestamp = time();

        $signature = WebhookSignature::generate($originalPayload, $secret, $timestamp);

        $tamperedPayload = '{"event":"tampered"}';
        $this->assertFalse(WebhookSignature::verify($tamperedPayload, $signature, $secret));
    }

    #[Test]
    public function verifyReturnsFalseForWrongSecret(): void
    {
        $payload = '{"event":"test"}';
        $correctSecret = 'correct_secret';
        $wrongSecret = 'wrong_secret';
        $timestamp = time();

        $signature = WebhookSignature::generate($payload, $correctSecret, $timestamp);

        $this->assertFalse(WebhookSignature::verify($payload, $signature, $wrongSecret));
    }

    #[Test]
    public function verifyRespectsToleranceForExpiredSignatures(): void
    {
        $payload = '{"event":"test"}';
        $secret = 'test_secret';
        $oldTimestamp = time() - 600; // 10 minutes ago

        $signature = WebhookSignature::generate($payload, $secret, $oldTimestamp);

        // With 5 minute tolerance, should fail
        $this->assertFalse(WebhookSignature::verify($payload, $signature, $secret, 300));

        // With null tolerance (no check), should pass
        $this->assertTrue(WebhookSignature::verify($payload, $signature, $secret, null));
    }

    #[Test]
    public function verifyReturnsFailureForMalformedSignature(): void
    {
        $payload = '{"event":"test"}';
        $secret = 'test_secret';

        // Missing timestamp
        $this->assertFalse(WebhookSignature::verify($payload, 'v1=abc123', $secret));

        // Missing signature
        $this->assertFalse(WebhookSignature::verify($payload, 't=1700000000', $secret));

        // Empty string
        $this->assertFalse(WebhookSignature::verify($payload, '', $secret));
    }

    #[Test]
    public function parseExtractsTimestampAndSignatures(): void
    {
        $signatureHeader = 't=1700000000,v1=abc123,v0=def456';

        $parsed = WebhookSignature::parse($signatureHeader);

        $this->assertEquals(1700000000, $parsed['timestamp']);
        $this->assertEquals(['v1' => 'abc123', 'v0' => 'def456'], $parsed['signatures']);
    }

    #[Test]
    public function parseReturnsNullForInvalidHeader(): void
    {
        $this->assertNull(WebhookSignature::parse('invalid'));
        $this->assertNull(WebhookSignature::parse(''));
        $this->assertNull(WebhookSignature::parse('v1=abc123')); // Missing timestamp
    }
}

<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Queue;

use Glueful\Queue\QueuePayloadSigner;
use PHPUnit\Framework\TestCase;

final class QueuePayloadSignerTest extends TestCase
{
    private string|false $previousAppKey;
    private ?string $previousEnvAppKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousAppKey = getenv('APP_KEY');
        $this->previousEnvAppKey = $_ENV['APP_KEY'] ?? null;

        putenv('APP_KEY=test-queue-payload-signer-key');
        $_ENV['APP_KEY'] = 'test-queue-payload-signer-key';
    }

    protected function tearDown(): void
    {
        if ($this->previousAppKey === false) {
            putenv('APP_KEY');
        } else {
            putenv('APP_KEY=' . $this->previousAppKey);
        }

        if ($this->previousEnvAppKey === null) {
            unset($_ENV['APP_KEY']);
        } else {
            $_ENV['APP_KEY'] = $this->previousEnvAppKey;
        }

        parent::tearDown();
    }

    public function testVerifyRejectsTamperedPayload(): void
    {
        $signer = new QueuePayloadSigner();
        $payload = $signer->sign([
            'job' => 'ExampleJob',
            'data' => ['id' => 1],
        ]);

        $payload['data']['id'] = 2;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('signature mismatch');

        $signer->verify($payload);
    }

    public function testScheduledEnvelopeCoversHandlerClass(): void
    {
        $signer = new QueuePayloadSigner();
        $encoded = $signer->encodeScheduledParameters('ExampleJob', ['id' => 1]);

        self::assertSame(['id' => 1], $signer->decodeScheduledParameters('ExampleJob', $encoded));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('handler signature mismatch');

        $signer->decodeScheduledParameters('OtherJob', $encoded);
    }

    public function testScheduledEnvelopeBindsNameAndSchedule(): void
    {
        $signer = new QueuePayloadSigner();
        $encoded = $signer->encodeScheduledParameters('ExampleJob', ['id' => 1], 'nightly-report', '0 2 * * *');

        self::assertSame(
            ['id' => 1],
            $signer->decodeScheduledParameters('ExampleJob', $encoded, 'nightly-report', '0 2 * * *')
        );
    }

    public function testScheduledEnvelopeRejectsRescheduledRow(): void
    {
        $signer = new QueuePayloadSigner();
        $encoded = $signer->encodeScheduledParameters('ExampleJob', ['id' => 1], 'nightly-report', '0 2 * * *');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('schedule signature mismatch');

        $signer->decodeScheduledParameters('ExampleJob', $encoded, 'nightly-report', '* * * * *');
    }

    public function testScheduledEnvelopeRejectsClonedRowName(): void
    {
        $signer = new QueuePayloadSigner();
        $encoded = $signer->encodeScheduledParameters('ExampleJob', ['id' => 1], 'nightly-report', '0 2 * * *');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('name signature mismatch');

        $signer->decodeScheduledParameters('ExampleJob', $encoded, 'other-job', '0 2 * * *');
    }

    public function testLegacyEnvelopeWithoutBindingStillDecodes(): void
    {
        $signer = new QueuePayloadSigner();
        // Envelope created before name/schedule binding existed.
        $encoded = $signer->encodeScheduledParameters('ExampleJob', ['id' => 1]);

        self::assertSame(
            ['id' => 1],
            $signer->decodeScheduledParameters('ExampleJob', $encoded, 'nightly-report', '0 2 * * *')
        );
    }
}

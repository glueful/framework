<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Http\Services;

use Glueful\Http\Client;
use Glueful\Http\Services\WebhookDeliveryService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Batch webhook delivery must stay concurrent (all requests started before any
 * response is consumed) while every URL goes through SSRF validation — and one
 * unsafe URL must fail only its own entry, not the whole batch.
 */
final class WebhookDeliveryServiceBatchTest extends TestCase
{
    public function testBatchStartsAllRequestsBeforeConsumingAndIsolatesUnsafeUrls(): void
    {
        $fake = $this->recordingClient();
        $service = new WebhookDeliveryService(new Client($fake, new NullLogger()), new NullLogger());

        $results = $service->deliverBatchWebhooks([
            'ok-1' => ['url' => 'https://93.184.216.34/hook', 'payload' => ['event' => 'a']],
            'unsafe' => ['url' => 'http://169.254.169.254/hook', 'payload' => ['event' => 'b']],
            'ok-2' => ['url' => 'https://93.184.216.35/hook', 'payload' => ['event' => 'c']],
        ]);

        $this->assertTrue($results['ok-1']['success']);
        $this->assertTrue($results['ok-2']['success']);
        $this->assertFalse($results['unsafe']['success']);
        $this->assertStringContainsString('Unsafe URL', $results['unsafe']['error']);

        // Both safe requests were dispatched, the unsafe one never reached the wire.
        $this->assertSame(
            ['https://93.184.216.34/hook', 'https://93.184.216.35/hook'],
            $fake->requestedUrls
        );

        // Concurrency: every request was started before any response body/status
        // was consumed.
        $this->assertSame(
            ['request', 'request', 'consume', 'consume'],
            $fake->events
        );
    }

    private function recordingClient(): HttpClientInterface
    {
        return new class implements HttpClientInterface {
            /** @var list<string> */
            public array $requestedUrls = [];

            /** @var list<string> */
            public array $events = [];

            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                $this->requestedUrls[] = $url;
                $this->events[] = 'request';
                $events = &$this->events;

                return new class ($events) implements ResponseInterface {
                    /** @param list<string> $events */
                    public function __construct(private array &$events)
                    {
                    }

                    private bool $consumed = false;

                    private function markConsumed(): void
                    {
                        if (!$this->consumed) {
                            $this->consumed = true;
                            $this->events[] = 'consume';
                        }
                    }

                    public function getStatusCode(): int
                    {
                        $this->markConsumed();
                        return 200;
                    }
                    public function getHeaders(bool $throw = true): array
                    {
                        return [];
                    }
                    public function getContent(bool $throw = true): string
                    {
                        $this->markConsumed();
                        return 'ok';
                    }
                    /** @return array<int|string,mixed> */
                    public function toArray(bool $throw = true): array
                    {
                        return [];
                    }
                    public function cancel(): void
                    {
                    }
                    public function getInfo(?string $type = null): mixed
                    {
                        return null;
                    }
                };
            }

            public function stream($responses, ?float $timeout = null): ResponseStreamInterface
            {
                throw new \BadMethodCallException('stream() is not used in this test');
            }

            public function withOptions(array $options): static
            {
                return $this;
            }
        };
    }
}

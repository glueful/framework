<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Http;

use Glueful\Http\Client;
use Glueful\Http\Exceptions\HttpClientException;
use Glueful\Http\Security\SafeOutboundTargetResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Client::safeWebhookRequestAsync() is the strict-profile counterpart to
 * safeRequestAsync(): it must call SafeOutboundTargetResolver::resolveWebhook()
 * exactly once, install the exact resolved address into the resolve map (no
 * check-then-second-resolution / DNS-rebinding gap), request the canonicalized
 * (IDNA-ASCII) URL so the resolve-map host matches the request's host, and never
 * follow redirects.
 */
final class ClientSafeWebhookRequestAsyncTest extends TestCase
{
    public function testResolvesOnceAndPinsExactResolvedAddress(): void
    {
        $dnsCalls = 0;
        $resolver = new SafeOutboundTargetResolver(function (string $host) use (&$dnsCalls): array {
            $dnsCalls++;
            $this->assertSame('seller.example.test', $host);
            return ['93.184.216.34'];
        });

        $fake = $this->capturingClient();
        $client = new Client($fake, new NullLogger(), null, $resolver);

        $response = $client->safeWebhookRequestAsync(
            'POST',
            'https://seller.example.test/hooks/orders',
            ['json' => ['event' => 'order.paid']]
        );

        $this->assertSame(1, $dnsCalls, 'resolveWebhook must resolve DNS exactly once per call');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            ['seller.example.test' => '93.184.216.34'],
            $fake->captured['resolve'] ?? null
        );
        $this->assertSame(0, $fake->captured['max_redirects'] ?? null);
        $this->assertSame('https://seller.example.test/hooks/orders', $fake->capturedUrl);
        $this->assertSame(['event' => 'order.paid'], $fake->captured['json'] ?? null);
    }

    public function testRequestsTheCanonicalAsciiUrlSoResolveMapHostMatches(): void
    {
        $resolver = new SafeOutboundTargetResolver(function (string $host): array {
            return ['93.184.216.34'];
        });

        $fake = $this->capturingClient();
        $client = new Client($fake, new NullLogger(), null, $resolver);

        // "例え" (Japanese) IDN host — the request MUST go out against the
        // punycode-canonicalized host, otherwise the resolve[] map (keyed by the
        // ASCII host) would not match the request URL's host and pinning would
        // silently not apply.
        $client->safeWebhookRequestAsync('POST', "https://\u{4F8B}\u{3048}.example.test/hooks");

        $this->assertSame('xn--r8jz45g.example.test', array_key_first($fake->captured['resolve'] ?? []));
        $this->assertSame('https://xn--r8jz45g.example.test/hooks', $fake->capturedUrl);
    }

    public function testRejectsHttpBeforeIssuingAnyRequest(): void
    {
        $fake = $this->capturingClient();
        $client = new Client($fake, new NullLogger());

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessage('only https is allowed');

        try {
            $client->safeWebhookRequestAsync('POST', 'http://seller.example.test/hooks');
        } finally {
            $this->assertSame([], $fake->captured, 'no request should be sent when validation fails');
        }
    }

    public function testRejectsWhenResolvedAddressIsPrivateEvenIfHostLooksPublic(): void
    {
        // Simulates a DNS-rebind / TOCTOU scenario: the endpoint was public at
        // registration time but resolves to a private address at delivery time.
        // Because there is only one resolution point, this is caught right here.
        $resolver = new SafeOutboundTargetResolver(function (string $host): array {
            return ['10.0.0.5'];
        });

        $fake = $this->capturingClient();
        $client = new Client($fake, new NullLogger(), null, $resolver);

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessage('private or reserved');

        try {
            $client->safeWebhookRequestAsync('POST', 'https://seller.example.test/hooks');
        } finally {
            $this->assertSame([], $fake->captured);
        }
    }

    public function testDoesNotFollowRedirects(): void
    {
        $resolver = new SafeOutboundTargetResolver(function (string $host): array {
            return ['93.184.216.34'];
        });

        $fake = $this->redirectingClient('https://attacker.example.test/steal');
        $client = new Client($fake, new NullLogger(), null, $resolver);

        $response = $client->safeWebhookRequestAsync('POST', 'https://seller.example.test/hooks');

        $this->assertSame(1, $fake->requestCount, 'exactly one request should be issued, no redirect hop');
        $this->assertSame(302, $response->getStatusCode());
    }

    /**
     * A Symfony HttpClientInterface that records the (already Glueful-transformed)
     * options and requested URL, returning a trivial 200 response.
     */
    private function capturingClient(): HttpClientInterface
    {
        return new class implements HttpClientInterface {
            /** @var array<string,mixed> */
            public array $captured = [];
            public string $capturedUrl = '';

            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                $this->captured = $options;
                $this->capturedUrl = $url;

                return new class implements ResponseInterface {
                    public function getStatusCode(): int
                    {
                        return 200;
                    }
                    public function getHeaders(bool $throw = true): array
                    {
                        return [];
                    }
                    public function getContent(bool $throw = true): string
                    {
                        return '{}';
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

    private function redirectingClient(string $location): HttpClientInterface
    {
        return new class ($location) implements HttpClientInterface {
            public int $requestCount = 0;
            /** @var array<string,mixed> */
            public array $captured = [];

            public function __construct(private string $location)
            {
            }

            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                $this->requestCount++;
                $this->captured = $options;
                $location = $this->location;

                return new class ($location) implements ResponseInterface {
                    public function __construct(private string $location)
                    {
                    }

                    public function getStatusCode(): int
                    {
                        return 302;
                    }
                    public function getHeaders(bool $throw = true): array
                    {
                        return ['location' => [$this->location]];
                    }
                    public function getContent(bool $throw = true): string
                    {
                        return '';
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

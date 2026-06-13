<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Http;

use Glueful\Http\Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Verifies Client::transformOptions() maps Glueful's per-request options onto the
 * Symfony HttpClient options — in particular that auth_basic is passed through
 * (regression: it was previously dropped) alongside form_params.
 */
final class ClientTransformOptionsTest extends TestCase
{
    public function testAuthBasicAndFormParamsArePassedThrough(): void
    {
        $fake = $this->capturingClient();
        $client = new Client($fake, new NullLogger());

        $client->post('https://example.test/oauth/token', [
            'auth_basic' => ['user', 'pass'],
            'form_params' => ['grant_type' => 'client_credentials'],
        ]);

        $this->assertSame(['user', 'pass'], $fake->captured['auth_basic'] ?? null);
        $this->assertSame('grant_type=client_credentials', $fake->captured['body'] ?? null);
        $this->assertSame(
            'application/x-www-form-urlencoded',
            $fake->captured['headers']['Content-Type'] ?? null
        );
    }

    public function testNoAuthBasicKeyWhenNotProvided(): void
    {
        $fake = $this->capturingClient();
        $client = new Client($fake, new NullLogger());

        $client->get('https://example.test/ping', ['headers' => ['X-Test' => '1']]);

        $this->assertArrayNotHasKey('auth_basic', $fake->captured);
    }

    public function testSafeFetchRejectsPrivateIpBeforeRequest(): void
    {
        $fake = $this->capturingClient();
        $client = new Client($fake, new NullLogger());

        $this->expectException(\Glueful\Http\Exceptions\HttpClientException::class);
        $this->expectExceptionMessage('Unsafe URL');

        try {
            $client->safeFetch('http://169.254.169.254/latest/meta-data');
        } finally {
            $this->assertSame([], $fake->captured);
        }
    }

    public function testSafeFetchRejectsUnsafeRedirectLocation(): void
    {
        $fake = $this->redirectingClient('http://169.254.169.254/latest/meta-data');
        $client = new Client($fake, new NullLogger());

        $this->expectException(\Glueful\Http\Exceptions\HttpClientException::class);
        $this->expectExceptionMessage('Unsafe URL');

        $client->safeFetch('https://93.184.216.34/image.png');
    }

    public function testSafeFetchPinsValidatedHostToResolvedIp(): void
    {
        $fake = $this->capturingClient();
        $client = new Client($fake, new NullLogger());

        $client->safeFetch('https://93.184.216.34/image.png');

        $this->assertSame(
            ['93.184.216.34' => '93.184.216.34'],
            $fake->captured['resolve'] ?? null
        );
        $this->assertSame(0, $fake->captured['max_redirects'] ?? null);
    }

    public function testSafeFetchAllowsPrivateHostWhenOptedIn(): void
    {
        $fake = $this->capturingClient();
        $client = new Client($fake, new NullLogger());

        $response = $client->safeFetch('http://10.0.0.5/health', ['allow_private_hosts' => true]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['10.0.0.5' => '10.0.0.5'], $fake->captured['resolve'] ?? null);
        $this->assertArrayNotHasKey('allow_private_hosts', $fake->captured);
    }

    public function testSafeRequestAsyncRejectsPrivateIpBeforeRequest(): void
    {
        $fake = $this->capturingClient();
        $client = new Client($fake, new NullLogger());

        $this->expectException(\Glueful\Http\Exceptions\HttpClientException::class);
        $this->expectExceptionMessage('Unsafe URL');

        try {
            $client->safeRequestAsync('POST', 'http://169.254.169.254/hook');
        } finally {
            $this->assertSame([], $fake->captured);
        }
    }

    public function testSafeRequestAsyncPinsHostAndDisablesRedirects(): void
    {
        $fake = $this->capturingClient();
        $client = new Client($fake, new NullLogger());

        $response = $client->safeRequestAsync('POST', 'https://93.184.216.34/hook', ['json' => ['a' => 1]]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            ['93.184.216.34' => '93.184.216.34'],
            $fake->captured['resolve'] ?? null
        );
        $this->assertSame(0, $fake->captured['max_redirects'] ?? null);
    }

    /**
     * A Symfony HttpClientInterface that records the (already Glueful-transformed)
     * options and returns a trivial 200 response so Client::request() can read the
     * status without performing real I/O.
     */
    private function capturingClient(): HttpClientInterface
    {
        return new class implements HttpClientInterface {
            /** @var array<string,mixed> */
            public array $captured = [];

            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                $this->captured = $options;

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
            public function __construct(private string $location)
            {
            }

            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                return new class ($this->location) implements ResponseInterface {
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

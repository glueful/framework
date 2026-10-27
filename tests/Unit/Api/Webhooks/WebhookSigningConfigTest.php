<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Api\Webhooks;

use Glueful\Api\Webhooks\Jobs\DeliverWebhookJob;
use Glueful\Api\Webhooks\Webhook;
use Glueful\Api\Webhooks\WebhookDelivery;
use Glueful\Api\Webhooks\WebhookSignature;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Framework;
use Glueful\Http\Client;
use Glueful\Http\Services\WebhookDeliveryService;
use Glueful\Routing\RouteManifest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * `api.webhooks.signature_header`, `signature_algorithm` and `retry.backoff` were read by
 * nothing: deliveries always signed HMAC-SHA256 into X-Webhook-Signature and backed off on a
 * fixed table. WebhookDeliveryService meanwhile signed a different scheme
 * (`X-Glueful-Signature: sha256=<hmac of the body>`). Every outbound delivery now goes through
 * one signer, the subscription scheme (`t=<ts>,v1=<hmac of "<ts>.<body>">`), honouring the config.
 */
final class WebhookSigningConfigTest extends TestCase
{
    private string $appPath;
    private ApplicationContext $context;
    private Connection $db;

    protected function tearDown(): void
    {
        Webhook::reset();
        if (isset($this->appPath)) {
            exec('rm -rf ' . escapeshellarg($this->appPath));
        }
    }

    private function boot(string $webhooksConfig): void
    {
        RouteManifest::reset();
        $this->appPath = sys_get_temp_dir() . '/glueful-webhooks-sign-' . uniqid('', true);
        $cfg = $this->appPath . '/config';
        mkdir($cfg, 0755, true);
        $files = [
            'app' => "['name' => 'T', 'version_full' => '1.0.0', 'env' => 'testing', 'debug' => true, "
                . "'key' => 'test-key']",
            'database' => "['engine' => 'sqlite', 'sqlite' => ['primary' => '" . $this->appPath . "/w.sqlite'], "
                . "'pooling' => ['enabled' => false]]",
            'cache' => "['enabled' => true, 'default' => 'array', 'stores' => ['array' => ['driver' => 'array']]]",
            'security' => "['csrf' => ['enabled' => false]]",
            'session' => "['jwt_key' => 'test']",
            'api' => "['webhooks' => {$webhooksConfig}]",
        ];
        foreach ($files as $name => $body) {
            file_put_contents("{$cfg}/{$name}.php", "<?php\nreturn {$body};\n");
        }
        $this->context = Framework::create($this->appPath)->boot(allowReboot: true)->getContext();
        $this->db = Connection::fromContext($this->context);
        require_once dirname(__DIR__, 4) . '/migrations/webhooks/001_CreateWebhookTables.php';
        (new \Glueful\Migrations\Webhooks\CreateWebhookTables())->up($this->db->getSchemaBuilder());
        Webhook::reset();
        Webhook::setContext($this->context);
    }

    private function delivery(string $secret): WebhookDelivery
    {
        $sub = Webhook::subscribe(['order.*'], 'https://93.184.216.34/hook');
        $sub->update(['secret' => $secret]);
        $delivery = WebhookDelivery::create($this->context, [
            'subscription_id' => $sub->id,
            'event' => 'order.shipped',
            'payload' => ['id' => 'wh_evt_1', 'event' => 'order.shipped', 'data' => ['a' => 'b/c']],
        ]);
        self::assertInstanceOf(WebhookDelivery::class, $delivery);

        return $delivery;
    }

    private function job(WebhookDelivery $delivery, RecordingHttpClient $http): DeliverWebhookJob
    {
        return new class (['delivery_id' => $delivery->id], $this->context, $http) extends DeliverWebhookJob {
            public function __construct(array $data, ApplicationContext $context, private RecordingHttpClient $http)
            {
                parent::__construct($data, $context);
            }

            protected function createClient(): Client
            {
                return new Client($this->http, new NullLogger());
            }
        };
    }

    public function testQueuedDeliveriesSignWithTheConfiguredHeaderAndAlgorithm(): void
    {
        $this->boot("['signature_header' => 'X-Acme-Signature', 'signature_algorithm' => 'sha512', "
            . "'require_https' => true]");
        $http = new RecordingHttpClient(200);

        $this->job($this->delivery('whsec_test'), $http)->handle();

        $headers = $http->lastHeaders();
        self::assertArrayHasKey('x-acme-signature', $headers);
        self::assertArrayNotHasKey('x-webhook-signature', $headers);
        self::assertTrue(WebhookSignature::verify($http->lastBody(), $headers['x-acme-signature'], 'whsec_test', 300, 'sha512'));
        self::assertFalse(WebhookSignature::verify($http->lastBody(), $headers['x-acme-signature'], 'whsec_test', 300));
        self::assertArrayHasKey('x-webhook-timestamp', $headers);
    }

    public function testFailedAttemptsBackOffOnTheConfiguredSchedule(): void
    {
        $this->boot("['retry' => ['max_attempts' => 5, 'backoff' => [7, 11]]]");
        $delivery = $this->delivery('whsec_test');

        $first = $this->job($delivery, new RecordingHttpClient(422));
        $first->handle();
        self::assertSame(7, $first->requestedReleaseDelay());

        $second = $this->job($delivery, new RecordingHttpClient(422));
        $second->handle();
        self::assertSame(11, $second->requestedReleaseDelay());

        $third = $this->job($delivery, new RecordingHttpClient(422));
        $third->handle();
        self::assertSame(11, $third->requestedReleaseDelay(), 'the last step repeats');
    }

    public function testTheDeliveryServiceSignsWithTheSameSchemeAsSubscriptions(): void
    {
        $http = new RecordingHttpClient(200);
        $service = new WebhookDeliveryService(new Client($http, new NullLogger()), new NullLogger());

        self::assertTrue($service->deliverWebhook(
            'https://93.184.216.34/hook',
            ['event' => 'order.shipped', 'url' => 'https://x.test/a'],
            ['secret' => 'whsec_svc']
        ));

        $headers = $http->lastHeaders();
        self::assertArrayNotHasKey('x-glueful-signature', $headers);
        self::assertArrayHasKey('x-webhook-signature', $headers);
        self::assertTrue(WebhookSignature::verify($http->lastBody(), $headers['x-webhook-signature'], 'whsec_svc'));
        self::assertTrue($service->verifySignature($http->lastBody(), $headers['x-webhook-signature'], 'whsec_svc'));
    }

    public function testTheDeliveryServiceHonoursTheConfiguredHeaderAndAlgorithm(): void
    {
        $this->boot("['signature_header' => 'X-Acme-Signature', 'signature_algorithm' => 'sha512']");
        $http = new RecordingHttpClient(200);
        $service = new WebhookDeliveryService(
            new Client($http, new NullLogger()),
            new NullLogger(),
            null,
            $this->context
        );

        $service->deliverWebhook('https://93.184.216.34/hook', ['event' => 'x'], ['secret' => 'whsec_svc']);

        $headers = $http->lastHeaders();
        self::assertArrayHasKey('x-acme-signature', $headers);
        self::assertTrue(
            WebhookSignature::verify($http->lastBody(), $headers['x-acme-signature'], 'whsec_svc', 300, 'sha512')
        );
    }

    public function testAnUnsupportedAlgorithmIsRefusedRatherThanSigningWithSomethingElse(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        WebhookSignature::generate('{}', 'secret', time(), 'not-a-hash');
    }
}

/**
 * Records the last request (headers lower-cased, body as sent) and answers a fixed status.
 */
final class RecordingHttpClient implements HttpClientInterface
{
    /** @var array<string, mixed> */
    public array $lastOptions = [];

    public function __construct(private int $status)
    {
    }

    /** @return array<string, string> */
    public function lastHeaders(): array
    {
        $out = [];
        foreach ((array) ($this->lastOptions['headers'] ?? []) as $name => $value) {
            if (is_int($name) && is_string($value) && str_contains($value, ':')) {
                [$name, $value] = array_map('trim', explode(':', $value, 2));
            }
            $out[strtolower((string) $name)] = is_array($value) ? (string) reset($value) : (string) $value;
        }

        return $out;
    }

    public function lastBody(): string
    {
        $body = $this->lastOptions['body'] ?? null;
        if (is_string($body)) {
            return $body;
        }
        if (isset($this->lastOptions['json'])) {
            return (string) json_encode($this->lastOptions['json']);
        }

        return '';
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $this->lastOptions = $options;
        $status = $this->status;

        return new class ($status) implements ResponseInterface {
            public function __construct(private int $status)
            {
            }
            public function getStatusCode(): int
            {
                return $this->status;
            }
            public function getHeaders(bool $throw = true): array
            {
                return [];
            }
            public function getContent(bool $throw = true): string
            {
                return 'ok';
            }
            /** @return array<int|string, mixed> */
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
        throw new \BadMethodCallException('not used');
    }

    public function withOptions(array $options): static
    {
        return $this;
    }
}

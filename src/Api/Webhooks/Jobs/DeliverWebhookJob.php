<?php

declare(strict_types=1);

namespace Glueful\Api\Webhooks\Jobs;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Api\Webhooks\WebhookDelivery;
use Glueful\Api\Webhooks\WebhookSignature;
use Glueful\Api\Webhooks\WebhookSubscription;
use Glueful\Events\EventService;
use Glueful\Events\Webhook\WebhookDeliveredEvent;
use Glueful\Events\Webhook\WebhookFailedEvent;
use Glueful\Http\Client;
use Glueful\Http\Response\Response;
use Glueful\Queue\Job;

/**
 * Queue job for delivering webhooks with retry logic
 *
 * Handles the actual HTTP delivery of webhooks including:
 * - HMAC signature generation
 * - Request timeout handling
 * - Exponential backoff retry
 * - Delivery status tracking
 */
class DeliverWebhookJob extends Job
{
    private const IPV6_BLOCKED_CIDRS = [
        '::/128',          // unspecified
        '::1/128',         // loopback
        'fe80::/10',       // link-local
        'fc00::/7',        // unique-local
        'fec0::/10',       // deprecated site-local
        '2001:db8::/32',   // documentation
        '2001::/32',       // Teredo
        '2002::/16',       // 6to4
        '2001:10::/28',    // ORCHID
        '2001:20::/28',    // ORCHIDv2
        '100::/64',        // discard-only
    ];
    /** @var string|null Queue name for webhook deliveries */
    protected ?string $queue = 'webhooks';

    /** @var array<int> Backoff delays in seconds: 1m, 5m, 30m, 2h, 12h */
    private array $backoff = [60, 300, 1800, 7200, 43200];

    /** @var int Request timeout in seconds */
    private const TIMEOUT = 30;

    /** @var array<string, string> Resolved IP addresses for DNS rebinding protection */
    private array $resolvedIps = [];

    public function __construct(array $data = [], ?ApplicationContext $context = null)
    {
        parent::__construct($data);
        $this->context = $context;
    }

    /**
     * Execute the webhook delivery
     */
    public function handle(): void
    {
        $deliveryId = $this->getData()['delivery_id'] ?? null;
        if ($deliveryId === null) {
            return;
        }

        if ($this->context === null) {
            return;
        }

        $delivery = WebhookDelivery::query($this->context)->find($deliveryId);
        if (!$delivery instanceof WebhookDelivery) {
            return;
        }

        $subscription = $this->getSubscription($delivery);
        if ($subscription === null || !$subscription->is_active) {
            $delivery->markFailed(0, 'Subscription disabled or not found');
            return;
        }

        $urlError = $this->validateWebhookUrl($subscription->url);
        if ($urlError !== null) {
            $delivery->markFailed(0, $urlError);
            $this->dispatchEvent(new WebhookFailedEvent(
                $subscription->url,
                $delivery->payload,
                0,
                $urlError,
                0.0
            ));
            return;
        }

        $delivery->incrementAttempts();
        $startTime = microtime(true);

        try {
            $response = $this->sendRequest($delivery, $subscription);
            $statusCode = $response->getStatusCode();
            $body = $response->getContent();
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($statusCode >= 200 && $statusCode < 300) {
                $delivery->markDelivered($statusCode, $body);
                $this->dispatchEvent(new WebhookDeliveredEvent(
                    $subscription->url,
                    $delivery->payload,
                    $statusCode,
                    $duration
                ));
            } else {
                $this->handleFailure($delivery, $subscription->url, $statusCode, $body, $duration);
            }
        } catch (\Throwable $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $this->handleFailure($delivery, $subscription->url, 0, $e->getMessage(), $duration);
        }
    }

    /**
     * Get the subscription for a delivery
     */
    private function getSubscription(WebhookDelivery $delivery): ?WebhookSubscription
    {
        // Check if relation is already loaded
        if ($delivery->relationLoaded('subscription')) {
            $relation = $delivery->getRelation('subscription');
            return $relation instanceof WebhookSubscription ? $relation : null;
        }

        // Load the relation via the method
        return $delivery->getRelationValue('subscription');
    }

    /**
     * Send the HTTP request to the webhook endpoint
     */
    private function sendRequest(WebhookDelivery $delivery, WebhookSubscription $subscription): Response
    {
        $payload = json_encode($delivery->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            throw new \RuntimeException('Failed to encode webhook payload');
        }

        $timestamp = time();
        $signature = WebhookSignature::generate($payload, $subscription->secret, $timestamp);

        $client = $this->createClient();

        return $client->post($subscription->url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Webhook-ID' => $delivery->uuid,
                'X-Webhook-Event' => $delivery->event,
                'X-Webhook-Timestamp' => (string) $timestamp,
                'X-Webhook-Signature' => $signature,
                'User-Agent' => $this->getUserAgent(),
            ],
            'body' => $payload,
        ]);
    }

    /**
     * Create HTTP client for webhook delivery
     *
     * Uses resolved IPs from validation to prevent DNS rebinding attacks.
     */
    private function createClient(): Client
    {
        $timeout = $this->getConfigTimeout();

        // Build resolve map for DNS rebinding protection
        $resolve = [];
        foreach ($this->resolvedIps as $host => $ip) {
            $resolve[$host] = $ip;
        }

        $options = [
            'timeout' => $timeout,
            'max_redirects' => 0,
        ];

        // Add DNS pinning if we have resolved IPs
        if ($resolve !== []) {
            $options['resolve'] = $resolve;
        }

        // Get the HTTP client from the container if available
        if ($this->context !== null) {
            $container = container($this->context);
            if ($container->has(Client::class)) {
                /** @var Client $client */
                $client = $container->get(Client::class);
                return $client->createScopedClient($options);
            }
        }

        // Create a new client instance using Symfony HttpClient
        $httpClient = \Symfony\Component\HttpClient\HttpClient::create($options);

        $logger = new \Psr\Log\NullLogger();
        if ($this->context !== null) {
            $container = container($this->context);
            if ($container->has(\Psr\Log\LoggerInterface::class)) {
                $logger = $container->get(\Psr\Log\LoggerInterface::class);
            }
        }

        return new Client($httpClient, $logger, $this->context);
    }

    /**
     * Handle delivery failure
     */
    private function handleFailure(
        WebhookDelivery $delivery,
        string $url,
        int $statusCode,
        string $body,
        float $duration
    ): void {
        $maxAttempts = $this->getMaxAttempts();

        if ($delivery->attempts >= $maxAttempts) {
            // All retries exhausted
            $delivery->markFailed($statusCode, $body);
            $this->dispatchEvent(new WebhookFailedEvent(
                $url,
                $delivery->payload,
                $statusCode,
                $body,
                $duration
            ));
        } else {
            // Schedule retry with exponential backoff
            $delayIndex = min($delivery->attempts - 1, count($this->backoff) - 1);
            $delay = $this->backoff[$delayIndex];
            $nextRetry = new \DateTime();
            $nextRetry->modify("+{$delay} seconds");
            $delivery->scheduleRetry($nextRetry);
            $this->release($delay);
        }
    }

    /**
     * Handle job failure after all attempts exhausted
     */
    public function failed(\Exception $exception): void
    {
        $deliveryId = $this->getData()['delivery_id'] ?? null;
        if ($deliveryId !== null) {
            if ($this->context === null) {
                return;
            }

            $delivery = WebhookDelivery::query($this->context)->find($deliveryId);
            if ($delivery instanceof WebhookDelivery) {
                $delivery->markFailed(0, 'Job failed: ' . $exception->getMessage());
            }
        }

        parent::failed($exception);
    }

    private function dispatchEvent(object $event): void
    {
        if ($this->context === null) {
            return;
        }

        try {
            app($this->context, EventService::class)->dispatch($event);
        } catch (\Throwable) {
            // best-effort only
        }
    }

    /**
     * Get maximum number of delivery attempts
     */
    public function getMaxAttempts(): int
    {
        if (function_exists('config') && $this->context !== null) {
            $config = (array) config($this->context, 'api.webhooks.retry', []);
            return (int) ($config['max_attempts'] ?? 5);
        }

        return 5;
    }

    /**
     * Validate webhook URL for SSRF protection
     *
     * Validates the URL scheme, host, and all resolved IP addresses to prevent
     * Server-Side Request Forgery attacks. Stores resolved IPs for DNS rebinding protection.
     */
    private function validateWebhookUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return 'Invalid URL format';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme === '') {
            return 'Webhook URL must include a scheme';
        }

        if (
            function_exists('config')
            && $this->context !== null
            && (bool) config($this->context, 'api.webhooks.require_https', false)
        ) {
            if ($scheme !== 'https') {
                return 'Webhook URL must use HTTPS';
            }
        } elseif (!in_array($scheme, ['http', 'https'], true)) {
            return 'Webhook URL must use http or https';
        }

        $host = $parts['host'] ?? '';
        if ($host === '') {
            return 'Webhook URL must include a host';
        }

        $lowerHost = strtolower($host);
        if ($lowerHost === 'localhost' || str_ends_with($lowerHost, '.localhost')) {
            return 'Webhook URL host is not allowed';
        }

        // Direct IP address in URL
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ipError = $this->validateIpAddress($host);
            if ($ipError !== null) {
                return $ipError;
            }
            // Store the IP for DNS rebinding protection
            $this->resolvedIps[$host] = $host;
            return null;
        }

        // Resolve hostname to IP addresses
        $ips = $this->resolveHostname($host);
        if ($ips === []) {
            return 'Webhook URL host could not be resolved';
        }

        // Validate all resolved IPs
        foreach ($ips as $ip) {
            $ipError = $this->validateIpAddress($ip);
            if ($ipError !== null) {
                return $ipError;
            }
        }

        // Store first valid IP for DNS rebinding protection
        $this->resolvedIps[$host] = $ips[0];

        return null;
    }

    /**
     * Resolve hostname to IP addresses (both IPv4 and IPv6)
     *
     * @return array<string>
     */
    private function resolveHostname(string $host): array
    {
        $ips = @gethostbynamel($host) ?: [];

        $records = @dns_get_record($host, DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * Validate an IP address against SSRF blocklists
     *
     * Blocks private ranges, reserved ranges, loopback, link-local,
     * unique-local (RFC4193), and documentation addresses (RFC3849).
     */
    private function validateIpAddress(string $ip): ?string
    {
        // IPv4 validation
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return 'Webhook URL host is not allowed';
            }
            return null;
        }

        // IPv6 validation
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $lowerIp = strtolower($ip);

            // Strip zone ID (e.g., fe80::1%eth0 -> fe80::1)
            $zonePos = strpos($lowerIp, '%');
            if ($zonePos !== false) {
                $lowerIp = substr($lowerIp, 0, $zonePos);
            }

            // Check IPv4-mapped IPv6 addresses (::ffff:x.x.x.x)
            if (str_starts_with($lowerIp, '::ffff:')) {
                $ipv4 = substr($lowerIp, 7);
                if (
                    !filter_var(
                        $ipv4,
                        FILTER_VALIDATE_IP,
                        FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
                    )
                ) {
                    return 'Webhook URL host is not allowed';
                }
                return null;
            }

            foreach (self::IPV6_BLOCKED_CIDRS as $cidr) {
                if ($this->ipInCidr($lowerIp, $cidr)) {
                    return 'Webhook URL host is not allowed';
                }
            }

            return null;
        }

        return 'Invalid IP address format';
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        $packed = inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        [$subnet, $bits] = explode('/', $cidr, 2);
        $bits = (int) $bits;
        $subnetPacked = inet_pton($subnet);
        if ($subnetPacked === false) {
            return false;
        }

        $maskBytes = '';
        $fullBytes = intdiv($bits, 8);
        $remainingBits = $bits % 8;

        $maskBytes .= str_repeat("\xff", $fullBytes);
        if ($remainingBits > 0) {
            $maskBytes .= chr(0xff << (8 - $remainingBits));
        }
        $maskBytes = str_pad($maskBytes, strlen($packed), "\x00");

        return ($packed & $maskBytes) === ($subnetPacked & $maskBytes);
    }

    /**
     * Get request timeout from config
     */
    private function getConfigTimeout(): int
    {
        if (function_exists('config') && $this->context !== null) {
            return (int) config($this->context, 'api.webhooks.timeout', self::TIMEOUT);
        }

        return self::TIMEOUT;
    }

    /**
     * Get User-Agent header value
     */
    private function getUserAgent(): string
    {
        if (function_exists('config') && $this->context !== null) {
            return (string) config($this->context, 'api.webhooks.user_agent', 'Glueful-Webhooks/1.0');
        }

        return 'Glueful-Webhooks/1.0';
    }

    /**
     * Get job timeout (longer than request timeout to allow for processing)
     */
    public function getTimeout(): int
    {
        return $this->getConfigTimeout() + 30;
    }
}

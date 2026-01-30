<?php

declare(strict_types=1);

namespace Glueful\Http\Services;

use Glueful\Http\Client;
use Glueful\Events\Webhook\WebhookDeliveredEvent;
use Glueful\Events\Webhook\WebhookFailedEvent;
use Glueful\Events\EventService;
use Psr\Log\LoggerInterface;

/**
 * Webhook Delivery Service
 *
 * Handles reliable webhook delivery with retry mechanisms, signature generation,
 * batch processing, and comprehensive event logging for integration monitoring.
 */
class WebhookDeliveryService
{
    public function __construct(
        private Client $httpClient,
        private LoggerInterface $logger,
        private ?EventService $events = null
    ) {
    }

    /**
     * Deliver a single webhook
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     */
    public function deliverWebhook(
        string $url,
        array $payload,
        array $options = []
    ): bool {
        $startTime = microtime(true);

        try {
            $client = $this->createWebhookClient($options);
            $requestOptions = $this->buildRequestOptions($payload, $options);

            $response = $client->post($url, $requestOptions);
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($response->isSuccessful()) {
                $this->logger->info('Webhook delivered successfully', [
                    'url' => $this->sanitizeUrl($url),
                    'status_code' => $response->getStatusCode(),
                    'duration_ms' => $duration,
                    'payload_size' => strlen(json_encode($payload))
                ]);

                $this->events?->dispatch(new WebhookDeliveredEvent(
                    $url,
                    $payload,
                    $response->getStatusCode(),
                    $duration
                ));

                return true;
            } else {
                $this->logWebhookFailure($url, $payload, $response->getStatusCode(), $duration);

                $this->events?->dispatch(new WebhookFailedEvent(
                    $url,
                    $payload,
                    $response->getStatusCode(),
                    'HTTP error response',
                    $duration
                ));

                return false;
            }
        } catch (\Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            $this->logWebhookFailure($url, $payload, 0, $duration, $e->getMessage());

            $this->events?->dispatch(new WebhookFailedEvent(
                $url,
                $payload,
                0,
                $e->getMessage(),
                $duration
            ));

            return false;
        }
    }

    /**
     * Deliver multiple webhooks in batch
     * @param array<int|string, array{
     *     url: string,
     *     payload: array<string, mixed>,
     *     options?: array<string, mixed>
     * }> $webhooks
     * @return array<int|string, array{
     *     success: bool,
     *     status_code?: int,
     *     duration_ms: float,
     *     response?: string,
     *     error?: string
     * }>
     */
    public function deliverBatchWebhooks(array $webhooks): array
    {
        $responses = [];
        $requests = [];

        // Prepare all requests
        foreach ($webhooks as $key => $webhook) {
            $client = $this->createWebhookClient($webhook['options'] ?? []);
            $requestOptions = $this->buildRequestOptions(
                $webhook['payload'],
                $webhook['options'] ?? []
            );

            $requests[$key] = [
                'method' => 'POST',
                'url' => $webhook['url'],
                'options' => $requestOptions
            ];
        }

        // Execute batch requests
        $asyncResponses = $this->httpClient->requestBatch($requests);

        // Process responses
        foreach ($asyncResponses as $key => $response) {
            $webhook = $webhooks[$key];
            $startTime = microtime(true);

            try {
                $statusCode = $response->getStatusCode();
                $duration = round((microtime(true) - $startTime) * 1000, 2);
                $success = $statusCode >= 200 && $statusCode < 300;

                $responses[$key] = [
                    'success' => $success,
                    'status_code' => $statusCode,
                    'duration_ms' => $duration,
                    'response' => $response->getContent()
                ];

                if ($success) {
                    $this->events?->dispatch(new WebhookDeliveredEvent(
                        $webhook['url'],
                        $webhook['payload'],
                        $statusCode,
                        $duration
                    ));
                } else {
                    $this->events?->dispatch(new WebhookFailedEvent(
                        $webhook['url'],
                        $webhook['payload'],
                        $statusCode,
                        'HTTP error response',
                        $duration
                    ));
                }
            } catch (\Exception $e) {
                $responses[$key] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'duration_ms' => round((microtime(true) - $startTime) * 1000, 2)
                ];

                $this->events?->dispatch(new WebhookFailedEvent(
                    $webhook['url'],
                    $webhook['payload'],
                    0,
                    $e->getMessage(),
                    $responses[$key]['duration_ms']
                ));
            }
        }

        $this->logBatchResults($responses);
        return $responses;
    }

    /**
     * Deliver webhook with retry mechanism
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     */
    public function deliverWebhookWithRetries(
        string $url,
        array $payload,
        array $options = [],
        int $maxRetries = 3,
        int $delayMs = 1000
    ): bool {
        $attempt = 1;

        while ($attempt <= $maxRetries) {
            $success = $this->deliverWebhook($url, $payload, $options);

            if ($success) {
                if ($attempt > 1) {
                    $this->logger->info('Webhook delivered successfully after retries', [
                        'url' => $this->sanitizeUrl($url),
                        'attempt' => $attempt,
                        'max_attempts' => $maxRetries
                    ]);
                }
                return true;
            }

            if ($attempt < $maxRetries) {
                $delay = $delayMs * pow(2, $attempt - 1); // Exponential backoff
                usleep($delay * 1000); // Convert to microseconds

                $this->logger->warning('Webhook delivery failed, retrying', [
                    'url' => $this->sanitizeUrl($url),
                    'attempt' => $attempt,
                    'max_attempts' => $maxRetries,
                    'next_delay_ms' => $delay
                ]);
            }

            $attempt++;
        }

        $this->logger->error('Webhook delivery failed after all retries', [
            'url' => $this->sanitizeUrl($url),
            'attempts' => $maxRetries
        ]);

        return false;
    }

    /**
     * Create a webhook-specific HTTP client
     * @param array<string, mixed> $options
     */
    private function createWebhookClient(array $options): Client
    {
        return $this->httpClient->createScopedClient([
            'timeout' => $options['timeout'] ?? 5,
            'max_redirects' => $options['max_redirects'] ?? 0,
            'headers' => array_merge([
                'Content-Type' => 'application/json',
                'User-Agent' => 'Glueful-Webhook/1.0'
            ], $options['headers'] ?? [])
        ]);
    }

    /**
     * Build request options including signature
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function buildRequestOptions(array $payload, array $options): array
    {
        $requestOptions = ['json' => $payload];

        // Add HMAC signature if secret is provided
        if (isset($options['secret'])) {
            $signature = $this->generateSignature($payload, $options['secret']);
            $requestOptions['headers']['X-Glueful-Signature'] = $signature;
        }

        // Add timestamp header
        $requestOptions['headers']['X-Glueful-Timestamp'] = time();

        // Add custom headers
        if (isset($options['headers'])) {
            $requestOptions['headers'] = array_merge(
                $requestOptions['headers'],
                $options['headers']
            );
        }

        return $requestOptions;
    }

    /**
     * Generate HMAC signature for webhook security
     * @param array<string, mixed> $payload
     */
    private function generateSignature(array $payload, string $secret): string
    {
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);
        return 'sha256=' . hash_hmac('sha256', $jsonPayload, $secret);
    }

    /**
     * Verify webhook signature
     */
    public function verifySignature(string $payload, string $signature, string $secret): bool
    {
        $expectedSignature = 'sha256=' . hash_hmac('sha256', $payload, $secret);
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Log webhook delivery failure
     * @param array<string, mixed> $payload
     */
    private function logWebhookFailure(
        string $url,
        array $payload,
        int $statusCode,
        float $duration,
        ?string $error = null
    ): void {
        $this->logger->error('Webhook delivery failed', [
            'url' => $this->sanitizeUrl($url),
            'status_code' => $statusCode,
            'duration_ms' => $duration,
            'payload_size' => strlen(json_encode($payload)),
            'error' => $error
        ]);
    }

    /**
     * Log batch delivery results
     * @param array<int|string, array{
     *     success: bool,
     *     status_code?: int,
     *     duration_ms: float,
     *     response?: string,
     *     error?: string
     * }> $responses
     */
    private function logBatchResults(array $responses): void
    {
        $successful = count(array_filter($responses, fn($r) => $r['success']));
        $total = count($responses);
        $failed = $total - $successful;

        $this->logger->info('Batch webhook delivery completed', [
            'total' => $total,
            'successful' => $successful,
            'failed' => $failed,
            'success_rate' => $total > 0 ? round(($successful / $total) * 100, 2) : 0
        ]);
    }

    /**
     * Sanitize URL for logging
     */
    private function sanitizeUrl(string $url): string
    {
        $parsed = parse_url($url);
        if (!$parsed) {
            return '[INVALID_URL]';
        }

        // Remove sensitive query parameters
        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $queryParams);
            $sensitiveParams = ['api_key', 'token', 'secret'];
            foreach ($sensitiveParams as $param) {
                if (isset($queryParams[$param])) {
                    $queryParams[$param] = '[REDACTED]';
                }
            }
            $parsed['query'] = http_build_query($queryParams);
        }

        return ($parsed['scheme'] ?? 'http') . '://' .
               ($parsed['host'] ?? '') .
               ($parsed['path'] ?? '') .
               (isset($parsed['query']) ? '?' . $parsed['query'] : '');
    }

    /**
     * Create webhook delivery configuration
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function createConfig(array $config = []): array
    {
        return array_merge([
            'timeout' => 5,
            'max_redirects' => 0,
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => 'Glueful-Webhook/1.0'
            ],
            'retry' => [
                'max_attempts' => 3,
                'delay_ms' => 1000,
                'backoff_multiplier' => 2.0
            ]
        ], $config);
    }
}

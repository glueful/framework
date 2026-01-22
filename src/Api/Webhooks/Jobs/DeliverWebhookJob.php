<?php

declare(strict_types=1);

namespace Glueful\Api\Webhooks\Jobs;

use Glueful\Api\Webhooks\WebhookDelivery;
use Glueful\Api\Webhooks\WebhookSignature;
use Glueful\Api\Webhooks\WebhookSubscription;
use Glueful\Events\Event;
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
    /** @var string|null Queue name for webhook deliveries */
    protected ?string $queue = 'webhooks';

    /** @var array<int> Backoff delays in seconds: 1m, 5m, 30m, 2h, 12h */
    private array $backoff = [60, 300, 1800, 7200, 43200];

    /** @var int Request timeout in seconds */
    private const TIMEOUT = 30;

    /**
     * Execute the webhook delivery
     */
    public function handle(): void
    {
        $deliveryId = $this->getData()['delivery_id'] ?? null;
        if ($deliveryId === null) {
            return;
        }

        $delivery = WebhookDelivery::find($deliveryId);
        if ($delivery === null) {
            return;
        }

        $subscription = $this->getSubscription($delivery);
        if ($subscription === null || !$subscription->is_active) {
            $delivery->markFailed(0, 'Subscription disabled or not found');
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
                Event::dispatch(new WebhookDeliveredEvent(
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
     */
    private function createClient(): Client
    {
        $timeout = $this->getConfigTimeout();

        // Get the HTTP client from the container if available
        if (function_exists('app') && app()->has(Client::class)) {
            $client = app(Client::class);
            return $client->createScopedClient([
                'timeout' => $timeout,
                'max_redirects' => 0,
            ]);
        }

        // Create a new client instance using Symfony HttpClient
        $httpClient = \Symfony\Component\HttpClient\HttpClient::create([
            'timeout' => $timeout,
            'max_redirects' => 0,
        ]);

        $logger = function_exists('app') && app()->has(\Psr\Log\LoggerInterface::class)
            ? app(\Psr\Log\LoggerInterface::class)
            : new \Psr\Log\NullLogger();

        return new Client($httpClient, $logger);
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
            Event::dispatch(new WebhookFailedEvent(
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
            $delivery = WebhookDelivery::find($deliveryId);
            if ($delivery !== null) {
                $delivery->markFailed(0, 'Job failed: ' . $exception->getMessage());
            }
        }

        parent::failed($exception);
    }

    /**
     * Get maximum number of delivery attempts
     */
    public function getMaxAttempts(): int
    {
        if (function_exists('config')) {
            $config = (array) config('api.webhooks.retry', []);
            return (int) ($config['max_attempts'] ?? 5);
        }

        return 5;
    }

    /**
     * Get request timeout from config
     */
    private function getConfigTimeout(): int
    {
        if (function_exists('config')) {
            return (int) config('api.webhooks.timeout', self::TIMEOUT);
        }

        return self::TIMEOUT;
    }

    /**
     * Get User-Agent header value
     */
    private function getUserAgent(): string
    {
        if (function_exists('config')) {
            return (string) config('api.webhooks.user_agent', 'Glueful-Webhooks/1.0');
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

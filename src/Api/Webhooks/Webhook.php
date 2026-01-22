<?php

declare(strict_types=1);

namespace Glueful\Api\Webhooks;

use Glueful\Api\Webhooks\Contracts\WebhookDispatcherInterface;

/**
 * Webhook Facade
 *
 * Provides a static interface for working with webhooks. This facade
 * offers convenient methods for dispatching webhooks, creating
 * subscriptions, and managing deliveries.
 *
 * @example
 * ```php
 * // Dispatch a webhook event
 * Webhook::dispatch('user.created', ['id' => 123, 'name' => 'John']);
 *
 * // Create a new subscription
 * $subscription = Webhook::subscribe(['user.*', 'order.created'], 'https://example.com/webhooks');
 *
 * // Get subscription by UUID
 * $subscription = Webhook::findSubscription('wh_sub_abc123');
 *
 * // Test a webhook endpoint
 * Webhook::test('https://example.com/webhooks', 'webhook.test');
 * ```
 */
class Webhook
{
    private static ?WebhookDispatcherInterface $dispatcher = null;

    /**
     * Set the dispatcher instance
     *
     * @param WebhookDispatcherInterface $dispatcher The dispatcher to use
     */
    public static function setDispatcher(WebhookDispatcherInterface $dispatcher): void
    {
        self::$dispatcher = $dispatcher;
    }

    /**
     * Get the dispatcher instance
     *
     * @return WebhookDispatcherInterface
     * @throws \RuntimeException If dispatcher is not configured
     */
    public static function getDispatcher(): WebhookDispatcherInterface
    {
        if (self::$dispatcher === null) {
            // Try to get from container
            if (function_exists('app') && app()->has(WebhookDispatcherInterface::class)) {
                self::$dispatcher = app(WebhookDispatcherInterface::class);
            } elseif (function_exists('app') && app()->has(WebhookDispatcher::class)) {
                self::$dispatcher = app(WebhookDispatcher::class);
            } else {
                // Create a default dispatcher
                self::$dispatcher = new WebhookDispatcher();
            }
        }

        return self::$dispatcher;
    }

    /**
     * Dispatch a webhook event to all matching subscribers
     *
     * @param string $event Event name (e.g., 'user.created')
     * @param array<string, mixed> $data Event data
     * @param array<string, mixed> $options Additional options
     * @return array<WebhookDelivery> Created delivery records
     */
    public static function dispatch(string $event, array $data, array $options = []): array
    {
        return self::getDispatcher()->dispatch($event, $data, $options);
    }

    /**
     * Create a new webhook subscription
     *
     * @param array<string>|string $events Events to subscribe to (supports wildcards)
     * @param string $url The webhook endpoint URL
     * @param array<string, mixed> $metadata Optional metadata
     * @return WebhookSubscription The created subscription
     */
    public static function subscribe(array|string $events, string $url, array $metadata = []): WebhookSubscription
    {
        $events = is_array($events) ? $events : [$events];

        return WebhookSubscription::create([
            'url' => $url,
            'events' => $events,
            'is_active' => true,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Find a subscription by UUID
     *
     * @param string $uuid The subscription UUID
     * @return WebhookSubscription|null The subscription or null
     */
    public static function findSubscription(string $uuid): ?WebhookSubscription
    {
        $result = WebhookSubscription::query()
            ->where('uuid', $uuid)
            ->first();

        return $result instanceof WebhookSubscription ? $result : null;
    }

    /**
     * Find a subscription by ID
     *
     * @param int $id The subscription ID
     * @return WebhookSubscription|null The subscription or null
     */
    public static function findSubscriptionById(int $id): ?WebhookSubscription
    {
        return WebhookSubscription::find($id);
    }

    /**
     * Get all active subscriptions
     *
     * @return array<WebhookSubscription> Active subscriptions
     */
    public static function activeSubscriptions(): array
    {
        $results = WebhookSubscription::query()
            ->where('is_active', true)
            ->get();

        $subscriptions = [];
        /** @var WebhookSubscription $subscription */
        foreach ($results as $subscription) {
            $subscriptions[] = $subscription;
        }

        return $subscriptions;
    }

    /**
     * Get subscriptions for a specific event
     *
     * @param string $event The event name
     * @return array<WebhookSubscription> Matching subscriptions
     */
    public static function subscriptionsForEvent(string $event): array
    {
        $all = WebhookSubscription::query()
            ->where('is_active', true)
            ->get();

        $matching = [];

        /** @var WebhookSubscription $subscription */
        foreach ($all as $subscription) {
            if ($subscription->listensTo($event)) {
                $matching[] = $subscription;
            }
        }

        return $matching;
    }

    /**
     * Find a delivery by UUID
     *
     * @param string $uuid The delivery UUID
     * @return WebhookDelivery|null The delivery or null
     */
    public static function findDelivery(string $uuid): ?WebhookDelivery
    {
        $result = WebhookDelivery::query()
            ->where('uuid', $uuid)
            ->first();

        return $result instanceof WebhookDelivery ? $result : null;
    }

    /**
     * Get pending deliveries that are ready for retry
     *
     * @param int $limit Maximum number of deliveries to return
     * @return array<WebhookDelivery> Pending deliveries
     */
    public static function pendingRetries(int $limit = 100): array
    {
        $now = date('Y-m-d H:i:s');

        $results = WebhookDelivery::query()
            ->where('status', WebhookDelivery::STATUS_RETRYING)
            ->where('next_retry_at', '<=', $now)
            ->orderBy('next_retry_at', 'asc')
            ->limit($limit)
            ->get();

        $deliveries = [];
        /** @var WebhookDelivery $delivery */
        foreach ($results as $delivery) {
            $deliveries[] = $delivery;
        }

        return $deliveries;
    }

    /**
     * Get failed deliveries
     *
     * @param int $limit Maximum number of deliveries to return
     * @param string|null $since Only get failures since this date
     * @return array<WebhookDelivery> Failed deliveries
     */
    public static function failedDeliveries(int $limit = 100, ?string $since = null): array
    {
        $query = WebhookDelivery::query()
            ->where('status', WebhookDelivery::STATUS_FAILED)
            ->orderBy('created_at', 'desc')
            ->limit($limit);

        if ($since !== null) {
            $query->where('created_at', '>=', $since);
        }

        $deliveries = [];
        /** @var WebhookDelivery $delivery */
        foreach ($query->get() as $delivery) {
            $deliveries[] = $delivery;
        }

        return $deliveries;
    }

    /**
     * Retry a failed delivery
     *
     * @param string $uuid The delivery UUID to retry
     * @return bool Whether the retry was scheduled
     */
    public static function retry(string $uuid): bool
    {
        $delivery = self::findDelivery($uuid);

        if ($delivery === null) {
            return false;
        }

        if (!$delivery->isFailed() && !$delivery->isRetrying()) {
            return false;
        }

        $delivery->resetForRetry();

        // Queue the delivery job
        $job = new Jobs\DeliverWebhookJob(['delivery_id' => $delivery->id]);

        if (function_exists('app') && app()->has(\Glueful\Queue\QueueManager::class)) {
            app(\Glueful\Queue\QueueManager::class)->push($job);
        }

        return true;
    }

    /**
     * Send a test webhook to an endpoint
     *
     * @param string $url The URL to test
     * @param string $event The event name (default: 'webhook.test')
     * @param string|null $secret Optional secret for signing
     * @return array{success: bool, status_code?: int, response?: string, error?: string}
     */
    public static function test(string $url, string $event = 'webhook.test', ?string $secret = null): array
    {
        $payload = [
            'event' => $event,
            'timestamp' => time(),
            'data' => [
                'message' => 'This is a test webhook',
                'test' => true,
            ],
        ];

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($jsonPayload === false) {
            return [
                'success' => false,
                'error' => 'Failed to encode payload',
            ];
        }

        $timestamp = time();
        $signature = $secret !== null
            ? WebhookSignature::generate($jsonPayload, $secret, $timestamp)
            : '';

        try {
            // Use Symfony HttpClient for the test
            $httpClient = \Symfony\Component\HttpClient\HttpClient::create([
                'timeout' => 30,
                'max_redirects' => 0,
            ]);

            $headers = [
                'Content-Type' => 'application/json',
                'X-Webhook-Event' => $event,
                'X-Webhook-Timestamp' => (string) $timestamp,
                'User-Agent' => 'Glueful-Webhooks/1.0',
            ];

            if ($signature !== '') {
                $headers['X-Webhook-Signature'] = $signature;
            }

            $response = $httpClient->request('POST', $url, [
                'headers' => $headers,
                'body' => $jsonPayload,
            ]);

            $statusCode = $response->getStatusCode();

            return [
                'success' => $statusCode >= 200 && $statusCode < 300,
                'status_code' => $statusCode,
                'response' => $response->getContent(false),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Verify a webhook signature
     *
     * @param string $payload The raw request payload
     * @param string $signature The signature from the request
     * @param string $secret The webhook secret
     * @param int|null $tolerance Max age in seconds (null = no check)
     * @return bool Whether the signature is valid
     */
    public static function verify(string $payload, string $signature, string $secret, ?int $tolerance = 300): bool
    {
        return WebhookSignature::verify($payload, $signature, $secret, $tolerance);
    }

    /**
     * Reset the facade instance (for testing)
     */
    public static function reset(): void
    {
        self::$dispatcher = null;
    }
}

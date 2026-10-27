<?php

declare(strict_types=1);

namespace Glueful\Api\Webhooks;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Api\Webhooks\Contracts\WebhookDispatcherInterface;
use Glueful\Api\Webhooks\Contracts\WebhookPayloadInterface;
use Glueful\Api\Webhooks\Events\WebhookDispatchedEvent;
use Glueful\Api\Webhooks\Jobs\DeliverWebhookJob;
use Glueful\Events\EventService;

/**
 * Central webhook dispatcher
 *
 * Orchestrates webhook dispatching by:
 * - Finding matching subscriptions for events
 * - Creating delivery records
 * - Queueing delivery jobs
 */
class WebhookDispatcher implements WebhookDispatcherInterface
{
    private WebhookPayloadInterface $payloadBuilder;
    private ?ApplicationContext $context;

    public function __construct(
        ?WebhookPayloadInterface $payloadBuilder = null,
        ?ApplicationContext $context = null
    ) {
        $this->context = $context;
        $this->payloadBuilder = $payloadBuilder ?? new WebhookPayload();
    }

    /**
     * Dispatch a webhook event to all matching subscribers
     *
     * Options (all optional) apply to the queued delivery jobs:
     * - `queue` (string): queue name, overriding `api.webhooks.queue`
     * - `connection` (string): queue connection, overriding `api.webhooks.connection`
     * - `delay` (int): seconds before the first delivery attempt
     *
     * @param string $event Event name (e.g., 'user.created')
     * @param array<string, mixed> $data Event data
     * @param array<string, mixed> $options Additional options (see above)
     * @return array<WebhookDelivery> Created delivery records
     */
    public function dispatch(string $event, array $data, array $options = []): array
    {
        $deliveries = [];
        $config = $this->getConfig();

        // Check if webhooks are enabled
        $isEnabled = (bool) ($config['enabled'] ?? true);
        if (!$isEnabled) {
            return $deliveries;
        }

        // Find active subscriptions for this event
        if ($this->context === null) {
            throw new \RuntimeException('ApplicationContext is required for webhook dispatch.');
        }

        $subscriptions = WebhookSubscription::query($this->context)
            ->where('is_active', true)
            ->get()
            ->filter(fn(WebhookSubscription $sub) => $sub->listensTo($event));

        /** @var WebhookSubscription $subscription */
        foreach ($subscriptions as $subscription) {
            $delivery = $this->createDelivery($subscription, $event, $data);
            $deliveries[] = $delivery;

            // Queue the delivery job
            $this->queueDelivery($delivery, $config, $options);
        }

        // Dispatch internal event if any deliveries were created
        if (count($deliveries) > 0) {
            if ($this->context !== null) {
                app($this->context, EventService::class)->dispatch(
                    new WebhookDispatchedEvent($event, count($deliveries))
                );
            }
        }

        return $deliveries;
    }

    /**
     * Create a delivery record
     *
     * @param array<string, mixed> $data
     */
    private function createDelivery(
        WebhookSubscription $subscription,
        string $event,
        array $data
    ): WebhookDelivery {
        if ($this->context === null) {
            throw new \RuntimeException('ApplicationContext is required for webhook delivery creation.');
        }

        $delivery = WebhookDelivery::query($this->context)->create([
            'subscription_id' => $subscription->id,
            'event' => $event,
            'payload' => $this->payloadBuilder->build($event, $data),
            'status' => WebhookDelivery::STATUS_PENDING,
            'attempts' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        if (!$delivery instanceof WebhookDelivery) {
            throw new \RuntimeException('Unexpected delivery model returned.');
        }

        return $delivery;
    }

    /**
     * Queue a delivery job
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $options Per-dispatch `queue`, `connection`, `delay`
     */
    private function queueDelivery(WebhookDelivery $delivery, array $config, array $options = []): void
    {
        if ($this->context === null) {
            return;
        }

        $queue = self::nonEmptyString($options['queue'] ?? null)
            ?? self::nonEmptyString($config['queue'] ?? null)
            ?? 'webhooks';
        $connection = self::nonEmptyString($options['connection'] ?? null)
            ?? self::nonEmptyString($config['connection'] ?? null);
        $delay = is_numeric($options['delay'] ?? null) ? max(0, (int) $options['delay']) : 0;

        DeliverWebhookJob::enqueue($this->context, $delivery->id, $queue, $connection, $delay);
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Get webhook configuration
     *
     * @return array<string, mixed>
     */
    private function getConfig(): array
    {
        if (function_exists('config') && $this->context !== null) {
            return (array) config($this->context, 'api.webhooks', []);
        }

        return [];
    }
}

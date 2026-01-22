<?php

declare(strict_types=1);

namespace Glueful\Api\Webhooks;

use Glueful\Api\Webhooks\Contracts\WebhookDispatcherInterface;
use Glueful\Api\Webhooks\Contracts\WebhookPayloadInterface;
use Glueful\Api\Webhooks\Events\WebhookDispatchedEvent;
use Glueful\Api\Webhooks\Jobs\DeliverWebhookJob;
use Glueful\Database\Connection;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Events\Event;
use Glueful\Queue\QueueManager;

/**
 * Central webhook dispatcher with auto-migration
 *
 * Orchestrates webhook dispatching by:
 * - Auto-creating required database tables on first use
 * - Finding matching subscriptions for events
 * - Creating delivery records
 * - Queueing delivery jobs
 */
class WebhookDispatcher implements WebhookDispatcherInterface
{
    private SchemaBuilderInterface $schema;
    private Connection $db;
    private bool $tablesEnsured = false;
    private WebhookPayloadInterface $payloadBuilder;

    public function __construct(
        ?Connection $connection = null,
        ?WebhookPayloadInterface $payloadBuilder = null
    ) {
        $this->db = $connection ?? new Connection();
        $this->schema = $this->db->getSchemaBuilder();
        $this->payloadBuilder = $payloadBuilder ?? new WebhookPayload();
    }

    /**
     * Dispatch a webhook event to all matching subscribers
     *
     * @param string $event Event name (e.g., 'user.created')
     * @param array<string, mixed> $data Event data
     * @param array<string, mixed> $options Additional options
     * @return array<WebhookDelivery> Created delivery records
     */
    public function dispatch(string $event, array $data, array $options = []): array
    {
        // Ensure tables exist on first use
        $this->ensureTables();

        $deliveries = [];
        $config = $this->getConfig();

        // Check if webhooks are enabled
        $isEnabled = (bool) ($config['enabled'] ?? true);
        if (!$isEnabled) {
            return $deliveries;
        }

        // Find active subscriptions for this event
        $subscriptions = WebhookSubscription::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn(WebhookSubscription $sub) => $sub->listensTo($event));

        /** @var WebhookSubscription $subscription */
        foreach ($subscriptions as $subscription) {
            $delivery = $this->createDelivery($subscription, $event, $data);
            $deliveries[] = $delivery;

            // Queue the delivery job
            $this->queueDelivery($delivery, $config);
        }

        // Dispatch internal event if any deliveries were created
        if (count($deliveries) > 0) {
            Event::dispatch(new WebhookDispatchedEvent($event, count($deliveries)));
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
        return WebhookDelivery::create([
            'subscription_id' => $subscription->id,
            'event' => $event,
            'payload' => $this->payloadBuilder->build($event, $data),
            'status' => WebhookDelivery::STATUS_PENDING,
            'attempts' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Queue a delivery job
     *
     * @param array<string, mixed> $config
     */
    private function queueDelivery(WebhookDelivery $delivery, array $config): void
    {
        $job = new DeliverWebhookJob(['delivery_id' => $delivery->id]);
        $job->setQueue($config['queue'] ?? 'webhooks');

        // Use queue manager to dispatch if available
        if (function_exists('app') && app()->has(QueueManager::class)) {
            app(QueueManager::class)->push($job);
        }
    }

    /**
     * Get webhook configuration
     *
     * @return array<string, mixed>
     */
    private function getConfig(): array
    {
        if (function_exists('config')) {
            return (array) config('api.webhooks', []);
        }

        return [];
    }

    /**
     * Ensure webhook tables exist
     *
     * Following the pattern from DatabaseLogHandler, tables are created
     * automatically at runtime if they don't exist.
     */
    private function ensureTables(): void
    {
        if ($this->tablesEnsured) {
            return;
        }

        $this->ensureSubscriptionsTable();
        $this->ensureDeliveriesTable();

        $this->tablesEnsured = true;
    }

    /**
     * Ensure webhook_subscriptions table exists
     */
    private function ensureSubscriptionsTable(): void
    {
        if ($this->schema->hasTable('webhook_subscriptions')) {
            return;
        }

        $table = $this->schema->table('webhook_subscriptions');

        // Define columns
        $table->bigInteger('id')->unsigned()->primary()->autoIncrement();
        $table->string('uuid', 12)->unique();
        $table->string('url', 2048);
        $table->json('events');
        $table->string('secret', 255);
        $table->boolean('is_active')->default(true);
        $table->json('metadata')->nullable();
        $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
        $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');

        // Add indexes
        $table->index('is_active');

        // Create the table
        $table->create();
        $this->schema->execute();
    }

    /**
     * Ensure webhook_deliveries table exists
     */
    private function ensureDeliveriesTable(): void
    {
        if ($this->schema->hasTable('webhook_deliveries')) {
            return;
        }

        $table = $this->schema->table('webhook_deliveries');

        // Define columns
        $table->bigInteger('id')->unsigned()->primary()->autoIncrement();
        $table->string('uuid', 12)->unique();
        $table->bigInteger('subscription_id')->unsigned();
        $table->string('event', 255);
        $table->json('payload');
        $table->string('status', 20)->default('pending');
        $table->integer('attempts')->unsigned()->default(0);
        $table->integer('response_code')->unsigned()->nullable();
        $table->text('response_body')->nullable();
        $table->timestamp('delivered_at')->nullable();
        $table->timestamp('next_retry_at')->nullable();
        $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');

        // Add indexes
        $table->index('subscription_id');
        $table->index('status');
        $table->index('next_retry_at');

        // Create the table
        $table->create();
        $this->schema->execute();
    }

    /**
     * Get the schema builder (for testing)
     */
    public function getSchema(): SchemaBuilderInterface
    {
        return $this->schema;
    }

    /**
     * Check if tables have been ensured
     */
    public function areTablesEnsured(): bool
    {
        return $this->tablesEnsured;
    }

    /**
     * Force table check on next dispatch
     */
    public function resetTablesEnsured(): void
    {
        $this->tablesEnsured = false;
    }
}

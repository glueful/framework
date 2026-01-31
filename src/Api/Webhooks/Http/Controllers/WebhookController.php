<?php

declare(strict_types=1);

namespace Glueful\Api\Webhooks\Http\Controllers;

use Glueful\Api\Webhooks\Contracts\WebhookDispatcherInterface;
use Glueful\Api\Webhooks\Webhook;
use Glueful\Api\Webhooks\WebhookDelivery;
use Glueful\Api\Webhooks\WebhookSubscription;
use Glueful\Controllers\BaseController;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;

/**
 * REST API Controller for webhook management
 *
 * Provides endpoints for managing webhook subscriptions and deliveries:
 *
 * - POST   /api/webhooks/subscriptions           Create subscription
 * - GET    /api/webhooks/subscriptions           List subscriptions
 * - GET    /api/webhooks/subscriptions/{id}      Get subscription
 * - PATCH  /api/webhooks/subscriptions/{id}      Update subscription
 * - DELETE /api/webhooks/subscriptions/{id}      Delete subscription
 * - POST   /api/webhooks/subscriptions/{id}/test Send test webhook
 * - GET    /api/webhooks/deliveries              List deliveries
 * - POST   /api/webhooks/deliveries/{id}/retry   Retry delivery
 */
class WebhookController extends BaseController
{
    public function __construct(
        \Glueful\Bootstrap\ApplicationContext $context,
        private ?WebhookDispatcherInterface $dispatcher = null
    ) {
        parent::__construct($context);

        if ($this->dispatcher === null && function_exists('app')) {
            if (app($this->getContext())->has(WebhookDispatcherInterface::class)) {
                $this->dispatcher = app($this->getContext(), WebhookDispatcherInterface::class);
            }
        }
    }

    /**
     * List all webhook subscriptions
     *
     * GET /api/webhooks/subscriptions
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function listSubscriptions(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = min((int) $request->query->get('per_page', 25), 100);
        $activeOnly = $request->query->get('active') === 'true';

        $query = WebhookSubscription::query($this->context);

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        // Get total count
        $total = $query->count();

        // Calculate pagination
        $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 1;
        $offset = ($page - 1) * $perPage;

        // Get paginated results
        $results = $query->offset($offset)->limit($perPage)->get();

        $subscriptions = [];
        /** @var WebhookSubscription $subscription */
        foreach ($results as $subscription) {
            $subscriptions[] = $this->formatSubscription($subscription);
        }

        return Response::success([
            'subscriptions' => $subscriptions,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
            ],
        ], 'Subscriptions retrieved successfully');
    }

    /**
     * Create a new webhook subscription
     *
     * POST /api/webhooks/subscriptions
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function createSubscription(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $data = $this->getJsonBody($request);

        // Validate required fields
        if (!isset($data['url']) || !filter_var($data['url'], FILTER_VALIDATE_URL)) {
            return Response::error('Invalid or missing URL', 400);
        }

        if (!isset($data['events']) || !is_array($data['events']) || count($data['events']) === 0) {
            return Response::error('Events must be a non-empty array', 400);
        }

        // Check HTTPS requirement
        if ($this->requiresHttps() && !str_starts_with($data['url'], 'https://')) {
            return Response::error('HTTPS is required for webhook URLs', 400);
        }

        $subscription = Webhook::subscribe(
            $data['events'],
            $data['url'],
            $data['metadata'] ?? []
        );

        return Response::success(
            $this->formatSubscription($subscription, true),
            'Subscription created successfully'
        )->setStatusCode(201);
    }

    /**
     * Get a single subscription
     *
     * GET /api/webhooks/subscriptions/{id}
     *
     * @param string $id Subscription UUID
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function getSubscription(string $id): \Symfony\Component\HttpFoundation\Response
    {
        $subscription = Webhook::findSubscription($id);

        if ($subscription === null) {
            return Response::error('Subscription not found', 404);
        }

        return Response::success(
            $this->formatSubscription($subscription),
            'Subscription retrieved successfully'
        );
    }

    /**
     * Update a subscription
     *
     * PATCH /api/webhooks/subscriptions/{id}
     *
     * @param Request $request
     * @param string $id Subscription UUID
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function updateSubscription(Request $request, string $id): \Symfony\Component\HttpFoundation\Response
    {
        $subscription = Webhook::findSubscription($id);

        if ($subscription === null) {
            return Response::error('Subscription not found', 404);
        }

        $data = $this->getJsonBody($request);
        $updates = [];

        if (isset($data['url'])) {
            if (!filter_var($data['url'], FILTER_VALIDATE_URL)) {
                return Response::error('Invalid URL', 400);
            }
            if ($this->requiresHttps() && !str_starts_with($data['url'], 'https://')) {
                return Response::error('HTTPS is required for webhook URLs', 400);
            }
            $updates['url'] = $data['url'];
        }

        if (isset($data['events'])) {
            if (!is_array($data['events']) || count($data['events']) === 0) {
                return Response::error('Events must be a non-empty array', 400);
            }
            $updates['events'] = $data['events'];
        }

        if (isset($data['is_active'])) {
            $updates['is_active'] = (bool) $data['is_active'];
        }

        if (isset($data['metadata'])) {
            $updates['metadata'] = $data['metadata'];
        }

        if (count($updates) > 0) {
            $subscription->update($updates);
        }

        return Response::success(
            $this->formatSubscription($subscription),
            'Subscription updated successfully'
        );
    }

    /**
     * Delete a subscription
     *
     * DELETE /api/webhooks/subscriptions/{id}
     *
     * @param string $id Subscription UUID
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function deleteSubscription(string $id): \Symfony\Component\HttpFoundation\Response
    {
        $subscription = Webhook::findSubscription($id);

        if ($subscription === null) {
            return Response::error('Subscription not found', 404);
        }

        $subscription->delete();

        return Response::success(null, 'Subscription deleted successfully');
    }

    /**
     * Rotate a subscription's secret
     *
     * POST /api/webhooks/subscriptions/{id}/rotate-secret
     *
     * @param string $id Subscription UUID
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function rotateSecret(string $id): \Symfony\Component\HttpFoundation\Response
    {
        $subscription = Webhook::findSubscription($id);

        if ($subscription === null) {
            return Response::error('Subscription not found', 404);
        }

        $newSecret = $subscription->rotateSecret();

        return Response::success([
            'uuid' => $subscription->uuid,
            'secret' => $newSecret,
        ], 'Secret rotated successfully');
    }

    /**
     * Send a test webhook to a subscription
     *
     * POST /api/webhooks/subscriptions/{id}/test
     *
     * @param string $id Subscription UUID
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function testSubscription(string $id): \Symfony\Component\HttpFoundation\Response
    {
        $subscription = Webhook::findSubscription($id);

        if ($subscription === null) {
            return Response::error('Subscription not found', 404);
        }

        $result = Webhook::test($subscription->url, 'webhook.test', $subscription->secret);

        if ($result['success']) {
            return Response::success([
                'status_code' => $result['status_code'] ?? null,
                'response' => $result['response'] ?? null,
            ], 'Test webhook delivered successfully');
        }

        return Response::error(
            'Test webhook failed: ' . ($result['error'] ?? 'Unknown error'),
            502,
            [
                'status_code' => $result['status_code'] ?? null,
                'response' => $result['response'] ?? null,
            ]
        );
    }

    /**
     * Get subscription statistics
     *
     * GET /api/webhooks/subscriptions/{id}/stats
     *
     * @param Request $request
     * @param string $id Subscription UUID
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function getSubscriptionStats(Request $request, string $id): \Symfony\Component\HttpFoundation\Response
    {
        $subscription = Webhook::findSubscription($id);

        if ($subscription === null) {
            return Response::error('Subscription not found', 404);
        }

        $days = (int) $request->query->get('days', 30);
        $stats = $subscription->stats($days);
        $successRate = $subscription->successRate($days);

        return Response::success([
            'uuid' => $subscription->uuid,
            'period_days' => $days,
            'total_deliveries' => $stats['total'],
            'delivered' => $stats['delivered'],
            'failed' => $stats['failed'],
            'pending' => $stats['pending'],
            'success_rate' => $successRate,
        ], 'Statistics retrieved successfully');
    }

    /**
     * List webhook deliveries
     *
     * GET /api/webhooks/deliveries
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function listDeliveries(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = min((int) $request->query->get('per_page', 25), 100);
        $status = $request->query->get('status');
        $subscriptionId = $request->query->get('subscription');

        $query = WebhookDelivery::query($this->context);

        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        if ($subscriptionId !== null && $subscriptionId !== '') {
            $subscription = Webhook::findSubscription($subscriptionId);
            if ($subscription !== null) {
                $query->where('subscription_id', $subscription->id);
            }
        }

        $query->orderBy('created_at', 'desc');

        // Get total count
        $total = $query->count();

        // Calculate pagination
        $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 1;
        $offset = ($page - 1) * $perPage;

        // Get paginated results
        $results = $query->offset($offset)->limit($perPage)->get();

        $deliveries = [];
        /** @var WebhookDelivery $delivery */
        foreach ($results as $delivery) {
            $deliveries[] = $this->formatDelivery($delivery);
        }

        return Response::success([
            'deliveries' => $deliveries,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
            ],
        ], 'Deliveries retrieved successfully');
    }

    /**
     * Get a single delivery
     *
     * GET /api/webhooks/deliveries/{id}
     *
     * @param string $id Delivery UUID
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function getDelivery(string $id): \Symfony\Component\HttpFoundation\Response
    {
        $delivery = Webhook::findDelivery($id);

        if ($delivery === null) {
            return Response::error('Delivery not found', 404);
        }

        return Response::success(
            $this->formatDelivery($delivery, true),
            'Delivery retrieved successfully'
        );
    }

    /**
     * Retry a failed delivery
     *
     * POST /api/webhooks/deliveries/{id}/retry
     *
     * @param string $id Delivery UUID
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function retryDelivery(string $id): \Symfony\Component\HttpFoundation\Response
    {
        $delivery = Webhook::findDelivery($id);

        if ($delivery === null) {
            return Response::error('Delivery not found', 404);
        }

        if (!$delivery->isFailed() && !$delivery->isRetrying()) {
            return Response::error('Only failed or retrying deliveries can be retried', 400);
        }

        $success = Webhook::retry($id);

        if ($success) {
            return Response::success([
                'uuid' => $delivery->uuid,
                'status' => 'pending',
            ], 'Delivery queued for retry');
        }

        return Response::error('Failed to queue retry', 500);
    }

    /**
     * Format a subscription for API response
     *
     * @param WebhookSubscription $subscription
     * @param bool $includeSecret Whether to include the secret (only on create)
     * @return array<string, mixed>
     */
    private function formatSubscription(WebhookSubscription $subscription, bool $includeSecret = false): array
    {
        $data = [
            'uuid' => $subscription->uuid,
            'url' => $subscription->url,
            'events' => $subscription->events,
            'is_active' => $subscription->is_active,
            'metadata' => $subscription->metadata,
            'created_at' => $subscription->created_at,
            'updated_at' => $subscription->updated_at,
        ];

        if ($includeSecret) {
            $data['secret'] = $subscription->secret;
        }

        return $data;
    }

    /**
     * Format a delivery for API response
     *
     * @param WebhookDelivery $delivery
     * @param bool $includePayload Whether to include the full payload
     * @return array<string, mixed>
     */
    private function formatDelivery(WebhookDelivery $delivery, bool $includePayload = false): array
    {
        $data = [
            'uuid' => $delivery->uuid,
            'event' => $delivery->event,
            'status' => $delivery->status,
            'attempts' => $delivery->attempts,
            'response_code' => $delivery->response_code,
            'delivered_at' => $delivery->delivered_at,
            'next_retry_at' => $delivery->next_retry_at,
            'created_at' => $delivery->created_at,
        ];

        if ($includePayload) {
            $data['payload'] = $delivery->payload;
            $data['response_body'] = $delivery->response_body;
        }

        return $data;
    }

    /**
     * Get JSON body from request
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    private function getJsonBody(Request $request): array
    {
        $content = $request->getContent();
        if ($content === '' || $content === false) {
            return [];
        }

        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Check if HTTPS is required for webhook URLs
     *
     * @return bool
     */
    private function requiresHttps(): bool
    {
        if (function_exists('config')) {
            return (bool) config($this->getContext(), 'api.webhooks.require_https', true);
        }

        return true;
    }
}

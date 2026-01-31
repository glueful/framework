<?php

declare(strict_types=1);

namespace Glueful\Api\Webhooks\Listeners;

use Glueful\Api\Webhooks\Attributes\Webhookable;
use Glueful\Api\Webhooks\Contracts\WebhookDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event listener that bridges application events to webhooks
 *
 * This listener intercepts application events that are marked with
 * the #[Webhookable] attribute or use the DispatchesWebhooks trait,
 * and automatically dispatches them as webhooks to all matching
 * subscriptions.
 *
 * @example
 * ```php
 * // Register in a service provider
 * $dispatcher = $container->get(EventDispatcherInterface::class);
 * $dispatcher->addSubscriber(new WebhookEventListener($webhookDispatcher));
 *
 * // Now any event with #[Webhookable] or DispatchesWebhooks will trigger webhooks
 * app($context, \Glueful\Events\EventService::class)->dispatch(new UserCreatedEvent($userData));
 * ```
 */
class WebhookEventListener implements EventSubscriberInterface
{
    public function __construct(
        private WebhookDispatcherInterface $dispatcher
    ) {
    }

    /**
     * Get the events this listener subscribes to
     *
     * Returns an empty array since we use a different mechanism
     * to intercept events (via the kernel event).
     *
     * @return array<string, array<int, array{0: string, 1?: int}>>
     */
    public static function getSubscribedEvents(): array
    {
        return [];
    }

    /**
     * Handle an event and dispatch webhook if applicable
     *
     * This method should be called for any event that might
     * need to trigger webhooks.
     *
     * @param object $event The event to process
     * @param string|null $eventName Optional event name override
     * @return void
     */
    public function handleEvent(object $event, ?string $eventName = null): void
    {
        // Check if the event should dispatch webhooks
        if (!$this->shouldDispatchWebhook($event)) {
            return;
        }

        // Get the webhook event name
        $webhookEventName = $this->getWebhookEventName($event, $eventName);

        // Get the webhook payload
        $payload = $this->getWebhookPayload($event);

        // Get any options from the event
        $options = $this->getWebhookOptions($event);

        // Dispatch the webhook
        $this->dispatcher->dispatch($webhookEventName, $payload, $options);
    }

    /**
     * Check if an event should dispatch webhooks
     *
     * An event dispatches webhooks if it:
     * 1. Uses the DispatchesWebhooks trait and shouldDispatchWebhook() returns true
     * 2. Has the #[Webhookable] attribute and its condition passes
     *
     * @param object $event The event to check
     * @return bool Whether to dispatch webhooks
     */
    private function shouldDispatchWebhook(object $event): bool
    {
        // Check for DispatchesWebhooks trait
        if ($this->hasDispatchesTrait($event)) {
            if (method_exists($event, 'shouldDispatchWebhook')) {
                return (bool) $event->shouldDispatchWebhook();
            }
            return true;
        }

        // Check for Webhookable attribute
        $attribute = Webhookable::fromClass($event);
        if ($attribute !== null) {
            return $attribute->shouldDispatch($event);
        }

        return false;
    }

    /**
     * Get the webhook event name from an event
     *
     * @param object $event The event instance
     * @param string|null $eventName Optional fallback event name
     * @return string The webhook event name
     */
    private function getWebhookEventName(object $event, ?string $eventName = null): string
    {
        // Check for DispatchesWebhooks trait
        if ($this->hasDispatchesTrait($event) && method_exists($event, 'webhookEventName')) {
            return (string) $event->webhookEventName();
        }

        // Check for Webhookable attribute
        $attribute = Webhookable::fromClass($event);
        if ($attribute !== null) {
            return $attribute->getEventName($event::class);
        }

        // Use the provided event name or derive from class
        if ($eventName !== null) {
            return $eventName;
        }

        return $this->deriveEventName($event::class);
    }

    /**
     * Get the webhook payload from an event
     *
     * @param object $event The event instance
     * @return array<string, mixed> The webhook payload
     */
    private function getWebhookPayload(object $event): array
    {
        // Check for DispatchesWebhooks trait
        if ($this->hasDispatchesTrait($event) && method_exists($event, 'webhookPayload')) {
            $result = $event->webhookPayload();
            return is_array($result) ? $result : [];
        }

        // Default: extract public properties
        return $this->extractPayload($event);
    }

    /**
     * Get webhook options from an event
     *
     * @param object $event The event instance
     * @return array<string, mixed> Webhook options
     */
    private function getWebhookOptions(object $event): array
    {
        // Check for DispatchesWebhooks trait
        if ($this->hasDispatchesTrait($event) && method_exists($event, 'webhookOptions')) {
            $result = $event->webhookOptions();
            return is_array($result) ? $result : [];
        }

        return [];
    }

    /**
     * Check if an event uses the DispatchesWebhooks trait
     *
     * @param object $event The event to check
     * @return bool Whether the event has the trait
     */
    private function hasDispatchesTrait(object $event): bool
    {
        return method_exists($event, 'dispatchesWebhooks')
            && $event->dispatchesWebhooks() === true;
    }

    /**
     * Derive an event name from a class name
     *
     * @param string $className The class name
     * @return string The derived event name
     */
    private function deriveEventName(string $className): string
    {
        // Get short class name
        $shortName = (new \ReflectionClass($className))->getShortName();

        // Remove "Event" suffix if present
        $shortName = preg_replace('/Event$/', '', $shortName);

        // Convert CamelCase to dot.notation
        $result = preg_replace('/([a-z])([A-Z])/', '$1.$2', (string) $shortName);

        return strtolower((string) $result);
    }

    /**
     * Extract payload from an event's public properties
     *
     * @param object $event The event instance
     * @return array<string, mixed> The extracted payload
     */
    private function extractPayload(object $event): array
    {
        $payload = [];
        $reflection = new \ReflectionClass($event);

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            // Skip static properties
            if ($property->isStatic()) {
                continue;
            }

            $name = $property->getName();

            // Skip common event base class properties
            if (in_array($name, ['propagationStopped', 'eventId', 'timestamp', 'metadata'], true)) {
                continue;
            }

            $value = $property->getValue($event);

            // Convert objects to arrays if they have a toArray method
            if (is_object($value) && method_exists($value, 'toArray')) {
                $value = $value->toArray();
            } elseif (is_object($value) && $value instanceof \JsonSerializable) {
                $value = $value->jsonSerialize();
            }

            $payload[$name] = $value;
        }

        return $payload;
    }

    /**
     * Create a listener callback for use with Symfony EventDispatcher
     *
     * This creates a callback that can be registered with an event dispatcher
     * to automatically process events.
     *
     * @return callable The listener callback
     */
    public function createCallback(): callable
    {
        return function (object $event, string $eventName): void {
            $this->handleEvent($event, $eventName);
        };
    }
}

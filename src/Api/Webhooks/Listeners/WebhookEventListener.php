<?php

declare(strict_types=1);

namespace Glueful\Api\Webhooks\Listeners;

use Glueful\Api\Webhooks\Attributes\Webhookable;
use Glueful\Api\Webhooks\Contracts\WebhookDispatcherInterface;
use Glueful\Events\Contracts\BaseEvent;
use Glueful\Events\EventService;
use Psr\Container\ContainerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event listener that bridges application events to webhooks
 *
 * This listener intercepts application events that are marked with
 * the #[Webhookable] attribute or use the DispatchesWebhooks trait,
 * and automatically dispatches them as webhooks to all matching
 * subscriptions.
 *
 * The framework registers it at boot ({@see register()}) when both `events.enabled` and
 * `api.webhooks.enabled` are on, so any event with #[Webhookable] or DispatchesWebhooks
 * dispatched through EventService triggers webhooks:
 *
 * @example
 * ```php
 * app($context, \Glueful\Events\EventService::class)->dispatch(new UserCreatedEvent($userData));
 * ```
 */
class WebhookEventListener implements EventSubscriberInterface
{
    /**
     * Priority of the bridge listener: below the default (0) so application listeners for the
     * same event run first, and a listener that stops propagation also stops the webhook.
     */
    public const PRIORITY = -100;

    /** @var array<class-string, bool> Per-class cache of {@see isMarked()} */
    private static array $markedClasses = [];

    public function __construct(
        private WebhookDispatcherInterface $dispatcher
    ) {
    }

    /**
     * Bridge every BaseEvent dispatched through EventService to webhooks. The callback is cheap
     * for unmarked events (a cached per-class check); the listener — and with it the webhook
     * dispatcher and its database connection — is only resolved for a marked event.
     */
    public static function register(EventService $events, ContainerInterface $container): void
    {
        $events->addListener(
            BaseEvent::class,
            static function (object $event) use ($container): void {
                if (!self::isMarked($event)) {
                    return;
                }
                /** @var self $listener */
                $listener = $container->get(self::class);
                $listener->handleEvent($event);
            },
            self::PRIORITY
        );
    }

    /**
     * Whether the event's class uses DispatchesWebhooks or carries #[Webhookable]. Per-instance
     * conditions (shouldDispatchWebhook(), the attribute's condition) are checked later by
     * {@see handleEvent()}.
     */
    public static function isMarked(object $event): bool
    {
        $class = $event::class;

        return self::$markedClasses[$class] ??= method_exists($event, 'dispatchesWebhooks')
            || Webhookable::has($class);
    }

    /**
     * Get the events this listener subscribes to
     *
     * Returns an empty array since we use a different mechanism
     * to intercept events (via the kernel event).
     *
     * @return array<string, list<array{0: string, 1?: int}|int|string>|string>
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
        // Get short class name (fall back to manual parsing for unloadable names)
        if (class_exists($className)) {
            $shortName = (new \ReflectionClass($className))->getShortName();
        } else {
            $separatorPos = strrpos($className, '\\');
            $shortName = $separatorPos === false ? $className : substr($className, $separatorPos + 1);
        }

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

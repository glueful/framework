<?php

declare(strict_types=1);

namespace Glueful\Api\Webhooks\Concerns;

/**
 * Trait for making events automatically dispatch webhooks
 *
 * Use this trait in your event classes to enable automatic webhook
 * dispatching when the event is fired. The trait provides hooks for
 * customizing the webhook event name, payload, and dispatch conditions.
 *
 * @example
 * ```php
 * class UserCreatedEvent extends BaseEvent
 * {
 *     use DispatchesWebhooks;
 *
 *     public function __construct(
 *         public readonly array $user
 *     ) {
 *         parent::__construct();
 *     }
 *
 *     public function webhookPayload(): array
 *     {
 *         return ['user' => $this->user];
 *     }
 * }
 * ```
 */
trait DispatchesWebhooks
{
    /**
     * Get the webhook event name
     *
     * By default, converts the class name to a dot-notation event name.
     * For example: UserCreatedEvent -> user.created
     *
     * Override this method to customize the event name.
     *
     * @return string The webhook event name
     */
    public function webhookEventName(): string
    {
        $class = (new \ReflectionClass($this))->getShortName();

        // Remove "Event" suffix if present
        $class = preg_replace('/Event$/', '', $class);

        // Convert CamelCase to dot.notation
        // UserCreated -> user.created
        $result = preg_replace('/([a-z])([A-Z])/', '$1.$2', (string) $class);

        return strtolower((string) $result);
    }

    /**
     * Get the webhook payload data
     *
     * By default, returns all public properties of the event.
     * Override this method to customize the payload.
     *
     * @return array<string, mixed> The webhook payload
     */
    public function webhookPayload(): array
    {
        $payload = [];
        $reflection = new \ReflectionClass($this);

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            // Skip static properties
            if ($property->isStatic()) {
                continue;
            }

            // Skip properties from parent classes (BaseEvent properties)
            if ($property->getDeclaringClass()->getName() !== static::class) {
                continue;
            }

            $name = $property->getName();
            $value = $property->getValue($this);

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
     * Determine if this event should dispatch a webhook
     *
     * By default, always returns true. Override to add conditions.
     *
     * @return bool Whether to dispatch a webhook
     */
    public function shouldDispatchWebhook(): bool
    {
        return true;
    }

    /**
     * Get additional options for the webhook dispatch
     *
     * Override to provide custom options like delay, queue, etc.
     *
     * @return array<string, mixed> Additional dispatch options
     */
    public function webhookOptions(): array
    {
        return [];
    }

    /**
     * Check if this event has the DispatchesWebhooks trait
     *
     * Utility method for checking if an event can dispatch webhooks.
     *
     * @return bool Always true for objects using this trait
     */
    public function dispatchesWebhooks(): bool
    {
        return true;
    }
}

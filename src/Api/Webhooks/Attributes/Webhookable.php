<?php

declare(strict_types=1);

namespace Glueful\Api\Webhooks\Attributes;

use Attribute;

/**
 * Attribute to mark an event class as webhookable
 *
 * Apply this attribute to event classes to automatically dispatch
 * webhooks when the event is fired. Can be combined with the
 * DispatchesWebhooks trait for full customization.
 *
 * @example
 * ```php
 * #[Webhookable]
 * class UserCreatedEvent extends BaseEvent
 * {
 *     // Event will automatically dispatch webhook named "user.created"
 * }
 *
 * #[Webhookable(event: 'customer.signup', condition: 'shouldNotify')]
 * class CustomerRegisteredEvent extends BaseEvent
 * {
 *     public function shouldNotify(): bool
 *     {
 *         return $this->customer['email_verified'] ?? false;
 *     }
 * }
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Webhookable
{
    /**
     * @param string|null $event Custom event name (default: derived from class name)
     * @param string|null $condition Method name to check before dispatching
     * @param bool $enabled Whether webhooks are enabled for this event
     * @param array<string> $only Only dispatch to subscriptions matching these patterns
     * @param array<string> $except Exclude subscriptions matching these patterns
     */
    public function __construct(
        public readonly ?string $event = null,
        public readonly ?string $condition = null,
        public readonly bool $enabled = true,
        public readonly array $only = [],
        public readonly array $except = []
    ) {
    }

    /**
     * Get the webhook event name for a class
     *
     * Uses the custom event name if specified, otherwise derives
     * the name from the class name.
     *
     * @param string $className The fully qualified class name
     * @return string The webhook event name
     */
    public function getEventName(string $className): string
    {
        if ($this->event !== null) {
            return $this->event;
        }

        // Get short class name
        $shortName = (new \ReflectionClass($className))->getShortName();

        // Remove "Event" suffix if present
        $shortName = preg_replace('/Event$/', '', $shortName);

        // Convert CamelCase to dot.notation
        $result = preg_replace('/([a-z])([A-Z])/', '$1.$2', (string) $shortName);

        return strtolower((string) $result);
    }

    /**
     * Check if webhooks should be dispatched for this event
     *
     * @param object $eventInstance The event instance
     * @return bool Whether to dispatch webhooks
     */
    public function shouldDispatch(object $eventInstance): bool
    {
        if (!$this->enabled) {
            return false;
        }

        if ($this->condition === null) {
            return true;
        }

        // Check if the condition method exists and returns true
        if (method_exists($eventInstance, $this->condition)) {
            $method = $this->condition;
            /** @var callable $callback */
            $callback = [$eventInstance, $method];
            return (bool) call_user_func($callback);
        }

        return true;
    }

    /**
     * Get the webhook attribute from a class if it exists
     *
     * @param string|object $class The class name or instance
     * @return self|null The Webhookable attribute or null
     */
    public static function fromClass(string|object $class): ?self
    {
        $className = is_object($class) ? $class::class : $class;

        try {
            $reflection = new \ReflectionClass($className);
            $attributes = $reflection->getAttributes(self::class);

            if (count($attributes) > 0) {
                return $attributes[0]->newInstance();
            }
        } catch (\ReflectionException) {
            // Class doesn't exist or reflection failed
        }

        return null;
    }

    /**
     * Check if a class has the Webhookable attribute
     *
     * @param string|object $class The class name or instance
     * @return bool Whether the class has the Webhookable attribute
     */
    public static function has(string|object $class): bool
    {
        return self::fromClass($class) !== null;
    }
}

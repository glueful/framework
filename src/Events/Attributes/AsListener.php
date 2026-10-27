<?php

declare(strict_types=1);

namespace Glueful\Events\Attributes;

/**
 * Declares an event listener; registered by EventService::subscribe(Listener::class).
 *
 * On a method, that method handles the event. On the class, `method` names the handler
 * (default `__invoke`). Higher priority runs first. A class may combine this with
 * EventSubscriberInterface; both sets register. There is no scanning — subscribe the class.
 *
 * @example
 * ```php
 * final class OrderListeners
 * {
 *     #[AsListener(OrderPlaced::class, priority: 10)]
 *     public function reserveStock(OrderPlaced $event): void { }
 * }
 *
 * $events->subscribe(OrderListeners::class);
 * ```
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class AsListener
{
    public function __construct(
        public string $event,
        public int $priority = 0,
        public ?string $method = null, // when placed on class, which method to call
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Glueful\Events;

interface EventSubscriberInterface
{
    /**
     * Return a map of eventClass => method or [method, priority].
     *
     * @return array<string, string|array{0:string,1?:int}>
     */
    public static function getSubscribedEvents(): array;
}

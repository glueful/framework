<?php

declare(strict_types=1);

namespace Glueful\Events;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Holds ApplicationContext for queue-related event operations
 *
 * This replaces the static trait property pattern which is deprecated in PHP 8.3+.
 */
final class QueueContextHolder
{
    private static ?ApplicationContext $context = null;

    public static function setContext(?ApplicationContext $context): void
    {
        self::$context = $context;
    }

    public static function getContext(): ?ApplicationContext
    {
        return self::$context;
    }

    public static function reset(): void
    {
        self::$context = null;
    }
}

<?php

declare(strict_types=1);

namespace Glueful\Notifications\Exceptions;

use RuntimeException;

/**
 * Thrown when a notification channel is registered under a name that is already taken by a
 * *different* channel class — a real package conflict (e.g. two packages both claiming `email`).
 *
 * Re-registering the *same* class under the same name is a no-op (idempotent across repeated
 * boots); intentional overrides use {@see \Glueful\Notifications\Services\ChannelManager::replaceChannel()}.
 *
 * @package Glueful\Notifications\Exceptions
 */
final class ChannelAlreadyRegisteredException extends RuntimeException
{
    public static function forName(string $name, string $existingClass, string $incomingClass): self
    {
        return new self(sprintf(
            "A different channel is already registered as '%s' (%s); cannot register %s. "
            . "Use replaceChannel() to override intentionally.",
            $name,
            $existingClass,
            $incomingClass
        ));
    }
}

<?php

declare(strict_types=1);

namespace Glueful\Events;

use Symfony\Contracts\EventDispatcher\Event as SymfonyEvent;

/**
 * Base Event Class for Glueful Framework
 *
 * All events in Glueful should extend this class.
 * Provides abstraction from the underlying event dispatcher implementation.
 *
 * @package Glueful\Events
 */
abstract class BaseEvent extends SymfonyEvent
{
    /** @var array<string, mixed> Event metadata for framework features */
    private array $metadata = [];

    /** @var float Event creation timestamp */
    private float $timestamp;

    /** @var string|null Event ID for tracking */
    private ?string $eventId = null;

    public function __construct()
    {
        $this->timestamp = microtime(true);
        $this->eventId = uniqid('evt_', true);
    }

    /**
     * Stop event propagation
     */
    public function stopPropagation(): void
    {
        parent::stopPropagation();
    }

    /**
     * Check if propagation is stopped
     */
    public function isPropagationStopped(): bool
    {
        return parent::isPropagationStopped();
    }

    /**
     * Set event metadata
     */
    public function setMetadata(string $key, mixed $value): void
    {
        $this->metadata[$key] = $value;
    }

    /**
     * Get event metadata
     */
    public function getMetadata(?string $key = null): mixed
    {
        return $key !== null ? ($this->metadata[$key] ?? null) : $this->metadata;
    }

    /**
     * Get event timestamp
     */
    public function getTimestamp(): float
    {
        return $this->timestamp;
    }

    /**
     * Get event ID
     */
    public function getEventId(): string
    {
        return $this->eventId;
    }

    /**
     * Get event name (class name by default)
     */
    public function getName(): string
    {
        return static::class;
    }
}

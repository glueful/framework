<?php

declare(strict_types=1);

namespace Glueful\Events\Contracts;

use Psr\EventDispatcher\StoppableEventInterface;

/**
 * BaseEvent with lightweight propagation control and framework metadata.
 *
 * This preserves the useful metadata/time/id helpers from the legacy system
 * while adopting PSR-14's StoppableEventInterface.
 */
abstract class BaseEvent implements StoppableEventInterface
{
    private bool $stopped = false;

    /** @var array<string, mixed> */
    private array $metadata = [];

    private float $timestamp;
    private string $eventId;

    public function __construct()
    {
        $this->timestamp = microtime(true);
        $this->eventId = uniqid('evt_', true);
    }

    public function stopPropagation(): void
    {
        $this->stopped = true;
    }

    public function isPropagationStopped(): bool
    {
        return $this->stopped;
    }

    // ---- Framework helpers ----

    public function setMetadata(string $key, mixed $value): void
    {
        $this->metadata[$key] = $value;
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $key === null ? $this->metadata : ($this->metadata[$key] ?? null);
    }

    public function getTimestamp(): float
    {
        return $this->timestamp;
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getName(): string
    {
        return static::class;
    }
}

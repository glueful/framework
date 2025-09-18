<?php

declare(strict_types=1);

namespace Glueful\Events\Traits;

/**
 * Timestampable Trait
 *
 * Adds timestamp functionality to events.
 * Provides creation time and elapsed time tracking.
 */
trait Timestampable
{
    private ?\DateTimeImmutable $createdAt = null;

    public function getTimestamp(): \DateTimeImmutable
    {
        if ($this->createdAt === null) {
            $this->createdAt = new \DateTimeImmutable();
        }
        return $this->createdAt;
    }

    public function getUnixTimestamp(): int
    {
        return $this->getTimestamp()->getTimestamp();
    }

    public function getElapsedTime(): float
    {
        $now = new \DateTimeImmutable();
        $interval = $now->getTimestamp() - $this->getTimestamp()->getTimestamp();
        return (float) $interval;
    }

    public function isOlderThan(int $seconds): bool
    {
        return $this->getElapsedTime() > $seconds;
    }

    public function getFormattedTimestamp(string $format = 'Y-m-d H:i:s'): string
    {
        return $this->getTimestamp()->format($format);
    }
}

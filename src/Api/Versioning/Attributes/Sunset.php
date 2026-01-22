<?php

declare(strict_types=1);

namespace Glueful\Api\Versioning\Attributes;

use Attribute;

/**
 * Define sunset date for a deprecated endpoint (RFC 8594)
 *
 * The Sunset HTTP Header Field indicates that a resource is expected
 * to become unresponsive at a specific point in time.
 *
 * Usage:
 *   #[Sunset('2025-06-01')]
 *   #[Sunset(date: '2025-06-01', link: 'https://docs.example.com/migration')]
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Sunset
{
    public readonly \DateTimeImmutable $date;

    /**
     * @param string $date Sunset date in any format parseable by DateTimeImmutable (ISO 8601 recommended)
     * @param string|null $link URL to documentation about the sunset
     */
    public function __construct(
        string $date,
        public readonly ?string $link = null
    ) {
        $this->date = new \DateTimeImmutable($date);
    }

    /**
     * Format date for HTTP Sunset header (RFC 7231)
     */
    public function toHttpDate(): string
    {
        return $this->date->format(\DateTimeInterface::RFC7231);
    }

    /**
     * Check if sunset date has passed
     */
    public function hasPassed(): bool
    {
        return $this->date < new \DateTimeImmutable();
    }

    /**
     * Get days until sunset (negative if passed)
     */
    public function getDaysRemaining(): int
    {
        $now = new \DateTimeImmutable();
        $diff = $now->diff($this->date);

        $days = (int) $diff->days;
        return $diff->invert === 1 ? -$days : $days;
    }

    /**
     * Get human-readable time remaining
     */
    public function getTimeRemaining(): string
    {
        $days = $this->getDaysRemaining();

        if ($days < 0) {
            return 'Already sunset';
        }

        if ($days === 0) {
            return 'Today';
        }

        if ($days === 1) {
            return '1 day';
        }

        if ($days < 30) {
            return "{$days} days";
        }

        $months = (int) round($days / 30);
        return $months === 1 ? '1 month' : "{$months} months";
    }

    /**
     * Check if documentation link is specified
     */
    public function hasLink(): bool
    {
        return $this->link !== null;
    }
}

<?php

declare(strict_types=1);

namespace Glueful\Api\Versioning;

/**
 * Immutable value object representing an API version
 *
 * Supports multiple version formats:
 * - Major only: "1", "v1"
 * - Major.minor: "1.0", "v1.0"
 * - Semantic: "1.0.0", "v1.0.0"
 */
final class ApiVersion implements \Stringable
{
    public const DEFAULT_VERSION = '1';

    private function __construct(
        public readonly string $major,
        public readonly ?string $minor = null,
        public readonly ?string $patch = null
    ) {
    }

    /**
     * Create from string (e.g., "1", "1.0", "1.0.0", "v1")
     */
    public static function fromString(string $version): self
    {
        $version = ltrim($version, 'vV');
        $parts = explode('.', $version);

        return new self(
            $parts[0] ?? self::DEFAULT_VERSION,
            $parts[1] ?? null,
            $parts[2] ?? null
        );
    }

    /**
     * Create default version
     */
    public static function default(): self
    {
        return new self(self::DEFAULT_VERSION);
    }

    /**
     * Get version as string without prefix
     */
    public function toString(): string
    {
        $version = $this->major;
        if ($this->minor !== null) {
            $version .= '.' . $this->minor;
        }
        if ($this->patch !== null) {
            $version .= '.' . $this->patch;
        }
        return $version;
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * Get version as URL prefix (e.g., "v1")
     */
    public function toPrefix(): string
    {
        return 'v' . $this->major;
    }

    /**
     * Check exact equality with another version
     */
    public function equals(self $other): bool
    {
        return $this->major === $other->major
            && $this->minor === $other->minor
            && $this->patch === $other->patch;
    }

    /**
     * Check major version compatibility (same major = compatible)
     */
    public function isCompatibleWith(self $other): bool
    {
        return $this->major === $other->major;
    }

    /**
     * Compare to another version
     *
     * @return int Negative if this < other, 0 if equal, positive if this > other
     */
    public function compareTo(self $other): int
    {
        $majorDiff = (int) $this->major - (int) $other->major;
        if ($majorDiff !== 0) {
            return $majorDiff;
        }

        $minorDiff = (int) ($this->minor ?? 0) - (int) ($other->minor ?? 0);
        if ($minorDiff !== 0) {
            return $minorDiff;
        }

        return (int) ($this->patch ?? 0) - (int) ($other->patch ?? 0);
    }

    /**
     * Check if this version is greater than another
     */
    public function isGreaterThan(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    /**
     * Check if this version is less than another
     */
    public function isLessThan(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    /**
     * Check if this version is within a range (inclusive)
     */
    public function isWithinRange(?self $min, ?self $max): bool
    {
        if ($min !== null && $this->compareTo($min) < 0) {
            return false;
        }

        if ($max !== null && $this->compareTo($max) > 0) {
            return false;
        }

        return true;
    }
}

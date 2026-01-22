<?php

declare(strict_types=1);

namespace Glueful\Api\Versioning\Contracts;

/**
 * Contract for resources that can be deprecated
 *
 * Implements RFC 8594 (Sunset HTTP Header) semantics for indicating
 * when a resource will become unavailable.
 */
interface DeprecatableInterface
{
    /**
     * Check if the resource is deprecated
     *
     * @return bool True if deprecated
     */
    public function isDeprecated(): bool;

    /**
     * Get deprecation message
     *
     * @return string|null Human-readable deprecation notice
     */
    public function getDeprecationMessage(): ?string;

    /**
     * Get sunset date (RFC 8594)
     *
     * The date and time after which the resource will become unavailable.
     *
     * @return \DateTimeImmutable|null The sunset date or null if not set
     */
    public function getSunsetDate(): ?\DateTimeImmutable;

    /**
     * Get alternative/replacement resource URL
     *
     * Used to populate the Link header with rel="successor-version"
     *
     * @return string|null URL of the replacement resource
     */
    public function getAlternativeUrl(): ?string;
}

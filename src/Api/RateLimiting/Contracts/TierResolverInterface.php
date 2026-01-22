<?php

declare(strict_types=1);

namespace Glueful\Api\RateLimiting\Contracts;

use Symfony\Component\HttpFoundation\Request;

/**
 * Interface for resolving user rate limit tiers
 *
 * Implementations determine which tier a user belongs to
 * based on their authentication status, subscription, or roles.
 */
interface TierResolverInterface
{
    /**
     * Resolve the rate limit tier for the current request
     *
     * @param Request $request The HTTP request
     * @return string Tier name (e.g., 'anonymous', 'free', 'pro', 'enterprise')
     */
    public function resolve(Request $request): string;
}

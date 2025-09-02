<?php

declare(strict_types=1);

namespace Glueful\Serialization\Cache;

/**
 * Cache Key Strategy Interface
 *
 * Allows for custom cache key generation strategies
 */
interface CacheKeyStrategyInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function generateKey(object $object, array $context): string;
}

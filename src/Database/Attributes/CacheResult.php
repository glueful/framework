<?php

namespace Glueful\Database\Attributes;

use Attribute;

/**
 * Indicates that a repository method should cache results
 */

#[Attribute(Attribute::TARGET_METHOD)]
class CacheResult
{
    /**
     * @param int $ttl
     * @param string $keyPrefix
     * @param array<int, string> $tags
     */
    public function __construct(
        public int $ttl = 3600,
        public string $keyPrefix = '',
        public array $tags = []
    ) {
    }
}

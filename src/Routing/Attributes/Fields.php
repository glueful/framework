<?php

declare(strict_types=1);

namespace Glueful\Routing\Attributes;

use Attribute;

/**
 * Attach per-route field-selection options & whitelist.
 * Supports advanced patterns:
 *   #[Fields(
 *     strict: true,
 *     allowed: ['id', 'name', 'posts.*', 'email:if(owner)', '*,-password,-secret_key'],
 *     maxDepth: 6
 *   )]
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Fields
{
    /** @param string[]|null $allowed Advanced patterns: wildcards, conditionals, exclusions */
    public function __construct(
        public readonly ?bool $strict = null,
        public readonly ?array $allowed = null,
        public readonly ?string $whitelistKey = null,
        public readonly ?int $maxDepth = null,
        public readonly ?int $maxFields = null,
        public readonly ?int $maxItems = null,
        public readonly ?bool $useAdvancedPatterns = null,
    ) {
    }
}

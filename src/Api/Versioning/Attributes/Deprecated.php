<?php

declare(strict_types=1);

namespace Glueful\Api\Versioning\Attributes;

use Attribute;

/**
 * Mark a controller or method as deprecated
 *
 * Usage:
 *   #[Deprecated]
 *   #[Deprecated(message: 'Use /v2/users instead')]
 *   #[Deprecated(since: '1.5', alternative: '/v2/users')]
 *   #[Deprecated(since: '1.5', alternative: '/v2/users', link: 'https://docs.example.com/migration')]
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Deprecated
{
    /**
     * @param string|null $message Custom deprecation message
     * @param string|null $since Version when deprecation started
     * @param string|null $alternative URL of the replacement endpoint
     * @param string|null $link URL to migration documentation
     */
    public function __construct(
        public readonly ?string $message = null,
        public readonly ?string $since = null,
        public readonly ?string $alternative = null,
        public readonly ?string $link = null
    ) {
    }

    /**
     * Get full deprecation message
     */
    public function getFullMessage(): string
    {
        $parts = [];

        if ($this->message !== null) {
            $parts[] = $this->message;
        } else {
            $parts[] = 'This endpoint is deprecated';
        }

        if ($this->since !== null) {
            $parts[] = "since version {$this->since}";
        }

        if ($this->alternative !== null) {
            $parts[] = "Use {$this->alternative} instead";
        }

        if ($this->link !== null) {
            $parts[] = "See {$this->link} for more information";
        }

        return implode('. ', $parts) . '.';
    }

    /**
     * Check if an alternative endpoint is specified
     */
    public function hasAlternative(): bool
    {
        return $this->alternative !== null;
    }

    /**
     * Check if documentation link is specified
     */
    public function hasLink(): bool
    {
        return $this->link !== null;
    }
}

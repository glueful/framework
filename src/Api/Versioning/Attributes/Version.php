<?php

declare(strict_types=1);

namespace Glueful\Api\Versioning\Attributes;

use Attribute;
use Glueful\Api\Versioning\ApiVersion;

/**
 * Define API version constraints for a controller or method
 *
 * Usage:
 *   #[Version('2')]                    - Only version 2
 *   #[Version(['1', '2'])]             - Versions 1 and 2
 *   #[Version(min: '1', max: '3')]     - Versions 1 through 3
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Version
{
    /** @var array<string> */
    public readonly array $versions;

    /**
     * @param string|array<string>|null $version Single version or array of versions
     * @param string|null $min Minimum supported version (inclusive)
     * @param string|null $max Maximum supported version (inclusive)
     */
    public function __construct(
        string|array|null $version = null,
        public readonly ?string $min = null,
        public readonly ?string $max = null
    ) {
        if ($version !== null) {
            $this->versions = is_array($version) ? $version : [$version];
        } else {
            $this->versions = [];
        }
    }

    /**
     * Check if a version matches this constraint
     */
    public function matches(ApiVersion $version): bool
    {
        // Explicit version list check
        if (count($this->versions) > 0) {
            foreach ($this->versions as $v) {
                $constraint = ApiVersion::fromString($v);
                if ($version->isCompatibleWith($constraint)) {
                    return true;
                }
            }
            return false;
        }

        // Range check
        $minVersion = $this->min !== null ? ApiVersion::fromString($this->min) : null;
        $maxVersion = $this->max !== null ? ApiVersion::fromString($this->max) : null;

        return $version->isWithinRange($minVersion, $maxVersion);
    }

    /**
     * Get human-readable constraint description
     */
    public function getDescription(): string
    {
        if (count($this->versions) > 0) {
            return 'versions: ' . implode(', ', array_map(fn($v) => "v{$v}", $this->versions));
        }

        $parts = [];
        if ($this->min !== null) {
            $parts[] = "min v{$this->min}";
        }
        if ($this->max !== null) {
            $parts[] = "max v{$this->max}";
        }

        return count($parts) > 0 ? implode(', ', $parts) : 'all versions';
    }
}

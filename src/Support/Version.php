<?php

declare(strict_types=1);

namespace Glueful\Support;

/**
 * Glueful Framework Version Information
 *
 * Provides version information and framework metadata.
 * Used for version checks, compatibility validation, and system information.
 */
final class Version
{
    /** Current framework version */
    public const VERSION = '1.9.2';

    /** Release code name */
    public const NAME = 'Deneb';

    /** Release date */
    public const RELEASE_DATE = '2026-01-20';

    /** Minimum required PHP version */
    public const MIN_PHP_VERSION = '8.3.0';

    /**
     * Get complete version information
     *
     * @return array<string, string> Version information array
     */
    public static function getInfo(): array
    {
        return [
            'version' => self::VERSION,
            'name' => self::NAME,
            'release_date' => self::RELEASE_DATE,
            'min_php_version' => self::MIN_PHP_VERSION,
        ];
    }

    /**
     * Get version string
     */
    public static function getVersion(): string
    {
        return self::VERSION;
    }

    /**
     * Get release name
     */
    public static function getName(): string
    {
        return self::NAME;
    }

    /**
     * Get release date
     */
    public static function getReleaseDate(): string
    {
        return self::RELEASE_DATE;
    }

    /**
     * Get minimum PHP version required
     */
    public static function getMinPhpVersion(): string
    {
        return self::MIN_PHP_VERSION;
    }

    /**
     * Check if current PHP version meets minimum requirements
     */
    public static function isPhpVersionSupported(): bool
    {
        return version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '>=');
    }

    /**
     * Get full version string with name
     */
    public static function getFullVersion(): string
    {
        return sprintf('%s (%s)', self::VERSION, self::NAME);
    }

    /**
     * Get formatted version information for display
     */
    public static function getFormattedInfo(): string
    {
        return sprintf(
            "Glueful Framework %s\n" .
            "Release: %s\n" .
            "Date: %s\n" .
            "PHP Version: %s (min: %s)",
            self::VERSION,
            self::NAME,
            self::RELEASE_DATE,
            PHP_VERSION,
            self::MIN_PHP_VERSION
        );
    }
}

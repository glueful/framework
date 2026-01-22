<?php

declare(strict_types=1);

namespace Glueful\Database\Factory;

/**
 * Bridge to Faker Library
 *
 * Provides a lazy-loaded connection to the Faker library with availability
 * checking. Faker is a require-dev dependency, so this bridge ensures
 * helpful error messages if factories are used without Faker installed.
 *
 * @package Glueful\Database\Factory
 */
class FakerBridge
{
    /**
     * Cached Faker instance
     *
     * @var object|null Faker\Generator instance when available
     */
    private static ?object $instance = null;

    /**
     * The locale for Faker
     */
    private static string $locale = 'en_US';

    /**
     * Get the Faker instance
     *
     * @return object Faker\Generator instance
     * @throws \RuntimeException if Faker is not installed
     */
    public static function getInstance(): object
    {
        if (!self::isAvailable()) {
            throw new \RuntimeException(
                'Faker is required for model factories. ' .
                'Install it with: composer require --dev fakerphp/faker'
            );
        }

        if (self::$instance === null) {
            // @phpstan-ignore-next-line Faker is an optional dependency
            self::$instance = \Faker\Factory::create(self::$locale);
        }

        return self::$instance;
    }

    /**
     * Check if Faker is available
     *
     * Returns true if the fakerphp/faker package is installed.
     */
    public static function isAvailable(): bool
    {
        return class_exists(\Faker\Factory::class);
    }

    /**
     * Set the locale for Faker
     *
     * Must be called before getInstance() to take effect, or
     * call reset() first if changing the locale after first use.
     *
     * @param string $locale Locale code (e.g., 'en_US', 'de_DE', 'fr_FR')
     */
    public static function setLocale(string $locale): void
    {
        self::$locale = $locale;
    }

    /**
     * Get the current locale
     */
    public static function getLocale(): string
    {
        return self::$locale;
    }

    /**
     * Reset the Faker instance
     *
     * Useful for testing or when changing locales.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Create a new Faker instance with a specific locale
     *
     * This does not affect the cached instance.
     *
     * @param string $locale Locale code
     * @return object Faker\Generator instance
     * @throws \RuntimeException if Faker is not installed
     */
    public static function create(string $locale = 'en_US'): object
    {
        if (!self::isAvailable()) {
            throw new \RuntimeException(
                'Faker is required for model factories. ' .
                'Install it with: composer require --dev fakerphp/faker'
            );
        }

        // @phpstan-ignore-next-line Faker is an optional dependency
        return \Faker\Factory::create($locale);
    }
}

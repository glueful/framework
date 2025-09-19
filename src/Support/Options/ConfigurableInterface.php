<?php

declare(strict_types=1);

namespace Glueful\Support\Options;

use Glueful\Support\Options\SimpleOptionsResolver as OptionsResolver;

/**
 * Configurable Interface
 *
 * Defines the contract for classes that use Symfony OptionsResolver
 * for configuration validation and normalization.
 *
 * @package Glueful\Support\Options
 */
interface ConfigurableInterface
{
    /**
     * Configure the options resolver
     *
     * This method should define:
     * - Default values for options
     * - Required options
     * - Allowed types and values
     * - Option normalizers
     *
     * @param OptionsResolver $resolver The options resolver instance
     */
    public function configureOptions(OptionsResolver $resolver): void;
}

<?php

declare(strict_types=1);

namespace Glueful\Container\Providers;

use Glueful\Container\Definition\DefinitionInterface;

/**
 * ORM Service Provider
 *
 * Provides service definitions for the ORM/Active Record system.
 * The actual initialization is handled via ApplicationContext-aware Model instances.
 * to ensure the container is fully built before the ORM is wired up.
 *
 * @package Glueful\Container\Providers
 */
final class ORMProvider extends BaseServiceProvider
{
    /**
     * @return array<string, DefinitionInterface|callable|mixed>
     */
    public function defs(): array
    {
        $defs = [];

        // Future ORM-specific service definitions can be added here
        // e.g., custom connection resolvers, model factories, etc.

        return $defs;
    }
}

<?php

declare(strict_types=1);

namespace Glueful\Container;

use Glueful\Container\Definition\DefinitionInterface;
use Psr\Container\ContainerInterface;

/**
 * A container that accepts definitions AFTER it has been built. Both the runtime
 * {@see Container} and the compiled container implement it, so a provider that re-pins a
 * service at boot (`$this->app->load([...])`) reaches production's compiled container exactly
 * as it reaches the runtime one — guard on THIS interface, never on the concrete class.
 */
interface RebindableContainer extends ContainerInterface
{
    /**
     * @param array<string, DefinitionInterface|callable|mixed> $defs
     */
    public function load(array $defs): void;
}

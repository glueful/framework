<?php

declare(strict_types=1);

namespace Glueful\Container\Definition;

use Psr\Container\ContainerInterface;

final class FactoryDefinition implements DefinitionInterface
{
    /**
     * @param callable $factory
     */
    public function __construct(private string $id, private $factory, private bool $shared = true)
    {
    }

    public function resolve(ContainerInterface $container): mixed
    {
        $f = $this->factory;
        return $f($container);
    }

    public function isShared(): bool
    {
        return $this->shared;
    }
}

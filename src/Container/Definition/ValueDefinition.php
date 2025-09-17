<?php

declare(strict_types=1);

namespace Glueful\Container\Definition;

use Psr\Container\ContainerInterface;

final class ValueDefinition implements DefinitionInterface
{
    public function __construct(private string $id, private mixed $value)
    {
    }

    public function resolve(ContainerInterface $container): mixed
    {
        return $this->value;
    }

    public function isShared(): bool
    {
        return true;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }
}

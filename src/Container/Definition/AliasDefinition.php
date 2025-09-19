<?php

declare(strict_types=1);

namespace Glueful\Container\Definition;

use Psr\Container\ContainerInterface;

final class AliasDefinition implements DefinitionInterface
{
    public function __construct(private string $id, private string $target)
    {
    }

    public function resolve(ContainerInterface $container): mixed
    {
        return $container->get($this->target);
    }

    public function isShared(): bool
    {
        // Do not cache alias ID itself; rely on target's sharing semantics
        return false;
    }

    public function getTarget(): string
    {
        return $this->target;
    }
}

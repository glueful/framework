<?php

declare(strict_types=1);

namespace Glueful\Container\Definition;

use Psr\Container\ContainerInterface;

interface DefinitionInterface
{
    public function resolve(ContainerInterface $container): mixed;
    public function isShared(): bool;
}

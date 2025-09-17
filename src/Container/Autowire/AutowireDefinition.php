<?php

declare(strict_types=1);

namespace Glueful\Container\Autowire;

use Glueful\Container\Definition\DefinitionInterface;
use Psr\Container\ContainerInterface;

final class AutowireDefinition implements DefinitionInterface
{
    private string $class;

    private bool $shared;

    private ReflectionResolver $resolver;

    public function __construct(
        private string $id,
        ?string $class = null,
        bool $shared = true,
        ?ReflectionResolver $resolver = null
    ) {
        $this->class = $class ?? $id;
        $this->shared = $shared;
        $this->resolver = $resolver ?? ReflectionResolver::shared();
    }

    public function resolve(ContainerInterface $container): mixed
    {
        return $this->resolver->resolve($this->class, $container);
    }

    public function isShared(): bool
    {
        return $this->shared;
    }

    public function getClass(): string
    {
        return $this->class;
    }
}

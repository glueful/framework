<?php

declare(strict_types=1);

namespace Glueful\Container\Providers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Autowire\AutowireDefinition;
use Glueful\Container\Definition\DefinitionInterface;

abstract class BaseServiceProvider
{
    protected TagCollector $tags;
    protected ApplicationContext $context;

    final public function __construct(TagCollector $tags, ApplicationContext $context)
    {
        $this->tags = $tags;
        $this->context = $context;
    }

    /** @return array<string, mixed|callable|DefinitionInterface> */
    abstract public function defs(): array;

    protected function autowire(string $fqcn, bool $shared = true): AutowireDefinition
    {
        return new AutowireDefinition($fqcn, shared: $shared);
    }

    protected function tag(string $serviceId, string $tagName, int $priority = 0): void
    {
        $this->tags->add($tagName, $serviceId, $priority);
    }

    protected function getContext(): ApplicationContext
    {
        return $this->context;
    }

    protected function alias(string $aliasId, string $targetId): \Glueful\Container\Definition\AliasDefinition
    {
        return new \Glueful\Container\Definition\AliasDefinition($aliasId, $targetId);
    }
}

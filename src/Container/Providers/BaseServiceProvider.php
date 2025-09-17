<?php

declare(strict_types=1);

namespace Glueful\Container\Providers;

use Glueful\Container\Autowire\AutowireDefinition;
use Glueful\Container\Definition\DefinitionInterface;

abstract class BaseServiceProvider
{
    protected TagCollector $tags;
    final public function __construct(TagCollector $tags)
    {
        $this->tags = $tags;
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
}

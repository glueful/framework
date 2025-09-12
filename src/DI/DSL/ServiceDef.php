<?php

declare(strict_types=1);

namespace Glueful\DI\DSL;

final class ServiceDef
{
    public function __construct(private Def $root, private string $class)
    {
        $this->root->put($class, ['class' => $class]);
    }

    public function args(mixed ...$args): self
    {
        $this->root->put($this->class, ['arguments' => $args]);
        return $this;
    }
    public function shared(bool $shared = true): self
    {
        $this->root->put($this->class, ['shared' => $shared]);
        return $this;
    }
    public function public(bool $public = true): self
    {
        $this->root->put($this->class, ['public' => $public]);
        return $this;
    }
    public function alias(string $alias): self
    {
        $this->root->put($this->class, ['alias' => [$alias]]);
        return $this;
    }
    /**
     * @param array<string, mixed> $attrs
     */
    public function tag(string $name, array $attrs = []): self
    {
        $tags = $this->root->toArray()[$this->class]['tags'] ?? [];
        $tags[] = ['name' => $name] + $attrs;
        $this->root->put($this->class, ['tags' => $tags]);
        return $this;
    }
    /**
     * @param array{0: string, 1: string}|string $factory
     */
    public function factory(array|string $factory): self
    {
        $this->root->put($this->class, ['factory' => $factory]);
        return $this;
    }
    /**
     * @param array<int, mixed> $args
     */
    public function call(string $method, array $args = []): self
    {
        $calls = $this->root->toArray()[$this->class]['calls'] ?? [];
        $calls[] = [$method, $args];
        $this->root->put($this->class, ['calls' => $calls]);
        return $this;
    }
    /**
     * Replace all method calls at once
     * @param array<int, array{0: string, 1: array<int, mixed>}> $methodCalls
     */
    public function calls(array $methodCalls): self
    {
        $this->root->put($this->class, ['calls' => $methodCalls]);
        return $this;
    }
    public function decorate(string $target, int $priority = 0): self
    {
        $this->root->put($this->class, ['decorate' => ['id' => $target, 'priority' => $priority]]);
        return $this;
    }
    public function end(): Def
    {
        return $this->root;
    }
}

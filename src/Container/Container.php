<?php

declare(strict_types=1);

namespace Glueful\Container;

use Glueful\Container\Definition\{DefinitionInterface, ValueDefinition, FactoryDefinition};
use Glueful\Container\Exception\{ContainerException, NotFoundException};
use Psr\Container\ContainerInterface as PsrContainer;

final class Container implements PsrContainer
{
    /** @var array<string, DefinitionInterface> */
    private array $definitions = [];

    /** @var array<string, mixed> */
    private array $singletons = [];

    /** @var array<string, bool> */
    private array $resolving = [];

    private ?PsrContainer $delegate = null;

    /**
     * @param array<string, DefinitionInterface|callable|mixed> $definitions
     */
    public function __construct(array $definitions = [], ?PsrContainer $delegate = null)
    {
        $this->delegate = $delegate;
        $this->load($definitions);
    }

    public function has(string $id): bool
    {
        return isset($this->singletons[$id]) || isset($this->definitions[$id]) || ($this->delegate?->has($id) ?? false);
    }

    public function get(string $id): mixed
    {
        if (isset($this->singletons[$id])) {
            return $this->singletons[$id];
        }
        if (!isset($this->definitions[$id])) {
            if ($this->delegate !== null && $this->delegate->has($id)) {
                return $this->delegate->get($id);
            }
            throw new NotFoundException("Service '$id' not found");
        }
        if (isset($this->resolving[$id])) {
            $chain = implode(' -> ', array_keys($this->resolving));
            $chain = $chain !== '' ? ($chain . ' -> ' . $id) : $id;
            throw new ContainerException("Circular dependency detected: {$chain}");
        }
        $this->resolving[$id] = true;
        try {
            $def = $this->definitions[$id];
            $val = $def->resolve($this);
            if ($def->isShared()) {
                $this->singletons[$id] = $val;
            }
            return $val;
        } finally {
            unset($this->resolving[$id]);
        }
    }

    /**
     * @param array<string, DefinitionInterface|callable|mixed> $overrides
     */
    public function with(array $overrides): self
    {
        return new self($overrides, $this);
    }

    public function reset(): void
    {
        $this->singletons = [];
    }

    /**
     * @param array<string, DefinitionInterface|callable|mixed> $defs
     */
    public function load(array $defs): void
    {
        foreach ($defs as $id => $d) {
            $this->definitions[$id] = $d instanceof DefinitionInterface ? $d
                : (is_callable($d) ? new FactoryDefinition($id, $d) : new ValueDefinition($id, $d));
        }
    }
}

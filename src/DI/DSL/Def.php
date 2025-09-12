<?php

declare(strict_types=1);

namespace Glueful\DI\DSL;

final class Def
{
      /** @var array<string, array<string,mixed>> */
    private array $defs = [];
    public static function create(): self
    {
        return new self();
    }
    public function service(string $class): ServiceDef
    {
        return new ServiceDef($this, $class);
    }
    public function singleton(string $class): ServiceDef
    {
        return $this->service($class)->shared(true);
    }
    public function bind(string $iface, string $impl): self
    {
        $this->defs[$impl] = ($this->defs[$impl] ?? []) + ['class' => $impl];
        $aliases = $this->defs[$impl]['alias'] ?? [];
        $this->defs[$impl]['alias'] = array_values(array_unique([...$aliases, $iface]));
        return $this;
    }
    /**
     * @internal
     * @param array<string, mixed> $def
     */
    public function put(string $id, array $def): void
    {
        $this->defs[$id] = ($this->defs[$id] ?? []) + $def;
    }

    /** @return array<string, array<string,mixed>> */
    public function toArray(): array
    {
         return $this->defs;
    }
}

<?php

declare(strict_types=1);

namespace Glueful\Container\Providers;

final class TagCollector
{
    /** @var array<string, array<int, array{service:string, priority:int}>> */
    private array $byTag = [];

    public function add(string $tag, string $serviceId, int $priority = 0): void
    {
        $this->byTag[$tag][] = ['service' => $serviceId, 'priority' => $priority];
    }

    /** @return array<string, array<int, array{service:string, priority:int}>> */
    public function all(): array
    {
        return $this->byTag;
    }
}

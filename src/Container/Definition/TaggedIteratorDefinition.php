<?php

declare(strict_types=1);

namespace Glueful\Container\Definition;

use Psr\Container\ContainerInterface;

final class TaggedIteratorDefinition implements DefinitionInterface
{
    /** @param array<int, array{service:string, priority:int}> $tagged */
    public function __construct(private string $id, private array $tagged)
    {
    }

    /** @return array<mixed> */
    public function resolve(ContainerInterface $container): array
    {
        $sorted = $this->tagged;
        usort($sorted, fn($a, $b) => $b['priority'] <=> $a['priority']);
        return array_map(fn($t) => $container->get($t['service']), $sorted);
    }

    public function isShared(): bool
    {
        return true;
    }

    /**
     * Expose tagged entries for compilation
     * @return array<int, array{service:string, priority:int}>
     */
    public function getTagged(): array
    {
        return $this->tagged;
    }
}

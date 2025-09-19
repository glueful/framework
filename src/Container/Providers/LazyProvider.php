<?php

declare(strict_types=1);

namespace Glueful\Container\Providers;

use Glueful\Container\Definition\{DefinitionInterface, ValueDefinition, FactoryDefinition, AliasDefinition};

final class LazyProvider extends BaseServiceProvider
{
    /**
     * @return array<string, DefinitionInterface|callable|mixed>
     */
    public function defs(): array
    {
        $defs = [];

        // Build lists of service IDs from tags (no instantiation)
        $all = $this->tags->all();
        $background = $this->collectIds($all['lazy.background'] ?? []);
        $requestTime = $this->collectIds($all['lazy.request_time'] ?? []);

        $defs['lazy.background.ids'] = new ValueDefinition('lazy.background.ids', $background);
        $defs['lazy.request_time.ids'] = new ValueDefinition('lazy.request_time.ids', $requestTime);

        // LazyInitializer service
        $defs[\Glueful\Container\Support\LazyInitializer::class] = new FactoryDefinition(
            \Glueful\Container\Support\LazyInitializer::class,
            function (\Psr\Container\ContainerInterface $c) {
                $bg = $c->has('lazy.background.ids') ? (array) $c->get('lazy.background.ids') : [];
                $rt = $c->has('lazy.request_time.ids') ? (array) $c->get('lazy.request_time.ids') : [];
                return new \Glueful\Container\Support\LazyInitializer($c, $bg, $rt);
            }
        );
        $defs['lazy.initializer'] = new AliasDefinition(
            'lazy.initializer',
            \Glueful\Container\Support\LazyInitializer::class
        );

        return $defs;
    }

    /**
     * @param array<int, array{service:string, priority:int}> $entries
     * @return array<int,string>
     */
    private function collectIds(array $entries): array
    {
        usort($entries, static fn(array $a, array $b) => $b['priority'] <=> $a['priority']);
        $ids = [];
        foreach ($entries as $e) {
            $s = (string) ($e['service'] ?? '');
            if ($s !== '') {
                $ids[] = $s;
            }
        }
        return array_values(array_unique($ids));
    }
}

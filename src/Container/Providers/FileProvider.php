<?php

declare(strict_types=1);

namespace Glueful\Container\Providers;

use Glueful\Container\Definition\{DefinitionInterface, FactoryDefinition, AliasDefinition};

final class FileProvider extends BaseServiceProvider
{
    /**
     * @return array<string, DefinitionInterface|callable|mixed>
     */
    public function defs(): array
    {
        $defs = [];

        $defs[\Glueful\Services\FileFinder::class] = new FactoryDefinition(
            \Glueful\Services\FileFinder::class,
            function (\Psr\Container\ContainerInterface $c) {
                $cfg = function_exists('config') ? (array) config($this->context, 'filesystem.file_finder', []) : [];
                return new \Glueful\Services\FileFinder(
                    $c->get('logger'),
                    $cfg
                );
            }
        );

        $defs['file.finder'] = new AliasDefinition('file.finder', \Glueful\Services\FileFinder::class);

        return $defs;
    }
}

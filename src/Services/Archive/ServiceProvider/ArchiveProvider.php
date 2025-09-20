<?php

declare(strict_types=1);

namespace Glueful\Services\Archive\ServiceProvider;

use Glueful\Container\Definition\{DefinitionInterface, FactoryDefinition, AliasDefinition};
use Glueful\Container\Providers\BaseServiceProvider;

final class ArchiveProvider extends BaseServiceProvider
{
    /**
     * @return array<string, DefinitionInterface|callable|mixed>
     */
    public function defs(): array
    {
        $defs = [];

        $defs[\Glueful\Services\Archive\ArchiveServiceInterface::class] = new FactoryDefinition(
            \Glueful\Services\Archive\ArchiveServiceInterface::class,
            function (\Psr\Container\ContainerInterface $c) {
                $cfg = function_exists('config') ? (array) config('archive.config', []) : [];
                return new \Glueful\Services\Archive\ArchiveService(
                    $c->get('database'),
                    $c->get(\Glueful\Database\Schema\Interfaces\SchemaBuilderInterface::class),
                    $c->get(\Glueful\Security\RandomStringGenerator::class),
                    $cfg
                );
            }
        );

        $defs[\Glueful\Services\Archive\ArchiveService::class] = new AliasDefinition(
            \Glueful\Services\Archive\ArchiveService::class,
            \Glueful\Services\Archive\ArchiveServiceInterface::class
        );

        return $defs;
    }
}

<?php

declare(strict_types=1);

namespace Glueful\Container\Providers;

use Glueful\Container\Definition\{DefinitionInterface, FactoryDefinition, AliasDefinition};

final class RepositoryProvider extends BaseServiceProvider
{
    /**
     * @return array<string, DefinitionInterface|callable|mixed>
     */
    public function defs(): array
    {
        $defs = [];

        $defs[\Glueful\Repository\RepositoryFactory::class] = new FactoryDefinition(
            \Glueful\Repository\RepositoryFactory::class,
            fn(\Psr\Container\ContainerInterface $c) => new \Glueful\Repository\RepositoryFactory(
                $c->get('database')
            )
        );

        // UserRepository moved to the glueful/users extension (registers its own binding).
        $defs[\Glueful\Repository\ResourceRepository::class] = new FactoryDefinition(
            \Glueful\Repository\ResourceRepository::class,
            fn(\Psr\Container\ContainerInterface $c) => new \Glueful\Repository\ResourceRepository(
                $c->get('database')
            )
        );
        $defs[\Glueful\Repository\NotificationRepository::class] = new FactoryDefinition(
            \Glueful\Repository\NotificationRepository::class,
            fn(\Psr\Container\ContainerInterface $c) => new \Glueful\Repository\NotificationRepository(
                $c->get('database')
            )
        );
        $defs[\Glueful\Repository\BlobRepository::class] = new FactoryDefinition(
            \Glueful\Repository\BlobRepository::class,
            fn(\Psr\Container\ContainerInterface $c) => new \Glueful\Repository\BlobRepository(
                $c->get('database')
            )
        );

        // Convenience aliases
        $defs['repository'] = new AliasDefinition('repository', \Glueful\Repository\RepositoryFactory::class);
        // 'repository.user' alias removed — UserRepository moved to glueful/users.
        $defs['repository.resource'] = new AliasDefinition(
            'repository.resource',
            \Glueful\Repository\ResourceRepository::class
        );
        $defs['repository.notification'] = new AliasDefinition(
            'repository.notification',
            \Glueful\Repository\NotificationRepository::class
        );
        $defs['repository.blob'] = new AliasDefinition('repository.blob', \Glueful\Repository\BlobRepository::class);

        return $defs;
    }
}

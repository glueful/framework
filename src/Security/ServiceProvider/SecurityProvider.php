<?php

declare(strict_types=1);

namespace Glueful\Security\ServiceProvider;

use Glueful\Container\Definition\{DefinitionInterface, FactoryDefinition};
use Glueful\Container\Providers\BaseServiceProvider;

final class SecurityProvider extends BaseServiceProvider
{
    /**
     * @return array<string, DefinitionInterface|callable|mixed>
     */
    public function defs(): array
    {
        $defs = [];

        // SecureSerializer variants
        $defs['serializer.cache'] = new FactoryDefinition(
            'serializer.cache',
            fn() => \Glueful\Security\SecureSerializer::forCache()
        );
        $defs['serializer.queue'] = new FactoryDefinition(
            'serializer.queue',
            fn() => \Glueful\Security\SecureSerializer::forQueue()
        );

        $defs[\Glueful\Security\SecureSerializer::class] =
            $this->autowire(\Glueful\Security\SecureSerializer::class);

        return $defs;
    }
}

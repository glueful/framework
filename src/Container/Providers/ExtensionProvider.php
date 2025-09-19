<?php

declare(strict_types=1);

namespace Glueful\Container\Providers;

use Glueful\Container\Definition\{DefinitionInterface, FactoryDefinition, AliasDefinition};

final class ExtensionProvider extends BaseServiceProvider
{
    /**
     * @return array<string, DefinitionInterface|callable|mixed>
     */
    public function defs(): array
    {
        $defs = [];

        $defs[\Glueful\Extensions\ExtensionMetadataRegistry::class] =
            $this->autowire(\Glueful\Extensions\ExtensionMetadataRegistry::class);
        $defs[\Glueful\Extensions\PackageManifest::class] =
            $this->autowire(\Glueful\Extensions\PackageManifest::class);

        // Manager expects legacy container; use global for now
        $defs['extension.manager'] = new FactoryDefinition(
            'extension.manager',
            function () {
                $di = $GLOBALS['container'] ?? null;
                if ($di === null) {
                    throw new \RuntimeException('Global DI container not initialized');
                }
                return new \Glueful\Extensions\ExtensionManager($di);
            }
        );
        $defs[\Glueful\Extensions\ExtensionManager::class] = new AliasDefinition(
            \Glueful\Extensions\ExtensionManager::class,
            'extension.manager'
        );
        $defs['extensions'] = new AliasDefinition('extensions', 'extension.manager');

        return $defs;
    }
}

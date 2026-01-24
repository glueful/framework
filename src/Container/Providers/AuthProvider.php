<?php

declare(strict_types=1);

namespace Glueful\Container\Providers;

use Glueful\Auth\Interfaces\SessionStoreInterface;
use Glueful\Auth\SessionStore;
use Glueful\Container\Definition\DefinitionInterface;

final class AuthProvider extends BaseServiceProvider
{
    /**
     * @return array<string, DefinitionInterface|callable|mixed>
     */
    public function defs(): array
    {
        $defs = [];

        // Unified session store - single shared instance
        // The concrete class is the canonical definition, interface aliases to it
        $defs[SessionStore::class] = $this->autowire(SessionStore::class);
        $defs[SessionStoreInterface::class] = $this->alias(SessionStoreInterface::class, SessionStore::class);

        return $defs;
    }
}

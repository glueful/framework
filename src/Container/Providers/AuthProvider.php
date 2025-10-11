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

        // Unified session store
        $defs[SessionStoreInterface::class] = $this->autowire(SessionStore::class);
        $defs[SessionStore::class] = $this->autowire(SessionStore::class);

        return $defs;
    }
}

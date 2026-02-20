<?php

declare(strict_types=1);

namespace Glueful\Container\Providers;

use Glueful\Auth\Interfaces\SessionStoreInterface;
use Glueful\Auth\AccessTokenIssuer;
use Glueful\Auth\ProviderTokenIssuer;
use Glueful\Auth\RefreshTokenStore;
use Glueful\Auth\RefreshTokenRepository;
use Glueful\Auth\RefreshService;
use Glueful\Auth\SessionRepository;
use Glueful\Auth\SessionStateCache;
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
        $defs[RefreshTokenStore::class] = $this->autowire(RefreshTokenStore::class);
        $defs[SessionRepository::class] = $this->autowire(SessionRepository::class);
        $defs[RefreshTokenRepository::class] = $this->autowire(RefreshTokenRepository::class);
        $defs[AccessTokenIssuer::class] = $this->autowire(AccessTokenIssuer::class);
        $defs[ProviderTokenIssuer::class] = $this->autowire(ProviderTokenIssuer::class);
        $defs[SessionStateCache::class] = $this->autowire(SessionStateCache::class);
        $defs[RefreshService::class] = $this->autowire(RefreshService::class);

        return $defs;
    }
}

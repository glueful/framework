<?php

declare(strict_types=1);

namespace Glueful\Auth\Traits;

use Glueful\Auth\Interfaces\SessionStoreInterface;
use Glueful\Auth\Utils\SessionStoreResolver;

trait ResolvesSessionStore
{
    /**
     * Resolve SessionStore via DI container with fallback
     */
    protected function getSessionStore(): SessionStoreInterface
    {
        return SessionStoreResolver::resolve();
    }
}

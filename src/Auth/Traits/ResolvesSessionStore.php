<?php

declare(strict_types=1);

namespace Glueful\Auth\Traits;

use Glueful\Auth\Interfaces\SessionStoreInterface;
use Glueful\Auth\Utils\SessionStoreResolver;

trait ResolvesSessionStore
{
    /**
     * Get the application context for session store resolution.
     * Classes using this trait should override this method.
     */
    protected function getContext(): ?\Glueful\Bootstrap\ApplicationContext
    {
        return $this->context ?? null;
    }

    /**
     * Resolve SessionStore via DI container with fallback
     */
    protected function getSessionStore(): SessionStoreInterface
    {
        $context = $this->getContext();
        return SessionStoreResolver::resolve($context);
    }
}

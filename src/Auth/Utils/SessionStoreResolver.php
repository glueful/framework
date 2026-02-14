<?php

declare(strict_types=1);

namespace Glueful\Auth\Utils;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Auth\Interfaces\SessionStoreInterface;

final class SessionStoreResolver
{
    public static function resolve(?ApplicationContext $context = null): SessionStoreInterface
    {
        if ($context === null || !$context->hasContainer()) {
            throw new \RuntimeException(
                'SessionStore requires ApplicationContext with a booted container.'
            );
        }

        /** @var SessionStoreInterface $store */
        $store = container($context)->get(SessionStoreInterface::class);
        return $store;
    }
}

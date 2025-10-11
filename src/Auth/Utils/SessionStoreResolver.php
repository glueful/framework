<?php

declare(strict_types=1);

namespace Glueful\Auth\Utils;

use Glueful\Auth\Interfaces\SessionStoreInterface;
use Glueful\Auth\SessionStore;

final class SessionStoreResolver
{
    public static function resolve(): SessionStoreInterface
    {
        try {
            /** @var SessionStoreInterface $store */
            $store = container()->get(SessionStoreInterface::class);
            return $store;
        } catch (\Throwable) {
            return new SessionStore();
        }
    }
}

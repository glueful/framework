<?php

declare(strict_types=1);

namespace Glueful\Auth\Utils;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Auth\Interfaces\SessionStoreInterface;
use Glueful\Auth\SessionStore;

final class SessionStoreResolver
{
    public static function resolve(?ApplicationContext $context = null): SessionStoreInterface
    {
        try {
            if ($context !== null) {
                /** @var SessionStoreInterface $store */
                $store = container($context)->get(SessionStoreInterface::class);
            } else {
                throw new \RuntimeException('Container unavailable without ApplicationContext.');
            }
            return $store;
        } catch (\Throwable) {
            return new SessionStore();
        }
    }
}

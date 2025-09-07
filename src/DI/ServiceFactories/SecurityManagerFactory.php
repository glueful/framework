<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceFactories;

use Glueful\Security\SecurityManager;

class SecurityManagerFactory
{
    public static function create(): SecurityManager
    {
        return new SecurityManager();
    }
}

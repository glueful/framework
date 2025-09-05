<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceFactories;

use Glueful\Bootstrap\ConfigurationCache;
use Glueful\Http\Router;

class RouterFactory
{
    public static function create(): Router
    {
        $apiVersion = ConfigurationCache::get('app.api_version', 'v1');

        Router::setVersion($apiVersion);

        // getInstance() triggers constructor which loads routes (from cache or files)
        return Router::getInstance();
    }
}

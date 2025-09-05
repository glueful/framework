<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceFactories;

use Glueful\Extensions\ExtensionManager;
use Glueful\Extensions\Services\ExtensionLoader;
use Glueful\Extensions\Services\ExtensionConfig;
use Glueful\Extensions\Services\ExtensionCatalog;
use Glueful\Extensions\Services\ExtensionValidator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class ExtensionManagerFactory
{
    public static function create(): ExtensionManager
    {
        // Use a simple logger for now to avoid circular dependencies
        $logger = new NullLogger();

        return new ExtensionManager(
            new ExtensionLoader(),
            new ExtensionConfig(),
            new ExtensionCatalog(),
            new ExtensionValidator(),
            $logger
        );
    }
}

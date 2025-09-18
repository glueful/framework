<?php

declare(strict_types=1);

namespace Glueful\DI;

/**
 * Simplified Container Bootstrap - Heavy logic moved to ApplicationKernel
 */
class ContainerBootstrap
{
    private static ?Container $container = null;

    public static function initialize(string $basePath, string $applicationConfigPath, string $environment): Container
    {
        if (self::$container !== null) {
            return self::$container;
        }

        // Since ApplicationKernel now handles configuration loading via ConfigurationCache,
        // we only need to create a basic container here
        self::initializeBasicConfig($basePath, $applicationConfigPath, $environment);

        // Create container - prefer compiled for production
        $isProduction = $environment === 'production' && !($_ENV['APP_DEBUG'] ?? false);
        if ($isProduction && ContainerFactory::hasCompiledContainer()) {
            self::$container = ContainerFactory::create(true);
        } else {
            self::$container = ContainerFactory::create(false);
        }

        // Minimal bootstrapping - heavy service loading is now lazy
        self::registerEssentialServices();

        return self::$container;
    }

    private static function initializeBasicConfig(
        string $basePath,
        string $applicationConfigPath,
        string $environment
    ): void {
        // Keep minimal globals for backward compatibility
        $GLOBALS['base_path'] = $basePath;
        $GLOBALS['app_environment'] = $environment;

        // Essential container parameters
        $GLOBALS['container_parameters'] = [
            'app.base_path' => $basePath,
            'app.config_path' => $applicationConfigPath,
            'app.environment' => $environment,
        ];
    }

    private static function registerEssentialServices(): void
    {
        // Only register absolutely essential services that can't be lazy-loaded
        $coreProviders = [
            new ServiceProviders\CoreServiceProvider(),
        ];

        foreach ($coreProviders as $provider) {
            $provider->boot(self::$container);
        }

        // All other services are now registered as lazy services by ApplicationKernel
    }

    /**
     * Get the current container instance
     */
    public static function getContainer(): ?Container
    {
        return self::$container;
    }

    /**
     * Reset container (useful for testing)
     */
    public static function reset(): void
    {
        self::$container = null;
    }
}

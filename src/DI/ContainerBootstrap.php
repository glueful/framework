<?php

declare(strict_types=1);

namespace Glueful\DI;

/**
 * Pure Symfony DI Bootstrap - Complete replacement
 */
class ContainerBootstrap
{
    private static ?Container $container = null;

    public static function initialize(string $basePath, string $applicationConfigPath, string $environment): Container
    {
        if (self::$container !== null) {
            return self::$container;
        }

        // Set up config hierarchy
        $frameworkConfigPath = dirname(__DIR__, 2) . '/config';  // Framework defaults
        self::initializeConfigSystem($basePath, $frameworkConfigPath, $applicationConfigPath, $environment);

        // Create container with proper environment detection
        $isProduction = $environment === 'production' && !($_ENV['APP_DEBUG'] ?? false);
        if ($isProduction) {
            // Prefer compiled container for production
            if (ContainerFactory::hasCompiledContainer()) {
                self::$container = ContainerFactory::create(true);
            } else {
                self::$container = ContainerFactory::buildProductionContainer();
            }
        } else {
            self::$container = ContainerFactory::create(false);
        }

        // Boot with all configs loaded
        self::bootContainer($basePath);

        return self::$container;
    }

    private static function initializeConfigSystem(
        string $basePath,
        string $frameworkConfigPath,
        string $applicationConfigPath,
        string $environment
    ): void {
        // Register config paths globally so config() helper can find them
        $GLOBALS['config_paths'] = [
            'framework' => $frameworkConfigPath,      // Framework defaults (lowest priority)
            'application' => $applicationConfigPath,  // User config (highest priority)
        ];
        $GLOBALS['app_environment'] = $environment;
        $GLOBALS['base_path'] = $basePath;

        // Register paths in container for Application access
        $GLOBALS['container_parameters'] = [
            'app.base_path' => $basePath,
            'app.config_path' => $applicationConfigPath,
            'app.environment' => $environment,
        ];
    }

    private static function bootContainer(string $basePath): void
    {
        // Boot service providers that need post-compilation setup
        $providers = [
            new ServiceProviders\CoreServiceProvider(),
            new ServiceProviders\ConfigServiceProvider(),
            new ServiceProviders\SecurityServiceProvider(),
            new ServiceProviders\ValidatorServiceProvider(),
            new ServiceProviders\SerializerServiceProvider(),
            new ServiceProviders\HttpClientServiceProvider(),
            new ServiceProviders\RequestServiceProvider(),
            new ServiceProviders\FileServiceProvider(),
            new ServiceProviders\LockServiceProvider(),
            new ServiceProviders\EventServiceProvider(),
            new ServiceProviders\ConsoleServiceProvider(),
            new ServiceProviders\VarDumperServiceProvider(),
            new ServiceProviders\ExtensionServiceProvider(),
            new ServiceProviders\ArchiveServiceProvider(),
            new ServiceProviders\ControllerServiceProvider(),
            new ServiceProviders\QueueServiceProvider(),
            new ServiceProviders\RepositoryServiceProvider(),
            new ServiceProviders\SpaServiceProvider(),
        ];

        foreach ($providers as $provider) {
            $provider->boot(self::$container);
        }

        // Boot extension service providers
        self::bootExtensionServiceProviders($basePath);
    }

    private static function bootExtensionServiceProviders(string $basePath): void
    {
        // Use provided basePath instead of hardcoded dirname(__DIR__, 2)
        $extensionsConfig = $basePath . '/extensions/extensions.json';

        if (!file_exists($extensionsConfig)) {
            return;
        }

        $extensionsData = json_decode(file_get_contents($extensionsConfig), true);
        if (!isset($extensionsData['extensions'])) {
            return;
        }

        foreach ($extensionsData['extensions'] as $extensionName => $config) {
            if (($config['enabled'] ?? false) !== true) {
                continue;
            }

            $serviceProviders = $config['provides']['services'] ?? [];
            foreach ($serviceProviders as $serviceProviderPath) {
                $absolutePath = $basePath . '/' . $serviceProviderPath;

                if (!file_exists($absolutePath)) {
                    continue;
                }

                // Build class name from path (existing logic)
                $pathInfo = pathinfo($serviceProviderPath);
                $className = $pathInfo['filename'];
                $pathParts = explode('/', $serviceProviderPath);
                $fullClassName = null;

                if (count($pathParts) >= 5 && $pathParts[2] === 'src') {
                    $subNamespace = $pathParts[3];
                    $fullClassName = "Glueful\\Extensions\\{$extensionName}\\{$subNamespace}\\{$className}";
                } else {
                    $fullClassName = "Glueful\\Extensions\\{$extensionName}\\{$className}";
                }

                if (!class_exists($fullClassName)) {
                    continue;
                }

                // Create instance and boot
                $serviceProvider = new $fullClassName();
                if (method_exists($serviceProvider, 'boot')) {
                    $serviceProvider->boot(self::$container);
                }
            }
        }
    }

    public static function reset(): void
    {
        self::$container = null;
        unset($GLOBALS['config_paths'], $GLOBALS['app_environment'], $GLOBALS['base_path']);
    }

    public static function getContainer(): ?Container
    {
        return self::$container;
    }
}

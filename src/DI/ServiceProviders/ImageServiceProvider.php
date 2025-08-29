<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceProviders;

use Glueful\DI\ServiceProviders\BaseServiceProvider;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Glueful\Services\ImageProcessorInterface;
use Glueful\Services\ImageProcessor;
use Glueful\Services\ImageSecurityValidator;
use Glueful\Cache\CacheStore;
use Psr\Log\LoggerInterface;

/**
 * Image Service Provider
 *
 * Registers image processing services in the dependency injection container.
 * Configures Intervention Image with appropriate drivers and settings.
 */
class ImageServiceProvider extends BaseServiceProvider
{
    /**
     * Register image processing services
     *
     * @return void
     */
    public function register(): void
    {
        $this->registerImageManager();
        $this->registerImageSecurityValidator();
        $this->registerImageProcessor();
    }

    /**
     * Register Intervention Image Manager with driver
     *
     * @return void
     */
    private function registerImageManager(): void
    {
        $this->container->singleton(ImageManager::class, function ($container) {
            $driverType = config('image.driver', 'gd');

            // Choose driver based on configuration and availability
            $driver = match ($driverType) {
                'imagick' => $this->createImagickDriver(),
                'gd' => $this->createGdDriver(),
                default => $this->createGdDriver() // Default to GD
            };

            return new ImageManager($driver);
        });
    }

    /**
     * Register Image Security Validator
     *
     * @return void
     */
    private function registerImageSecurityValidator(): void
    {
        $this->container->singleton(ImageSecurityValidator::class, function ($container) {
            $securityConfig = config('image.security', []);

            // Merge with limits configuration
            $limitsConfig = config('image.limits', []);
            $mergedConfig = array_merge($securityConfig, $limitsConfig);

            return new ImageSecurityValidator($mergedConfig);
        });
    }

    /**
     * Register Image Processor Interface and Implementation
     *
     * @return void
     */
    private function registerImageProcessor(): void
    {
        $this->container->bind(ImageProcessorInterface::class, function ($container) {
            return new ImageProcessor(
                $container->get(ImageManager::class),
                $container->get(CacheStore::class),
                $container->get(ImageSecurityValidator::class),
                $container->get(LoggerInterface::class),
                $this->getImageProcessorConfig()
            );
        });

        // Also register the concrete implementation
        $this->container->bind(ImageProcessor::class, function ($container) {
            return $container->get(ImageProcessorInterface::class);
        });
    }

    /**
     * Create GD driver instance
     *
     * @return GdDriver
     */
    private function createGdDriver(): GdDriver
    {
        // Verify GD extension is loaded
        if (!extension_loaded('gd')) {
            throw new \RuntimeException('GD extension is not loaded');
        }

        return new GdDriver();
    }

    /**
     * Create ImageMagick driver instance
     *
     * @return ImagickDriver
     * @throws \RuntimeException If ImageMagick is not available
     */
    private function createImagickDriver(): ImagickDriver
    {
        // Check if ImageMagick extension is available
        if (!extension_loaded('imagick')) {
            // Fall back to GD if ImageMagick is not available
            $this->logDriverFallback('imagick', 'gd', 'ImageMagick extension not loaded');
            return $this->createGdDriver();
        }

        // Verify ImageMagick is functional
        if (!class_exists('\Imagick')) {
            $this->logDriverFallback('imagick', 'gd', 'Imagick class not found');
            return $this->createGdDriver();
        }

        return new ImagickDriver();
    }

    /**
     * Get image processor configuration
     *
     * @return array Configuration array
     */
    private function getImageProcessorConfig(): array
    {
        return [
            'optimization' => config('image.optimization', []),
            'security' => config('image.security', []),
            'cache' => config('image.cache', []),
            'features' => config('image.features', []),
            'defaults' => config('image.defaults', []),
            'performance' => config('image.performance', []),
            'monitoring' => config('image.monitoring', []),
        ];
    }

    /**
     * Log driver fallback information
     *
     * @param string $requestedDriver Requested driver
     * @param string $fallbackDriver Fallback driver
     * @param string $reason Reason for fallback
     * @return void
     */
    private function logDriverFallback(string $requestedDriver, string $fallbackDriver, string $reason): void
    {
        if ($this->container->has(LoggerInterface::class)) {
            $logger = $this->container->get(LoggerInterface::class);
            $logger->warning('Image driver fallback', [
                'requested_driver' => $requestedDriver,
                'fallback_driver' => $fallbackDriver,
                'reason' => $reason,
                'type' => 'image_processing'
            ]);
        }
    }

    /**
     * Boot method - called after all services are registered
     *
     * @return void
     */
    public function boot(): void
    {
        // Verify image processing is properly configured
        $this->validateImageConfiguration();

        // Register any image processing middleware or events here
        // if needed in the future
    }

    /**
     * Validate image processing configuration
     *
     * @return void
     * @throws \RuntimeException If configuration is invalid
     */
    private function validateImageConfiguration(): void
    {
        // Check required configuration
        $requiredConfigs = [
            'image.driver',
            'image.limits.max_width',
            'image.limits.max_height',
        ];

        foreach ($requiredConfigs as $configKey) {
            if (!config($configKey)) {
                throw new \RuntimeException("Missing required image configuration: {$configKey}");
            }
        }

        // Validate driver availability
        $driver = config('image.driver', 'gd');
        if ($driver === 'imagick' && !extension_loaded('imagick')) {
            $this->logDriverFallback('imagick', 'gd', 'ImageMagick not available, using GD');
        }

        // Validate cache configuration if caching is enabled
        if (config('image.cache.enabled', true)) {
            if (!$this->container->has(CacheStore::class)) {
                throw new \RuntimeException('Image caching enabled but CacheStore not registered');
            }
        }

        // Validate security settings
        $maxWidth = config('image.limits.max_width');
        $maxHeight = config('image.limits.max_height');

        if ($maxWidth <= 0 || $maxHeight <= 0) {
            throw new \RuntimeException('Invalid image dimension limits');
        }

        if ($maxWidth > 8192 || $maxHeight > 8192) {
            if ($this->container->has(LoggerInterface::class)) {
                $logger = $this->container->get(LoggerInterface::class);
                $logger->warning('Very large image dimensions configured', [
                    'max_width' => $maxWidth,
                    'max_height' => $maxHeight,
                    'type' => 'image_processing'
                ]);
            }
        }
    }

    /**
     * Get services provided by this provider
     *
     * @return array
     */
    public function provides(): array
    {
        return [
            ImageManager::class,
            ImageSecurityValidator::class,
            ImageProcessorInterface::class,
            ImageProcessor::class,
        ];
    }
}

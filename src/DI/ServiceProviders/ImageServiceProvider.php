<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceProviders;

use Glueful\DI\ServiceProviderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Glueful\DI\Container;
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
class ImageServiceProvider implements ServiceProviderInterface
{
    /**
     * Register image processing services
     *
     * @param ContainerBuilder $container
     */
    public function register(ContainerBuilder $container): void
    {
        $this->registerImageManager($container);
        $this->registerImageSecurityValidator($container);
        $this->registerImageProcessor($container);
    }

    /**
     * Register Intervention Image Manager with driver
     *
     * @return void
     */
    private function registerImageManager(ContainerBuilder $container): void
    {
        $container->register(ImageManager::class)
            ->setFactory([self::class, 'createImageManager'])
            ->setPublic(true);
    }

    /**
     * Factory method for creating ImageManager
     */
    public static function createImageManager(): ImageManager
    {
        $driverType = config('image.driver', 'gd');

        // Choose driver based on configuration and availability
        $driver = match ($driverType) {
            'imagick' => self::createImagickDriver(),
            'gd' => self::createGdDriver(),
            default => self::createGdDriver() // Default to GD
        };

        return new ImageManager($driver);
    }

    /**
     * Register Image Security Validator
     *
     * @return void
     */
    private function registerImageSecurityValidator(ContainerBuilder $container): void
    {
        $container->register(ImageSecurityValidator::class)
            ->setFactory([self::class, 'createImageSecurityValidator'])
            ->setPublic(true);
    }

    /**
     * Factory method for creating ImageSecurityValidator
     */
    public static function createImageSecurityValidator(): ImageSecurityValidator
    {
        $securityConfig = config('image.security', []);
        $limitsConfig = config('image.limits', []);
        $mergedConfig = array_merge($securityConfig, $limitsConfig);

        return new ImageSecurityValidator($mergedConfig);
    }

    /**
     * Register Image Processor Interface and Implementation
     *
     * @return void
     */
    private function registerImageProcessor(ContainerBuilder $container): void
    {
        $container->register(ImageProcessorInterface::class)
            ->setFactory([self::class, 'createImageProcessor'])
            ->setArguments([
                new Reference(ImageManager::class),
                new Reference(CacheStore::class),
                new Reference(ImageSecurityValidator::class),
                new Reference(LoggerInterface::class)
            ])
            ->setPublic(true);

        // Also register the concrete implementation
        $container->setAlias(ImageProcessor::class, ImageProcessorInterface::class)
            ->setPublic(true);
    }

    /**
     * Factory method for creating ImageProcessor
     *
     * @param ImageManager $imageManager
     * @param CacheStore<mixed> $cacheStore
     * @param ImageSecurityValidator $validator
     * @param LoggerInterface $logger
     */
    public static function createImageProcessor(
        ImageManager $imageManager,
        CacheStore $cacheStore,
        ImageSecurityValidator $validator,
        LoggerInterface $logger
    ): ImageProcessor {
        $config = self::getImageProcessorConfig();

        return new ImageProcessor(
            $imageManager,
            $cacheStore,
            $validator,
            $logger,
            $config
        );
    }

    /**
     * Create GD driver instance
     *
     * @return GdDriver
     */
    private static function createGdDriver(): GdDriver
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
    private static function createImagickDriver(): GdDriver|ImagickDriver
    {
        // Check if ImageMagick extension is available
        if (!extension_loaded('imagick')) {
            // Fall back to GD if ImageMagick is not available
            return self::createGdDriver();
        }

        // Verify ImageMagick is functional
        if (!class_exists('\Imagick')) {
            return self::createGdDriver();
        }

        return new ImagickDriver();
    }

    /**
     * Get image processor configuration
     *
     * @return array<string, mixed> Configuration array
     */
    private static function getImageProcessorConfig(): array
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
     * Get services provided by this provider
     *
     * @return array<int, string>
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

    /**
     * Get the provider name for debugging
     */
    public function getName(): string
    {
        return 'image';
    }

    /**
     * Boot services after container is built
     */
    public function boot(Container $container): void
    {
        // Nothing to boot for image service
    }

    /**
     * Get compiler passes for image services
     */
    public function getCompilerPasses(): array
    {
        return [];
    }
}

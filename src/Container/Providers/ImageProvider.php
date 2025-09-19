<?php

declare(strict_types=1);

namespace Glueful\Container\Providers;

use Glueful\Container\Definition\{DefinitionInterface, FactoryDefinition, AliasDefinition};

final class ImageProvider extends BaseServiceProvider
{
    /**
     * @return array<string, DefinitionInterface|callable|mixed>
     */
    public function defs(): array
    {
        $defs = [];

        // Intervention ImageManager with driver selection
        $defs[\Intervention\Image\ImageManager::class] = new FactoryDefinition(
            \Intervention\Image\ImageManager::class,
            function () {
                $driverType = function_exists('config') ? (string) config('image.driver', 'gd') : 'gd';
                $driver = match ($driverType) {
                    'imagick' => (function () {
                        if (!extension_loaded('imagick') || !class_exists('\\Imagick')) {
                            // fallback to GD
                            if (!extension_loaded('gd')) {
                                throw new \RuntimeException('No image driver available');
                            }
                            return new \Intervention\Image\Drivers\Gd\Driver();
                        }
                        return new \Intervention\Image\Drivers\Imagick\Driver();
                    })(),
                    default => (function () {
                        if (!extension_loaded('gd')) {
                            throw new \RuntimeException('GD extension not loaded');
                        }
                        return new \Intervention\Image\Drivers\Gd\Driver();
                    })(),
                };
                return new \Intervention\Image\ImageManager($driver);
            }
        );

        // Image security validator
        $defs[\Glueful\Services\ImageSecurityValidator::class] = new FactoryDefinition(
            \Glueful\Services\ImageSecurityValidator::class,
            function () {
                $security = function_exists('config') ? (array) config('image.security', []) : [];
                $limits = function_exists('config') ? (array) config('image.limits', []) : [];
                return new \Glueful\Services\ImageSecurityValidator(array_merge($security, $limits));
            }
        );

        // Image processor interface and alias
        $defs[\Glueful\Services\ImageProcessorInterface::class] = new FactoryDefinition(
            \Glueful\Services\ImageProcessorInterface::class,
            function (\Psr\Container\ContainerInterface $c) {
                $config = [
                    'optimization' => function_exists('config') ? (array) config('image.optimization', []) : [],
                    'security' => function_exists('config') ? (array) config('image.security', []) : [],
                    'cache' => function_exists('config') ? (array) config('image.cache', []) : [],
                    'features' => function_exists('config') ? (array) config('image.features', []) : [],
                    'defaults' => function_exists('config') ? (array) config('image.defaults', []) : [],
                    'performance' => function_exists('config') ? (array) config('image.performance', []) : [],
                    'monitoring' => function_exists('config') ? (array) config('image.monitoring', []) : [],
                ];

                return new \Glueful\Services\ImageProcessor(
                    $c->get(\Intervention\Image\ImageManager::class),
                    $c->get('cache.store'),
                    $c->get(\Glueful\Services\ImageSecurityValidator::class),
                    $c->get(\Psr\Log\LoggerInterface::class),
                    $config
                );
            }
        );
        $defs[\Glueful\Services\ImageProcessor::class] = new AliasDefinition(
            \Glueful\Services\ImageProcessor::class,
            \Glueful\Services\ImageProcessorInterface::class
        );

        return $defs;
    }
}

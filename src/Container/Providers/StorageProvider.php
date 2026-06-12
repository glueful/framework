<?php

declare(strict_types=1);

namespace Glueful\Container\Providers;

use Glueful\Container\Definition\DefinitionInterface;
use Glueful\Container\Definition\FactoryDefinition;
use Glueful\Container\Definition\AliasDefinition;
use Glueful\Storage\Contracts\StorageDriverRegistryInterface;
use Glueful\Storage\StorageDriverRegistry;

final class StorageProvider extends BaseServiceProvider
{
    /**
     * @return array<string, DefinitionInterface|callable|mixed>
     */
    public function defs(): array
    {
        $defs = [];

        // PathGuard (configurable via storage config path_guard if present)
        $defs[\Glueful\Storage\PathGuard::class] = new FactoryDefinition(
            \Glueful\Storage\PathGuard::class,
            function (): \Glueful\Storage\PathGuard {
                /** @var array<string,mixed> $cfg */
                $cfg = (array) (\function_exists('config') ? \config($this->context, 'storage.path_guard', []) : []);
                return new \Glueful\Storage\PathGuard($cfg);
            }
        );

        // Storage driver registry: built-ins first, then tagged extension factories.
        // TaggedIteratorDefinition yields higher priorities first; registering
        // the reversed list preserves registry last-wins semantics for priority.
        // Equal-priority ties should not be used for same-driver overrides.
        $defs[StorageDriverRegistryInterface::class] = new FactoryDefinition(
            StorageDriverRegistryInterface::class,
            function (\Psr\Container\ContainerInterface $c): StorageDriverRegistryInterface {
                $logger = $c->has(\Psr\Log\LoggerInterface::class)
                    ? $c->get(\Psr\Log\LoggerInterface::class)
                    : null;

                $registry = StorageDriverRegistry::withBuiltIns($logger);

                if ($c->has('storage.driver_factory')) {
                    /** @var iterable<\Glueful\Storage\Contracts\StorageDriverFactoryInterface> $factories */
                    $factories = $c->get('storage.driver_factory');
                    if ($factories instanceof \Traversable) {
                        $factories = iterator_to_array($factories);
                    }

                    foreach (array_reverse((array) $factories) as $factory) {
                        $registry->register($factory->driver(), $factory);
                    }
                }

                return $registry;
            }
        );

        // StorageManager built from config/storage.php
        $defs[\Glueful\Storage\StorageManager::class] = new FactoryDefinition(
            \Glueful\Storage\StorageManager::class,
            function (\Psr\Container\ContainerInterface $c): \Glueful\Storage\StorageManager {
                /** @var array<string,mixed> $cfg */
                $cfg = (array) (\function_exists('config') ? \config($this->context, 'storage') : []);
                return new \Glueful\Storage\StorageManager(
                    $cfg,
                    new \Glueful\Storage\PathGuard(),
                    $c->get(StorageDriverRegistryInterface::class)
                );
            }
        );

        // Url generator for public links
        $defs[\Glueful\Storage\Support\UrlGenerator::class] = new FactoryDefinition(
            \Glueful\Storage\Support\UrlGenerator::class,
            function (): \Glueful\Storage\Support\UrlGenerator {
                /** @var array<string,mixed> $cfg */
                $cfg = (array) (\function_exists('config') ? \config($this->context, 'storage') : []);
                return new \Glueful\Storage\Support\UrlGenerator($cfg, new \Glueful\Storage\PathGuard());
            }
        );

        // String alias for convenience
        $defs['storage'] = new AliasDefinition('storage', \Glueful\Storage\StorageManager::class);

        // FileUploader with config-driven defaults
        $defs[\Glueful\Uploader\FileUploader::class] = new FactoryDefinition(
            \Glueful\Uploader\FileUploader::class,
            function (\Psr\Container\ContainerInterface $c): \Glueful\Uploader\FileUploader {
                $uploadsDir = (string) (\function_exists('config')
                    ? \config($this->context, 'uploads.path_prefix', 'uploads')
                    : 'uploads');
                $cdnBaseUrl = (string) (\function_exists('config')
                    ? \config($this->context, 'uploads.cdn_base_url', '')
                    : '');
                $disk = (string) (\function_exists('config')
                    ? \config($this->context, 'uploads.disk', 'uploads')
                    : 'uploads');

                // Optional rich-media seam — bound only by the glueful/media
                // extension. Absent → FileUploader uses its no-op fallback.
                $media = $c->has(\Glueful\Uploader\Contracts\MediaProcessorInterface::class)
                    ? $c->get(\Glueful\Uploader\Contracts\MediaProcessorInterface::class)
                    : null;

                return new \Glueful\Uploader\FileUploader(
                    $uploadsDir,
                    $cdnBaseUrl,
                    $disk,
                    $this->context,
                    $media
                );
            }
        );

        // Image security validator (kept resolvable after ImageProvider removal;
        // reads image.security/image.limits which the media extension merges in,
        // defaulting to [] when the extension is absent)
        $defs[\Glueful\Services\ImageSecurityValidator::class] = new FactoryDefinition(
            \Glueful\Services\ImageSecurityValidator::class,
            function (): \Glueful\Services\ImageSecurityValidator {
                $security = \function_exists('config')
                    ? (array) \config($this->context, 'image.security', [])
                    : [];
                $limits = \function_exists('config')
                    ? (array) \config($this->context, 'image.limits', [])
                    : [];
                return new \Glueful\Services\ImageSecurityValidator(\array_merge($security, $limits));
            }
        );

        // UploadController
        $defs[\Glueful\Controllers\UploadController::class] = new FactoryDefinition(
            \Glueful\Controllers\UploadController::class,
            fn(\Psr\Container\ContainerInterface $c) => new \Glueful\Controllers\UploadController(
                $c->get(\Glueful\Bootstrap\ApplicationContext::class),
                $c->get(\Glueful\Uploader\FileUploader::class),
                $c->get(\Glueful\Repository\BlobRepository::class),
                $c->get(\Glueful\Storage\StorageManager::class),
                $c->get(\Glueful\Storage\Support\UrlGenerator::class),
                // Optional rich-media seam — bound only by the glueful/media
                // extension. Absent → on-demand variants fall back to serving
                // the original (or 415 for explicit format conversion).
                $c->has(\Glueful\Uploader\Contracts\MediaProcessorInterface::class)
                    ? $c->get(\Glueful\Uploader\Contracts\MediaProcessorInterface::class)
                    : null,
                $c->get(\Glueful\Services\ImageSecurityValidator::class)
            )
        );

        return $defs;
    }
}

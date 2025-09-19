<?php

declare(strict_types=1);

namespace Glueful\Container\Providers;

use Glueful\Container\Definition\{DefinitionInterface, FactoryDefinition};

final class SpaProvider extends BaseServiceProvider
{
    /**
     * @return array<string, DefinitionInterface|callable|mixed>
     */
    public function defs(): array
    {
        $defs = [];

        $defs[\Glueful\Helpers\StaticFileDetector::class] = new FactoryDefinition(
            \Glueful\Helpers\StaticFileDetector::class,
            function () {
                return new \Glueful\Helpers\StaticFileDetector(
                    config: [
                        'extensions' => [
                            'css','js','map','json','txt','xml',
                            'png','jpg','jpeg','gif','svg','webp','avif','ico','bmp','tiff',
                            'woff','woff2','ttf','eot','otf',
                            'mp4','webm','ogg','mp3','wav','flac',
                            'pdf','zip','tar','gz',
                            'manifest','webmanifest','robots'
                        ],
                        'mime_types' => [
                            'text/css','application/javascript','text/javascript','image/','font/','audio/','video/',
                            'application/font','application/octet-stream'
                        ],
                        'cache_enabled' => true,
                        'cache_size' => 1000,
                    ]
                );
            }
        );

        $defs[\Glueful\Extensions\SpaManager::class] = new FactoryDefinition(
            \Glueful\Extensions\SpaManager::class,
            fn(\Psr\Container\ContainerInterface $c) => new \Glueful\Extensions\SpaManager(
                $c->get(\Psr\Log\LoggerInterface::class),
                $c->get(\Glueful\Helpers\StaticFileDetector::class)
            )
        );

        return $defs;
    }
}

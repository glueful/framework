<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Framework;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Bootstrap\ContainerFactory;
use Glueful\Services\ImageSecurityValidator;
use Glueful\Uploader\Contracts\MediaProcessorInterface;
use Psr\Container\ContainerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Core-only boot gate (Phase B / G1-G2): after delisting ImageProvider and
 * removing the ImageProcessor::setContext() boot poke, the core container must
 * build WITHOUT ever touching the rich-media graph.
 *
 * The concrete ImageProcessor/ImageProvider classes and the heavy deps still
 * exist in core at this phase (deleted only in C1), so this test is purely
 * structural — it proves the wiring no longer pulls them in.
 */
final class CoreBootWithoutMediaTest extends TestCase
{
    private function buildCoreContainer(): ContainerInterface
    {
        $context = ApplicationContext::forTesting(dirname(__DIR__, 3));

        // Build the full core container the way the framework does at boot.
        return ContainerFactory::create($context, false);
    }

    public function test_core_container_builds_without_media_graph(): void
    {
        // No fatal on boot now that ImageProvider is delisted and the
        // ImageProcessor::setContext() poke is gone.
        $container = $this->buildCoreContainer();

        $this->assertInstanceOf(ContainerInterface::class, $container);
    }

    public function test_image_security_validator_still_resolves(): void
    {
        // The relocated A2 binding lives in StorageProvider, so the validator
        // stays resolvable even though ImageProvider no longer registers it.
        $container = $this->buildCoreContainer();

        $validator = $container->get(ImageSecurityValidator::class);

        $this->assertInstanceOf(ImageSecurityValidator::class, $validator);
    }

    public function test_media_processor_interface_not_bound_by_core(): void
    {
        // Core must NOT bind the rich-media seam — that is the glueful/media
        // extension's job. FileUploader/UploadController fall back to no-op.
        $container = $this->buildCoreContainer();

        $this->assertFalse(
            $container->has(MediaProcessorInterface::class),
            'Core must not bind MediaProcessorInterface — it is owned by the media extension.'
        );
    }

    public function test_core_does_not_register_rich_media_classes(): void
    {
        // After ImageProvider is delisted, the rich-media classes it used to
        // register (the concrete ImageProcessor graph + Intervention's
        // ImageManager) are no longer wired into the core container. These move
        // to the glueful/media extension's provider in Phase D.
        $container = $this->buildCoreContainer();

        $this->assertFalse(
            $container->has(\Glueful\Services\ImageProcessorInterface::class),
            'Core must not register ImageProcessorInterface after ImageProvider removal.'
        );
        $this->assertFalse(
            $container->has(\Glueful\Services\ImageProcessor::class),
            'Core must not register the concrete ImageProcessor after ImageProvider removal.'
        );
        $this->assertFalse(
            $container->has(\Intervention\Image\ImageManager::class),
            'Core must not register Intervention\\Image\\ImageManager after ImageProvider removal.'
        );
    }
}

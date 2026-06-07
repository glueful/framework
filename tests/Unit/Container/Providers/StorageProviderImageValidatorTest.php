<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Container\Providers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Container;
use Glueful\Container\Providers\StorageProvider;
use Glueful\Container\Providers\TagCollector;
use Glueful\Services\ImageSecurityValidator;
use PHPUnit\Framework\TestCase;

/**
 * Ensures the ImageSecurityValidator binding survives the removal of ImageProvider
 * by being bound in StorageProvider (which owns the upload/storage graph).
 *
 * StorageProvider is built in ISOLATION here so the assertion genuinely depends on
 * StorageProvider's own defs() — not on ImageProvider also binding the validator.
 */
final class StorageProviderImageValidatorTest extends TestCase
{
    public function testStorageProviderBindsImageSecurityValidator(): void
    {
        $context = ApplicationContext::forTesting(dirname(__DIR__, 4));
        $provider = new StorageProvider(new TagCollector(), $context);

        $container = new Container($provider->defs());

        $validator = $container->get(ImageSecurityValidator::class);

        $this->assertInstanceOf(ImageSecurityValidator::class, $validator);
    }
}

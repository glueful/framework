<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceProviders;

use Glueful\DI\ServiceProviderInterface;
use Glueful\DI\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class ValidationServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerBuilder $container): void
    {
        // Bind Glueful Validation contracts to the lightweight Validator
        $container->register(\Glueful\Validation\Contracts\ValidatorInterface::class, \Glueful\Validation\Validator::class)
            ->setPublic(true);
    }

    public function boot(Container $container): void
    {
        // No boot-time wiring needed for validation
    }

    public function getCompilerPasses(): array
    {
        return [];
    }

    public function getName(): string
    {
        return 'validation';
    }
}


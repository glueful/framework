<?php

declare(strict_types=1);

namespace Glueful\Tests\Fixtures\ContainerPrecedence;

use Glueful\Auth\Contracts\UserProviderInterface;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Definition\FactoryDefinition;
use Psr\Container\ContainerInterface;

/**
 * Fixture app provider for the precedence regression. It attempts to override:
 *  - UserProviderInterface  -- a real core seam; the override SHOULD win.
 *  - ApplicationContext     -- a reserved key; the override SHOULD be ignored (re-pinned).
 */
final class SeamOverrideProvider
{
    /** @return array<string,mixed> */
    public static function defs(): array
    {
        return [
            UserProviderInterface::class => new FactoryDefinition(
                UserProviderInterface::class,
                static fn(ContainerInterface $c) => new FakeUserProvider()
            ),
            ApplicationContext::class => new FactoryDefinition(
                ApplicationContext::class,
                static fn(ContainerInterface $c) => 'CLOBBERED'
            ),
        ];
    }
}

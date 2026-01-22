<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Api\Versioning\Resolvers;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Glueful\Api\Versioning\Resolvers\HeaderResolver;
use Symfony\Component\HttpFoundation\Request;

class HeaderResolverTest extends TestCase
{
    #[Test]
    public function resolvesVersionFromHeader(): void
    {
        $resolver = new HeaderResolver('X-Api-Version');
        $request = Request::create('/users');
        $request->headers->set('X-Api-Version', '2');

        $version = $resolver->resolve($request);

        $this->assertNotNull($version);
        $this->assertEquals('2', $version->major);
    }

    #[Test]
    public function resolvesVersionWithVPrefix(): void
    {
        $resolver = new HeaderResolver('X-Api-Version');
        $request = Request::create('/users');
        $request->headers->set('X-Api-Version', 'v2');

        $version = $resolver->resolve($request);

        $this->assertNotNull($version);
        $this->assertEquals('2', $version->major);
    }

    #[Test]
    public function resolvesVersionWithMinor(): void
    {
        $resolver = new HeaderResolver('X-Api-Version');
        $request = Request::create('/users');
        $request->headers->set('X-Api-Version', '2.1');

        $version = $resolver->resolve($request);

        $this->assertNotNull($version);
        $this->assertEquals('2', $version->major);
        $this->assertEquals('1', $version->minor);
    }

    #[Test]
    public function returnsNullWhenHeaderMissing(): void
    {
        $resolver = new HeaderResolver('X-Api-Version');
        $request = Request::create('/users');

        $version = $resolver->resolve($request);

        $this->assertNull($version);
    }

    #[Test]
    public function returnsNullWhenHeaderEmpty(): void
    {
        $resolver = new HeaderResolver('X-Api-Version');
        $request = Request::create('/users');
        $request->headers->set('X-Api-Version', '');

        $version = $resolver->resolve($request);

        $this->assertNull($version);
    }

    #[Test]
    public function usesCustomHeaderName(): void
    {
        $resolver = new HeaderResolver('Api-Version');
        $request = Request::create('/users');
        $request->headers->set('Api-Version', '3');

        $version = $resolver->resolve($request);

        $this->assertNotNull($version);
        $this->assertEquals('3', $version->major);
    }

    #[Test]
    public function getPriorityReturnsConfiguredValue(): void
    {
        $resolver = new HeaderResolver('X-Api-Version', 90);

        $this->assertEquals(90, $resolver->getPriority());
    }

    #[Test]
    public function getNameReturnsHeader(): void
    {
        $resolver = new HeaderResolver();

        $this->assertEquals('header', $resolver->getName());
    }
}

<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Api\Versioning\Resolvers;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Glueful\Api\Versioning\Resolvers\QueryParameterResolver;
use Symfony\Component\HttpFoundation\Request;

class QueryParameterResolverTest extends TestCase
{
    #[Test]
    public function resolvesVersionFromQueryParameter(): void
    {
        $resolver = new QueryParameterResolver('api-version');
        $request = Request::create('/users?api-version=2');

        $version = $resolver->resolve($request);

        $this->assertNotNull($version);
        $this->assertEquals('2', $version->major);
    }

    #[Test]
    public function resolvesVersionWithVPrefix(): void
    {
        $resolver = new QueryParameterResolver('api-version');
        $request = Request::create('/users?api-version=v2');

        $version = $resolver->resolve($request);

        $this->assertNotNull($version);
        $this->assertEquals('2', $version->major);
    }

    #[Test]
    public function resolvesVersionWithMinor(): void
    {
        $resolver = new QueryParameterResolver('api-version');
        $request = Request::create('/users?api-version=2.1');

        $version = $resolver->resolve($request);

        $this->assertNotNull($version);
        $this->assertEquals('2', $version->major);
        $this->assertEquals('1', $version->minor);
    }

    #[Test]
    public function returnsNullWhenParameterMissing(): void
    {
        $resolver = new QueryParameterResolver('api-version');
        $request = Request::create('/users');

        $version = $resolver->resolve($request);

        $this->assertNull($version);
    }

    #[Test]
    public function returnsNullWhenParameterEmpty(): void
    {
        $resolver = new QueryParameterResolver('api-version');
        $request = Request::create('/users?api-version=');

        $version = $resolver->resolve($request);

        $this->assertNull($version);
    }

    #[Test]
    public function usesCustomParameterName(): void
    {
        $resolver = new QueryParameterResolver('version');
        $request = Request::create('/users?version=3');

        $version = $resolver->resolve($request);

        $this->assertNotNull($version);
        $this->assertEquals('3', $version->major);
    }

    #[Test]
    public function getPriorityReturnsConfiguredValue(): void
    {
        $resolver = new QueryParameterResolver('api-version', 70);

        $this->assertEquals(70, $resolver->getPriority());
    }

    #[Test]
    public function getNameReturnsQueryParameter(): void
    {
        $resolver = new QueryParameterResolver();

        $this->assertEquals('query_parameter', $resolver->getName());
    }
}

<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Api\Versioning\Resolvers;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Glueful\Api\Versioning\Resolvers\AcceptHeaderResolver;
use Symfony\Component\HttpFoundation\Request;

class AcceptHeaderResolverTest extends TestCase
{
    #[Test]
    public function resolvesVersionFromAcceptHeader(): void
    {
        $resolver = new AcceptHeaderResolver('glueful');
        $request = Request::create('/users');
        $request->headers->set('Accept', 'application/vnd.glueful.v2+json');

        $version = $resolver->resolve($request);

        $this->assertNotNull($version);
        $this->assertEquals('2', $version->major);
    }

    #[Test]
    public function resolvesVersionWithMinor(): void
    {
        $resolver = new AcceptHeaderResolver('glueful');
        $request = Request::create('/users');
        $request->headers->set('Accept', 'application/vnd.glueful.v2.1+json');

        $version = $resolver->resolve($request);

        $this->assertNotNull($version);
        $this->assertEquals('2', $version->major);
        $this->assertEquals('1', $version->minor);
    }

    #[Test]
    public function resolvesVersionWithFullSemver(): void
    {
        $resolver = new AcceptHeaderResolver('glueful');
        $request = Request::create('/users');
        $request->headers->set('Accept', 'application/vnd.glueful.v2.1.3+json');

        $version = $resolver->resolve($request);

        $this->assertNotNull($version);
        $this->assertEquals('2', $version->major);
        $this->assertEquals('1', $version->minor);
        $this->assertEquals('3', $version->patch);
    }

    #[Test]
    public function resolvesVersionWithXmlFormat(): void
    {
        $resolver = new AcceptHeaderResolver('glueful');
        $request = Request::create('/users');
        $request->headers->set('Accept', 'application/vnd.glueful.v3+xml');

        $version = $resolver->resolve($request);

        $this->assertNotNull($version);
        $this->assertEquals('3', $version->major);
    }

    #[Test]
    public function returnsNullWhenHeaderMissing(): void
    {
        $resolver = new AcceptHeaderResolver('glueful');
        $request = Request::create('/users');

        $version = $resolver->resolve($request);

        $this->assertNull($version);
    }

    #[Test]
    public function returnsNullWhenHeaderDoesNotMatchVendor(): void
    {
        $resolver = new AcceptHeaderResolver('glueful');
        $request = Request::create('/users');
        $request->headers->set('Accept', 'application/vnd.other.v2+json');

        $version = $resolver->resolve($request);

        $this->assertNull($version);
    }

    #[Test]
    public function returnsNullWhenHeaderIsPlainJson(): void
    {
        $resolver = new AcceptHeaderResolver('glueful');
        $request = Request::create('/users');
        $request->headers->set('Accept', 'application/json');

        $version = $resolver->resolve($request);

        $this->assertNull($version);
    }

    #[Test]
    public function usesCustomVendorName(): void
    {
        $resolver = new AcceptHeaderResolver('myapi');
        $request = Request::create('/users');
        $request->headers->set('Accept', 'application/vnd.myapi.v4+json');

        $version = $resolver->resolve($request);

        $this->assertNotNull($version);
        $this->assertEquals('4', $version->major);
    }

    #[Test]
    public function getPriorityReturnsConfiguredValue(): void
    {
        $resolver = new AcceptHeaderResolver('glueful', 85);

        $this->assertEquals(85, $resolver->getPriority());
    }

    #[Test]
    public function getNameReturnsAcceptHeader(): void
    {
        $resolver = new AcceptHeaderResolver();

        $this->assertEquals('accept_header', $resolver->getName());
    }

    #[Test]
    public function matchesCaseInsensitive(): void
    {
        $resolver = new AcceptHeaderResolver('glueful');
        $request = Request::create('/users');
        $request->headers->set('Accept', 'APPLICATION/VND.GLUEFUL.V2+JSON');

        $version = $resolver->resolve($request);

        $this->assertNotNull($version);
        $this->assertEquals('2', $version->major);
    }
}

<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Api\Versioning\Resolvers;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use Glueful\Api\Versioning\Resolvers\UrlPrefixResolver;
use Symfony\Component\HttpFoundation\Request;

class UrlPrefixResolverTest extends TestCase
{
    #[Test]
    public function resolvesVersionFromApiV1Path(): void
    {
        $resolver = new UrlPrefixResolver('/api');
        $request = Request::create('/api/v1/users');

        $version = $resolver->resolve($request);

        $this->assertNotNull($version);
        $this->assertEquals('1', $version->major);
    }

    #[Test]
    public function resolvesVersionFromApiV2Path(): void
    {
        $resolver = new UrlPrefixResolver('/api');
        $request = Request::create('/api/v2/posts');

        $version = $resolver->resolve($request);

        $this->assertNotNull($version);
        $this->assertEquals('2', $version->major);
    }

    #[Test]
    public function resolvesVersionWithMinor(): void
    {
        $resolver = new UrlPrefixResolver('/api');
        $request = Request::create('/api/v1.2/users');

        $version = $resolver->resolve($request);

        $this->assertNotNull($version);
        $this->assertEquals('1', $version->major);
        $this->assertEquals('2', $version->minor);
    }

    #[Test]
    public function resolvesVersionWithFullSemver(): void
    {
        $resolver = new UrlPrefixResolver('/api');
        $request = Request::create('/api/v1.2.3/users');

        $version = $resolver->resolve($request);

        $this->assertNotNull($version);
        $this->assertEquals('1', $version->major);
        $this->assertEquals('2', $version->minor);
        $this->assertEquals('3', $version->patch);
    }

    #[Test]
    public function returnsNullForPathWithoutVersion(): void
    {
        $resolver = new UrlPrefixResolver('/api');
        $request = Request::create('/api/users');

        $version = $resolver->resolve($request);

        $this->assertNull($version);
    }

    #[Test]
    public function returnsNullForDifferentPrefix(): void
    {
        $resolver = new UrlPrefixResolver('/api');
        $request = Request::create('/v1/users');

        $version = $resolver->resolve($request);

        $this->assertNull($version);
    }

    #[Test]
    public function resolvesVersionWithEmptyPrefix(): void
    {
        $resolver = new UrlPrefixResolver('');
        $request = Request::create('/v1/users');

        $version = $resolver->resolve($request);

        $this->assertNotNull($version);
        $this->assertEquals('1', $version->major);
    }

    #[Test]
    public function resolvesVersionCaseInsensitive(): void
    {
        // The regex pattern is case-insensitive for the version prefix (v/V)
        $resolver = new UrlPrefixResolver('/api');
        $request = Request::create('/api/V1/users'); // uppercase V

        $version = $resolver->resolve($request);

        $this->assertNotNull($version);
        $this->assertEquals('1', $version->major);
    }

    #[Test]
    public function getPriorityReturnsConfiguredValue(): void
    {
        $resolver = new UrlPrefixResolver('/api', 150);

        $this->assertEquals(150, $resolver->getPriority());
    }

    #[Test]
    public function getNameReturnsUrlPrefix(): void
    {
        $resolver = new UrlPrefixResolver();

        $this->assertEquals('url_prefix', $resolver->getName());
    }

    #[Test]
    #[DataProvider('validPathsProvider')]
    public function resolvesVersionFromValidPaths(string $path, string $expectedMajor): void
    {
        $resolver = new UrlPrefixResolver('/api');
        $request = Request::create($path);

        $version = $resolver->resolve($request);

        $this->assertNotNull($version);
        $this->assertEquals($expectedMajor, $version->major);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function validPathsProvider(): array
    {
        return [
            'v1 users' => ['/api/v1/users', '1'],
            'v2 posts' => ['/api/v2/posts', '2'],
            'v10 items' => ['/api/v10/items', '10'],
            'v1 at end' => ['/api/v1', '1'],
            'v1 with trailing slash' => ['/api/v1/', '1'],
        ];
    }
}

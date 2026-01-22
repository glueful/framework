<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Api\Versioning;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Glueful\Api\Versioning\VersionManager;
use Glueful\Api\Versioning\ApiVersion;
use Glueful\Api\Versioning\Resolvers\UrlPrefixResolver;
use Glueful\Api\Versioning\Resolvers\HeaderResolver;
use Symfony\Component\HttpFoundation\Request;

class VersionManagerTest extends TestCase
{
    #[Test]
    public function negotiateReturnsVersionFromResolver(): void
    {
        $manager = new VersionManager(ApiVersion::fromString('1'));
        $manager->registerResolver(new UrlPrefixResolver('/api'));

        $request = Request::create('/api/v2/users');
        $version = $manager->negotiate($request);

        $this->assertEquals('2', $version->major);
    }

    #[Test]
    public function negotiateReturnsDefaultWhenNoResolverMatches(): void
    {
        $manager = new VersionManager(ApiVersion::fromString('1'));
        $manager->registerResolver(new UrlPrefixResolver('/api'));

        $request = Request::create('/other/users');
        $version = $manager->negotiate($request);

        $this->assertEquals('1', $version->major);
    }

    #[Test]
    public function negotiateUsesResolverPriority(): void
    {
        $manager = new VersionManager(ApiVersion::fromString('1'));

        // Header has lower priority than URL by default
        $manager->registerResolver(new HeaderResolver('X-Api-Version', 80));
        $manager->registerResolver(new UrlPrefixResolver('/api', 100));

        // Both URL and header specify version, URL should win
        $request = Request::create('/api/v2/users');
        $request->headers->set('X-Api-Version', '3');

        $version = $manager->negotiate($request);

        $this->assertEquals('2', $version->major);
    }

    #[Test]
    public function negotiateFallsBackToLowerPriorityResolver(): void
    {
        $manager = new VersionManager(ApiVersion::fromString('1'));

        $manager->registerResolver(new HeaderResolver('X-Api-Version', 80));
        $manager->registerResolver(new UrlPrefixResolver('/api', 100));

        // URL doesn't have version, should fall back to header
        $request = Request::create('/api/users');
        $request->headers->set('X-Api-Version', '3');

        $version = $manager->negotiate($request);

        $this->assertEquals('3', $version->major);
    }

    #[Test]
    public function isSupportedReturnsTrueWhenNoVersionsConfigured(): void
    {
        $manager = new VersionManager(ApiVersion::fromString('1'));

        $version = ApiVersion::fromString('99');

        $this->assertTrue($manager->isSupported($version));
    }

    #[Test]
    public function isSupportedReturnsTrueForConfiguredVersion(): void
    {
        $manager = new VersionManager(ApiVersion::fromString('1'));
        $manager->registerSupportedVersion('1');
        $manager->registerSupportedVersion('2');

        $version = ApiVersion::fromString('2');

        $this->assertTrue($manager->isSupported($version));
    }

    #[Test]
    public function isSupportedReturnsFalseForUnconfiguredVersion(): void
    {
        $manager = new VersionManager(ApiVersion::fromString('1'));
        $manager->registerSupportedVersion('1');
        $manager->registerSupportedVersion('2');

        $version = ApiVersion::fromString('3');

        $this->assertFalse($manager->isSupported($version));
    }

    #[Test]
    public function isDeprecatedReturnsFalseByDefault(): void
    {
        $manager = new VersionManager(ApiVersion::fromString('1'));

        $version = ApiVersion::fromString('1');

        $this->assertFalse($manager->isDeprecated($version));
    }

    #[Test]
    public function isDeprecatedReturnsTrueForDeprecatedVersion(): void
    {
        $manager = new VersionManager(ApiVersion::fromString('1'));
        $manager->deprecateVersion('1');

        $version = ApiVersion::fromString('1');

        $this->assertTrue($manager->isDeprecated($version));
    }

    #[Test]
    public function getSunsetDateReturnsNullByDefault(): void
    {
        $manager = new VersionManager(ApiVersion::fromString('1'));

        $version = ApiVersion::fromString('1');

        $this->assertNull($manager->getSunsetDate($version));
    }

    #[Test]
    public function getSunsetDateReturnsConfiguredDate(): void
    {
        $manager = new VersionManager(ApiVersion::fromString('1'));
        $sunsetDate = new \DateTimeImmutable('2025-06-01');
        $manager->deprecateVersion('1', $sunsetDate);

        $version = ApiVersion::fromString('1');
        $sunset = $manager->getSunsetDate($version);

        $this->assertNotNull($sunset);
        $this->assertEquals('2025-06-01', $sunset->format('Y-m-d'));
    }

    #[Test]
    public function getDeprecationMessageReturnsConfiguredMessage(): void
    {
        $manager = new VersionManager(ApiVersion::fromString('1'));
        $manager->deprecateVersion('1', null, 'Please upgrade to v2');

        $version = ApiVersion::fromString('1');

        $this->assertEquals('Please upgrade to v2', $manager->getDeprecationMessage($version));
    }

    #[Test]
    public function getAlternativeUrlReturnsConfiguredUrl(): void
    {
        $manager = new VersionManager(ApiVersion::fromString('1'));
        $manager->deprecateVersion('1', null, null, '/v2');

        $version = ApiVersion::fromString('1');

        $this->assertEquals('/v2', $manager->getAlternativeUrl($version));
    }

    #[Test]
    public function fromConfigCreatesManagerWithDefaults(): void
    {
        $config = [
            'default' => '2',
            'supported' => ['1', '2', '3'],
            'deprecated' => [
                '1' => [
                    'sunset' => '2025-06-01',
                    'message' => 'Use v2',
                ],
            ],
            'resolvers' => ['url_prefix', 'header'],
        ];

        $manager = VersionManager::fromConfig($config);

        $this->assertEquals('2', $manager->getDefaultVersion()->major);
        $this->assertEquals(['1', '2', '3'], $manager->getSupportedVersions());
        $this->assertTrue($manager->isDeprecated(ApiVersion::fromString('1')));
        $this->assertFalse($manager->isDeprecated(ApiVersion::fromString('2')));
    }

    #[Test]
    public function getSupportedVersionsReturnsRegisteredVersions(): void
    {
        $manager = new VersionManager(ApiVersion::fromString('1'));
        $manager->registerSupportedVersion('1');
        $manager->registerSupportedVersion('2');

        $this->assertEquals(['1', '2'], $manager->getSupportedVersions());
    }

    #[Test]
    public function getDeprecatedVersionsReturnsOnlyDeprecated(): void
    {
        $manager = new VersionManager(ApiVersion::fromString('1'));
        $manager->deprecateVersion('1', new \DateTimeImmutable('2025-06-01'));

        $deprecated = $manager->getDeprecatedVersions();

        $this->assertArrayHasKey('1', $deprecated);
        $this->assertTrue($deprecated['1']['deprecated']);
    }

    #[Test]
    public function strictModeRejectsUnsupportedVersions(): void
    {
        $manager = new VersionManager(ApiVersion::fromString('1'), strictMode: true);
        $manager->registerSupportedVersion('1');
        $manager->registerSupportedVersion('2');
        $manager->registerResolver(new UrlPrefixResolver('/api'));

        // Request version 3 which is not supported
        $request = Request::create('/api/v3/users');
        $version = $manager->negotiate($request);

        // Should fall back to default since v3 is not supported
        $this->assertEquals('1', $version->major);
    }

    #[Test]
    public function nonStrictModeAcceptsAnyVersion(): void
    {
        $manager = new VersionManager(ApiVersion::fromString('1'), strictMode: false);
        $manager->registerSupportedVersion('1');
        $manager->registerSupportedVersion('2');
        $manager->registerResolver(new UrlPrefixResolver('/api'));

        // Request version 3 which is not in supported list
        $request = Request::create('/api/v3/users');
        $version = $manager->negotiate($request);

        // Should accept v3 since strict mode is off
        $this->assertEquals('3', $version->major);
    }
}

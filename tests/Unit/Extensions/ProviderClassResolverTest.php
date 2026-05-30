<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\ConfigurationLoader;
use Glueful\Extensions\ProviderClassResolver;
use Glueful\Extensions\ResolverError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProviderClassResolver::class)]
final class ProviderClassResolverTest extends TestCase
{
    /**
     * Build a temp app dir with config + installed.php and a wired config loader.
     *
     * @param list<string> $appProviders
     * @param list<string> $enabledExtensions
     * @param array<string, array<string, mixed>> $installedVersions
     */
    private function ctx(array $appProviders, array $enabledExtensions, array $installedVersions): ApplicationContext
    {
        $base = sys_get_temp_dir() . '/glueful-pcr-' . uniqid('', true);
        @mkdir($base . '/config', 0777, true);
        @mkdir($base . '/vendor/composer', 0777, true);

        file_put_contents(
            $base . '/config/serviceproviders.php',
            "<?php\nreturn " . var_export(['enabled' => $appProviders], true) . ";\n"
        );
        file_put_contents(
            $base . '/config/extensions.php',
            "<?php\nreturn " . var_export(['enabled' => $enabledExtensions], true) . ";\n"
        );
        file_put_contents(
            $base . '/vendor/composer/installed.php',
            "<?php\nreturn " . var_export(['versions' => $installedVersions], true) . ";\n"
        );

        $ctx = new ApplicationContext($base, 'testing');
        $ctx->setConfigLoader(new ConfigurationLoader($base, 'testing', $base . '/config'));
        return $ctx;
    }

    public function testAppProvidersComeFirstThenEnabledExtensions(): void
    {
        $ctx = $this->ctx(
            appProviders: ['App\\Providers\\AppServiceProvider'],
            enabledExtensions: ['Vendor\\Ext\\Provider'],
            installedVersions: [
                'vendor/ext' => [
                    'type' => 'glueful-extension',
                    'extra' => ['glueful' => ['provider' => 'Vendor\\Ext\\Provider']],
                ],
            ],
        );

        $result = (new ProviderClassResolver())->resolve($ctx);

        $this->assertSame(
            ['App\\Providers\\AppServiceProvider', 'Vendor\\Ext\\Provider'],
            $result->providers
        );
        $this->assertSame([], $result->errors);
    }

    public function testMissingEnabledExtensionSurfacesError(): void
    {
        $ctx = $this->ctx(
            appProviders: [],
            enabledExtensions: ['Vendor\\Ghost\\Provider'],
            installedVersions: [],
        );

        $result = (new ProviderClassResolver())->resolve($ctx);

        $this->assertSame([], $result->providers);
        $this->assertCount(1, $result->errors);
        $this->assertSame(ResolverError::MISSING_PROVIDER, $result->errors[0]->kind);
    }

    public function testEmptyConfigYieldsEmptyProviders(): void
    {
        $ctx = $this->ctx(appProviders: [], enabledExtensions: [], installedVersions: []);
        $result = (new ProviderClassResolver())->resolve($ctx);
        $this->assertSame([], $result->providers);
        $this->assertSame([], $result->errors);
    }
}

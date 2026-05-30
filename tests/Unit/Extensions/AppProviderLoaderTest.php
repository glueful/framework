<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\ConfigurationLoader;
use Glueful\Extensions\AppProviderLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AppProviderLoader::class)]
final class AppProviderLoaderTest extends TestCase
{
    /** @param array<string, mixed> $serviceproviders */
    private function ctxWithConfig(array $serviceproviders): ApplicationContext
    {
        $base = sys_get_temp_dir() . '/glueful-app-' . uniqid('', true);
        @mkdir($base . '/config', 0777, true);
        file_put_contents(
            $base . '/config/serviceproviders.php',
            "<?php\nreturn " . var_export($serviceproviders, true) . ";\n"
        );
        // config() reads files only once a loader is attached (see Conventions).
        $ctx = new ApplicationContext($base, 'testing');
        $ctx->setConfigLoader(new ConfigurationLoader($base, 'testing', $base . '/config'));
        return $ctx;
    }

    public function testReturnsEnabledInOrder(): void
    {
        $ctx = $this->ctxWithConfig(['enabled' => [
            'App\\Providers\\AppServiceProvider',
            'App\\Providers\\EventServiceProvider',
        ]]);
        $loader = new AppProviderLoader();
        $this->assertSame(
            ['App\\Providers\\AppServiceProvider', 'App\\Providers\\EventServiceProvider'],
            $loader->load($ctx)
        );
    }

    public function testEmptyWhenNoneConfigured(): void
    {
        $ctx = $this->ctxWithConfig([]);
        $this->assertSame([], (new AppProviderLoader())->load($ctx));
    }
}

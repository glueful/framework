<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Container;

use Glueful\Auth\Contracts\UserProviderInterface;
use Glueful\Auth\NullUserProvider;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\ConfigurationLoader;
use Glueful\Container\Bootstrap\ContainerFactory;
use Glueful\Tests\Fixtures\ContainerPrecedence\FakeUserProvider;
use Glueful\Tests\Fixtures\ContainerPrecedence\SeamOverrideProvider;
use PHPUnit\Framework\TestCase;

final class ExtensionOverridesCoreDefaultTest extends TestCase
{
    private function contextWithProvider(string $providerFqcn): ApplicationContext
    {
        $base = sys_get_temp_dir() . '/glueful-precedence-' . uniqid('', true);
        @mkdir($base . '/config', 0777, true);
        file_put_contents(
            $base . '/config/serviceproviders.php',
            "<?php\nreturn " . var_export(['enabled' => [$providerFqcn]], true) . ";\n"
        );
        $ctx = new ApplicationContext($base, 'testing');
        $ctx->setConfigLoader(new ConfigurationLoader($base, 'testing', $base . '/config'));
        return $ctx;
    }

    public function test_extension_overrides_a_real_core_default(): void
    {
        $ctx = $this->contextWithProvider(SeamOverrideProvider::class);

        $container = ContainerFactory::create($ctx, false);

        $resolved = $container->get(UserProviderInterface::class);
        self::assertInstanceOf(FakeUserProvider::class, $resolved);      // extension override WINS
        self::assertNotInstanceOf(NullUserProvider::class, $resolved);   // core default LOST
    }

    public function test_reserved_application_context_key_cannot_be_clobbered(): void
    {
        $ctx = $this->contextWithProvider(SeamOverrideProvider::class);

        $container = ContainerFactory::create($ctx, false);

        // The fixture tried to bind ApplicationContext::class => 'CLOBBERED';
        // the post-merge re-pin must restore the real context instance.
        self::assertSame($ctx, $container->get(ApplicationContext::class));
    }
}

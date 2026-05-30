<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Extensions;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ExtensionManager;
use PHPUnit\Framework\TestCase;

final class ExtensionManagerResolveTest extends TestCase
{
    public function testResolveCombinesAppProvidersAndEnabledExtensions(): void
    {
        $base = sys_get_temp_dir() . '/glueful-em-' . uniqid('', true);
        @mkdir($base . '/config', 0777, true);
        @mkdir($base . '/vendor/composer', 0777, true);

        file_put_contents(
            $base . '/config/serviceproviders.php',
            "<?php\nreturn ['enabled' => ['App\\\\Providers\\\\AppServiceProvider']];\n"
        );
        file_put_contents(
            $base . '/config/extensions.php',
            "<?php\nreturn ['enabled' => ['Vendor\\\\Ext\\\\Provider']];\n"
        );
        file_put_contents(
            $base . '/vendor/composer/installed.php',
            "<?php\nreturn " . var_export([
                'versions' => [
                    'vendor/ext' => [
                        'type' => 'glueful-extension',
                        'extra' => ['glueful' => ['provider' => 'Vendor\\Ext\\Provider']],
                    ],
                ],
            ], true) . ";\n"
        );

        $ctx = new ApplicationContext($base, 'testing');
        $ctx->setConfigLoader(new \Glueful\Bootstrap\ConfigurationLoader($base, 'testing', $base . '/config'));
        $manager = $this->managerWithContext($ctx);

        $classes = $manager->resolveProviderClasses();

        $this->assertContains('App\\Providers\\AppServiceProvider', $classes);
        $this->assertContains('Vendor\\Ext\\Provider', $classes);
        // app provider precedes the extension
        $this->assertLessThan(
            array_search('Vendor\\Ext\\Provider', $classes, true),
            array_search('App\\Providers\\AppServiceProvider', $classes, true)
        );
        $this->assertSame([], $manager->getResolverErrors());
    }

    private function managerWithContext(ApplicationContext $ctx): ExtensionManager
    {
        $container = new class ($ctx) implements \Psr\Container\ContainerInterface {
            public function __construct(private ApplicationContext $ctx)
            {
            }

            public function get(string $id): mixed
            {
                if ($id === ApplicationContext::class) {
                    return $this->ctx;
                }
                throw new class ("no {$id}") extends \RuntimeException implements
                    \Psr\Container\NotFoundExceptionInterface {
                };
            }

            public function has(string $id): bool
            {
                return $id === ApplicationContext::class;
            }
        };
        return new ExtensionManager($container);
    }
}

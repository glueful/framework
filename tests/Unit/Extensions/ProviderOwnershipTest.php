<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\ConfigurationLoader;
use Glueful\Extensions\ExtensionManager;
use Glueful\Extensions\PackageManifest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Type-agnostic provider→package attribution (modules-not-extensions spec §8.2): ownership
 * comes from `extra.glueful.provider` across ALL installed packages regardless of type, so a
 * library-typed app-integrated module keeps `managed_by: <package>` instead of degrading to
 * `app`. Duplicate ownership is a fatal configuration error after FQCN normalization.
 */
final class ProviderOwnershipTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/glueful-ownership-' . uniqid('', true);
        mkdir($this->base . '/config', 0777, true);
        mkdir($this->base . '/vendor/composer', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (
            [
                '/vendor/composer/installed.json',
                '/vendor/composer',
                '/vendor',
                '/config',
                '',
            ] as $path
        ) {
            $full = $this->base . $path;
            if (is_file($full)) {
                unlink($full);
            } elseif (is_dir($full)) {
                @rmdir($full);
            }
        }
    }

    /** @param list<array<string, mixed>> $packages */
    private function context(array $packages): ApplicationContext
    {
        file_put_contents(
            $this->base . '/vendor/composer/installed.json',
            (string) json_encode(['packages' => $packages])
        );
        $context = new ApplicationContext($this->base, 'testing');
        $context->setConfigLoader(
            new ConfigurationLoader($this->base, 'testing', $this->base . '/config')
        );

        return $context;
    }

    public function testLibraryTypedPackageAttributesItsProvider(): void
    {
        $context = $this->context([[
            'name' => 'acme/module',
            'version' => '1.0.0',
            'type' => 'library',
            'extra' => ['glueful' => ['provider' => 'Acme\\Module\\ModuleServiceProvider']],
        ]]);

        $ownership = (new PackageManifest($context))->providerOwnership();
        self::assertSame('acme/module', $ownership['Acme\\Module\\ModuleServiceProvider']);
        // And candidacy stays type-filtered: a library is NOT an extension candidate.
        self::assertSame([], (new PackageManifest($context))->getCandidates());
    }

    public function testUnownedProviderFallsBackToApp(): void
    {
        $context = $this->context([]);
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static function (string $id) use ($context): mixed {
                if ($id === ApplicationContext::class) {
                    return $context;
                }
                throw new \RuntimeException("Unexpected service: {$id}");
            }
        );
        $manager = new ExtensionManager($container);

        $method = (new \ReflectionClass($manager))->getMethod('packageNameFor');
        $method->setAccessible(true);
        self::assertSame('app', $method->invoke($manager, 'App\\Providers\\LocalProvider'));
    }

    public function testDuplicateOwnershipIsFatalAfterFqcnNormalization(): void
    {
        // One declaration with a leading backslash, one without — normalization must unify
        // them BEFORE duplicate detection, never let the variant spelling slip through.
        $context = $this->context([
            [
                'name' => 'acme/first',
                'version' => '1.0.0',
                'type' => 'glueful-extension',
                'extra' => ['glueful' => ['provider' => 'Acme\\Shared\\Provider']],
            ],
            [
                'name' => 'acme/second',
                'version' => '1.0.0',
                'type' => 'library',
                'extra' => ['glueful' => ['provider' => '\\Acme\\Shared\\Provider']],
            ],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/acme\/first.*acme\/second/');
        (new PackageManifest($context))->providerOwnership();
    }
}

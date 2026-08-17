<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions\Schema;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\PackageManifest;
use Glueful\Extensions\Schema\DescriptorMode;
use Glueful\Extensions\Schema\DescriptorValidationException;
use PHPUnit\Framework\TestCase;

final class ManifestMigrationDescriptorsTest extends TestCase
{
    /** @param list<array<string, mixed>> $packages */
    private function manifest(array $packages): PackageManifest
    {
        $base = sys_get_temp_dir() . '/glueful-md-' . uniqid('', true);
        mkdir($base . '/vendor/composer', 0777, true);
        file_put_contents(
            $base . '/vendor/composer/installed.json',
            json_encode(['packages' => $packages], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        return new PackageManifest(new ApplicationContext($base));
    }

    /** @param array<string, mixed> $glueful */
    private function extensionPkg(array $glueful, string $type = 'glueful-extension'): array
    {
        return [
            'name' => 'acme/widgets',
            'type' => $type,
            'install-path' => '../acme/widgets',
            'extra' => ['glueful' => $glueful + ['provider' => 'Acme\\Widgets\\P']],
        ];
    }

    public function testDescriptorsAreProjectedWithModeAndPriority(): void
    {
        $m = $this->manifest([$this->extensionPkg(['migrations' => [
            [
                'id' => 'default',
                'path' => 'migrations',
                'priority' => 'dependent',
                'mode' => 'on_enable',
                'verifier' => 'Acme\\Widgets\\Schema\\WidgetsStructuralVerifier',
            ],
        ]])]);
        $d = $m->migrationDescriptors()['acme/widgets'][0];
        self::assertSame('acme/widgets', $d->source());
        self::assertSame(100, $d->priority);
        self::assertSame(DescriptorMode::OnEnable, $d->mode);
        self::assertSame('Acme\\Widgets\\Schema\\WidgetsStructuralVerifier', $d->verifierClass);
    }

    public function testMigrationsNoneYieldsEmptyListAndCountsAsDeclared(): void
    {
        $m = $this->manifest([$this->extensionPkg(['migrations' => 'none'])]);
        self::assertSame([], $m->migrationDescriptors()['acme/widgets']);
        self::assertNotContains('acme/widgets', $m->undeclaredGluefulPackages());
    }

    public function testProviderDeclaringPackageWithoutMigrationsKeyIsUndeclaredNotFatal(): void
    {
        $m = $this->manifest([$this->extensionPkg([])]);
        self::assertArrayNotHasKey('acme/widgets', $m->migrationDescriptors());
        self::assertContains('acme/widgets', $m->undeclaredGluefulPackages());
    }

    public function testArbitraryComposerDependencyIsIgnoredEntirely(): void
    {
        $m = $this->manifest([[
            'name' => 'monolog/monolog',
            'type' => 'library',
            'install-path' => '../monolog/monolog',
            'extra' => [],
        ]]);
        self::assertArrayNotHasKey('monolog/monolog', $m->migrationDescriptors());
        self::assertNotContains('monolog/monolog', $m->undeclaredGluefulPackages());
    }

    public function testLibraryPackDeclaringOnEnableFailsClosed(): void
    {
        $m = $this->manifest([[
            'name' => 'glueful/thallo-commerce',
            'type' => 'library',
            'install-path' => '../glueful/thallo-commerce',
            'extra' => ['glueful' => ['migrations' => [
                ['id' => 'default', 'path' => 'migrations', 'priority' => 'dependent', 'mode' => 'on_enable'],
            ]]],
        ]]);
        $this->expectException(DescriptorValidationException::class);
        $m->migrationDescriptors();
    }

    public function testUnknownPriorityOrModeFailsClosed(): void
    {
        $m = $this->manifest([$this->extensionPkg(['migrations' => [
            ['id' => 'default', 'path' => 'migrations', 'priority' => 'urgent', 'mode' => 'on_enable'],
        ]])]);
        $this->expectException(DescriptorValidationException::class);
        $m->migrationDescriptors();
    }

    public function testEmptyMigrationsListFailsClosed(): void
    {
        $m = $this->manifest([$this->extensionPkg(['migrations' => []])]);
        $this->expectException(DescriptorValidationException::class);
        $m->migrationDescriptors();
    }

    public function testNonListMigrationsMapFailsClosed(): void
    {
        $m = $this->manifest([$this->extensionPkg(['migrations' => ['weird' => ['id' => 'default']]])]);
        $this->expectException(DescriptorValidationException::class);
        $m->migrationDescriptors();
    }

    public function testNonArrayDescriptorRowFailsClosed(): void
    {
        $m = $this->manifest([$this->extensionPkg(['migrations' => ['just-a-string']])]);
        $this->expectException(DescriptorValidationException::class);
        $m->migrationDescriptors();
    }

    public function testPlatformPriorityMapsToItsDedicatedSlot(): void
    {
        $m = $this->manifest([$this->extensionPkg(['migrations' => [
            ['id' => 'default', 'path' => 'migrations', 'priority' => 'platform', 'mode' => 'on_enable'],
        ]])]);
        self::assertSame(-50, $m->migrationDescriptors()['acme/widgets'][0]->priority);
    }

    public function testInstallPathsResolveAgainstTheVendorComposerDir(): void
    {
        $m = $this->manifest([$this->extensionPkg(['migrations' => 'none'])]);
        $paths = $m->installPaths();
        self::assertArrayHasKey('acme/widgets', $paths);
        self::assertStringEndsWith('/vendor/acme/widgets', $paths['acme/widgets']);
        self::assertStringStartsWith('/', $paths['acme/widgets']);
    }
}

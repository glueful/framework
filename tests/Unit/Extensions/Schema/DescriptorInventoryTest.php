<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions\Schema;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\PackageManifest;
use Glueful\Extensions\Schema\DescriptorInventory;
use Glueful\Extensions\Schema\DescriptorValidationException;
use Glueful\Extensions\Schema\FrameworkDescriptors;
use Glueful\Services\FileFinder;
use PHPUnit\Framework\TestCase;

final class DescriptorInventoryTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/glueful-inv-' . uniqid('', true);
        mkdir($this->base . '/vendor/composer', 0777, true);
    }

    /** @param list<array<string, mixed>> $packages */
    private function inventory(array $packages): DescriptorInventory
    {
        file_put_contents(
            $this->base . '/vendor/composer/installed.json',
            json_encode(['packages' => $packages], JSON_UNESCAPED_SLASHES)
        );
        $manifest = new PackageManifest(new ApplicationContext($this->base));
        return DescriptorInventory::fromManifest($manifest, $this->frameworkRoot(), new FileFinder());
    }

    private function frameworkRoot(): string
    {
        return dirname(__DIR__, 4); // the real framework repo root — its migrations/ leaves exist
    }

    /**
     * Creates a real package dir with migration files and returns its installed.json row.
     *
     * @param list<string> $files relative file paths under the package dir
     * @param list<array<string, mixed>> $migrations descriptor rows
     * @return array<string, mixed>
     */
    private function pkg(string $name, array $files, array $migrations, string $type = 'glueful-extension'): array
    {
        $dir = $this->base . '/vendor/' . $name;
        foreach ($files as $rel) {
            @mkdir(dirname($dir . '/' . $rel), 0777, true);
            file_put_contents($dir . '/' . $rel, "<?php // fixture\n");
        }
        return [
            'name' => $name,
            'type' => $type,
            'install-path' => '../' . $name,
            'extra' => ['glueful' => [
                'provider' => 'Fixture\\' . str_replace(['/', '-'], '', ucwords($name, '/-')) . '\\Provider',
                'migrations' => $migrations,
            ]],
        ];
    }

    public function testFrameworkBuiltInsCarryTheLegacyReceiptSources(): void
    {
        $sources = array_map(
            static fn($d) => $d->source(),
            FrameworkDescriptors::all($this->frameworkRoot())
        );
        foreach (
            [
            'glueful/framework',
            'glueful/framework:locks',
            'glueful/framework:metrics',
            'glueful/framework:notifications',
            'glueful/framework:queue',
            'glueful/framework:scheduler',
            'glueful/framework:uploads',
            ] as $expected
        ) {
            self::assertContains($expected, $sources);
        }
    }

    public function testHappyPathIndexesBySourcePackageAndDiscoversNestedFiles(): void
    {
        $inv = $this->inventory([$this->pkg('acme/widgets', [
            'migrations/001_A.php',
            'migrations/nested/002_B.php',
        ], [
            ['id' => 'default', 'path' => 'migrations', 'priority' => 'default', 'mode' => 'on_enable'],
        ])]);

        $d = $inv->bySource('acme/widgets');
        self::assertNotNull($d);
        self::assertCount(1, $inv->forPackage('acme/widgets'));
        self::assertTrue($inv->isDeclared('acme/widgets'));
        $basenames = array_map('basename', $inv->filesOf($d));
        self::assertSame(['001_A.php', '002_B.php'], $basenames);
        self::assertSame('acme/widgets', $inv->packageOfProvider('Fixture\\AcmeWidgets\\Provider'));
        self::assertNull($inv->packageOfProvider('App\\Nowhere\\Provider'));
    }

    public function testUndeclaredGluefulPackageIsNotDeclared(): void
    {
        $inv = $this->inventory([[
            'name' => 'acme/legacy',
            'type' => 'glueful-extension',
            'install-path' => '../acme/legacy',
            'extra' => ['glueful' => ['provider' => 'Acme\\Legacy\\P']],
        ]]);
        self::assertFalse($inv->isDeclared('acme/legacy'));
    }

    public function testDuplicateSourcesAcrossPackagesFailClosed(): void
    {
        // Named descriptor 'tenant' on acme/widgets collides with an alias-free source of the
        // same name declared by another package via legacy alias equality below; simplest direct
        // collision: two descriptors yielding the same source string is impossible across
        // packages (source embeds the package), so the cross-package collision surface is the
        // ALIAS index — this test pins the alias-vs-source collision instead.
        $this->expectException(DescriptorValidationException::class);
        $this->inventory([
            $this->pkg('acme/widgets', ['migrations/001_A.php'], [
                ['id' => 'default', 'path' => 'migrations', 'priority' => 'default', 'mode' => 'on_enable'],
            ]),
            $this->pkg('acme/gadgets', ['migrations/001_G.php'], [
                [
                    'id' => 'default',
                    'path' => 'migrations',
                    'priority' => 'default',
                    'mode' => 'on_enable',
                    'legacyAliases' => ['acme/widgets'],
                ],
            ]),
        ]);
    }

    public function testAliasClaimedTwiceFailsClosed(): void
    {
        $this->expectException(DescriptorValidationException::class);
        $this->inventory([
            $this->pkg('acme/widgets', ['migrations/001_A.php'], [
                [
                    'id' => 'default',
                    'path' => 'migrations',
                    'priority' => 'default',
                    'mode' => 'on_enable',
                    'legacyAliases' => ['old-name'],
                ],
            ]),
            $this->pkg('acme/gadgets', ['migrations/001_G.php'], [
                [
                    'id' => 'default',
                    'path' => 'migrations',
                    'priority' => 'default',
                    'mode' => 'on_enable',
                    'legacyAliases' => ['old-name'],
                ],
            ]),
        ]);
    }

    public function testDeclaredPathThatDoesNotResolveFailsClosed(): void
    {
        $row = $this->pkg('acme/widgets', ['migrations/001_A.php'], [
            ['id' => 'default', 'path' => 'not-there', 'priority' => 'default', 'mode' => 'on_enable'],
        ]);
        $this->expectException(DescriptorValidationException::class);
        $this->inventory([$row]);
    }

    public function testEmptyDeclaredPathFailsClosedWithNoneRemedy(): void
    {
        $row = $this->pkg('acme/widgets', ['migrations/.keep'], [
            ['id' => 'default', 'path' => 'migrations', 'priority' => 'default', 'mode' => 'on_enable'],
        ]);
        // .keep is not a migration php file; findMigrations('*.php') sees nothing.
        try {
            $this->inventory([$row]);
            self::fail('expected DescriptorValidationException');
        } catch (DescriptorValidationException $e) {
            self::assertStringContainsString('migrations: none', $e->getMessage());
        }
    }

    public function testDuplicateBasenamesWithinOneDescriptorFailClosed(): void
    {
        $this->expectException(DescriptorValidationException::class);
        $this->inventory([$this->pkg('acme/widgets', [
            'migrations/001_A.php',
            'migrations/nested/001_A.php',
        ], [
            ['id' => 'default', 'path' => 'migrations', 'priority' => 'default', 'mode' => 'on_enable'],
        ])]);
    }

    public function testAncestorDescendantDescriptorPathsFailClosed(): void
    {
        $this->expectException(DescriptorValidationException::class);
        $this->inventory([$this->pkg('acme/widgets', [
            'migrations/001_A.php',
            'migrations/tenant/002_T.php',
        ], [
            ['id' => 'default', 'path' => 'migrations', 'priority' => 'default', 'mode' => 'on_enable'],
            ['id' => 'tenant', 'path' => 'migrations/tenant', 'priority' => 'default', 'mode' => 'on_enable'],
        ])]);
    }

    public function testAliasIndexMapsAliasToSource(): void
    {
        $inv = $this->inventory([$this->pkg('acme/widgets', ['migrations/001_A.php'], [
            [
                'id' => 'default',
                'path' => 'migrations',
                'priority' => 'default',
                'mode' => 'on_enable',
                'legacyAliases' => ['acme-widgets'],
            ],
        ])]);
        self::assertSame(['acme-widgets' => 'acme/widgets'], $inv->aliasIndex());
    }
}

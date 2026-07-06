<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\Drivers\ArrayCacheDriver;
use Glueful\Extensions\ExtensionCatalog;
use Glueful\Extensions\ExtensionManager;
use Glueful\Http\Client;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class ExtensionCatalogTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->rmrf($dir);
        }
    }

    public function test_catalog_filters_by_vendor_and_type_and_hydrates_version(): void
    {
        $catalog = $this->catalog($this->packagistFixture(), installedJson: null);
        $rows = $catalog->catalog(refresh: true);

        // other/thing (wrong vendor) and glueful/faker (p2 type=library) are dropped.
        $this->assertCount(1, $rows);
        $this->assertSame('glueful/aegis', $rows[0]['package']);
        $this->assertSame('1.2.0', $rows[0]['version']);   // hydrated from p2
        $this->assertSame('Auth', $rows[0]['description']);
        $this->assertSame('available', $rows[0]['state']); // nothing installed locally
    }

    public function test_package_with_untyped_legacy_versions_is_kept_when_latest_is_typed(): void
    {
        // Real-world shape (glueful/entrada): the newest release carries
        // type=glueful-extension, but older releases predate the type and omit it.
        // Type re-verification must judge the latest release only — not every one.
        $map = [
            'https://packagist.org/search.json?type=glueful-extension&per_page=100' => [
                'results' => [
                    ['name' => 'glueful/entrada', 'description' => 'CMS', 'downloads' => 7, 'repository' => 'r'],
                ],
            ],
            'https://repo.packagist.org/p2/glueful/entrada.json' => [
                'packages' => ['glueful/entrada' => [
                    ['version' => 'v1.11.0', 'type' => 'glueful-extension'],
                    ['version' => 'v1.10.0'],                    // legacy: type field absent
                    ['version' => 'v1.9.0', 'type' => 'library'], // legacy: pre-adoption type
                ]],
            ],
        ];

        $rows = $this->catalog($map, installedJson: null)->catalog(refresh: true);

        $this->assertCount(1, $rows);
        $this->assertSame('glueful/entrada', $rows[0]['package']);
        $this->assertSame('v1.11.0', $rows[0]['version']);
    }

    public function test_state_is_installed_when_package_present_locally(): void
    {
        $installed = json_encode([
            'packages' => [[
                'name' => 'glueful/aegis',
                'type' => 'glueful-extension',
                'version' => '1.2.0',
                'extra' => ['glueful' => ['provider' => 'Glueful\\Aegis\\Provider']],
            ]],
        ]);

        $catalog = $this->catalog($this->packagistFixture(), installedJson: (string) $installed);
        $rows = $catalog->catalog(refresh: true);

        $this->assertSame('installed', $rows[0]['state']); // present but not enabled
    }

    /** @return array<string,array<string,mixed>> */
    private function packagistFixture(): array
    {
        return [
            'https://packagist.org/search.json?type=glueful-extension&per_page=100' => [
                'results' => [
                    ['name' => 'glueful/aegis', 'description' => 'Auth', 'downloads' => 10, 'repository' => 'r'],
                    ['name' => 'glueful/faker', 'description' => 'Lib', 'downloads' => 5, 'repository' => 'r'],
                    ['name' => 'other/thing', 'description' => 'No', 'downloads' => 1, 'repository' => 'r'],
                ],
            ],
            'https://repo.packagist.org/p2/glueful/aegis.json' => [
                'packages' => ['glueful/aegis' => [['version' => '1.2.0', 'type' => 'glueful-extension']]],
            ],
            'https://repo.packagist.org/p2/glueful/faker.json' => [
                'packages' => ['glueful/faker' => [['version' => '2.0.0', 'type' => 'library']]],
            ],
        ];
    }

    /** @param array<string,array<string,mixed>> $map */
    private function catalog(array $map, ?string $installedJson): ExtensionCatalog
    {
        $base = sys_get_temp_dir() . '/cat_' . bin2hex(random_bytes(6));
        mkdir($base . '/config', 0755, true);
        $this->tempDirs[] = $base;
        if ($installedJson !== null) {
            mkdir($base . '/vendor/composer', 0755, true);
            file_put_contents($base . '/vendor/composer/installed.json', $installedJson);
        }

        $context = ApplicationContext::forTesting($base);
        $container = new class ($context) implements ContainerInterface {
            public function __construct(private ApplicationContext $context)
            {
            }

            public function get(string $id): mixed
            {
                return $id === ApplicationContext::class ? $this->context
                    : throw new class ("No {$id}") extends \RuntimeException implements
                        \Psr\Container\NotFoundExceptionInterface {};
            }

            public function has(string $id): bool
            {
                return $id === ApplicationContext::class;
            }
        };

        return new class (
            $context,
            $this->createMock(Client::class),
            new ArrayCacheDriver(),
            new ExtensionManager($container),
            $map,
        ) extends ExtensionCatalog {
            /** @param array<string,array<string,mixed>> $map */
            public function __construct($ctx, $http, $cache, $extensions, private array $map)
            {
                parent::__construct($ctx, $http, $cache, $extensions);
            }

            protected function fetchJson(string $url): array
            {
                return $this->map[$url] ?? [];
            }
        };
    }

    private function rmrf(string $dir): void
    {
        if (!file_exists($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmrf($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}

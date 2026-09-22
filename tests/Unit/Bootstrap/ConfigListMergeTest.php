<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Bootstrap;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\ConfigurationLoader;
use PHPUnit\Framework\TestCase;

/**
 * An app's config file was merged over the framework's with array_replace_recursive, which merges
 * LISTS position by position: an app's third scheduled job was merged key by key with whatever the
 * framework's third job was, so keys it lacked (`enabled`, `parameters`, `queue` …) leaked in from
 * an unrelated job. A list the app sets now replaces the framework's list; maps still merge by key.
 */
final class ConfigListMergeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/glueful-listmerge-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/framework', 0755, true);
        mkdir($this->root . '/app', 0755, true);
        mkdir($this->root . '/config', 0755, true);
        file_put_contents($this->root . '/framework/schedule.php', '<?php return ' . var_export([
            'jobs' => [
                ['name' => 'fw_a', 'schedule' => '0 0 * * *', 'enabled' => false, 'parameters' => ['x' => 1]],
                ['name' => 'fw_b', 'schedule' => '0 1 * * *'],
            ],
            'options' => ['keep' => 'framework', 'tags' => ['a', 'b', 'c']],
        ], true) . ';');
        file_put_contents($this->root . '/app/schedule.php', '<?php return ' . var_export([
            'jobs' => [
                ['name' => 'app_only', 'schedule' => '* * * * *'],
            ],
            'options' => ['tags' => ['z']],
        ], true) . ';');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testAnAppListReplacesTheFrameworkListAndMapsStillMerge(): void
    {
        $loader = new ConfigurationLoader($this->root, 'testing', $this->root . '/app');
        (new \ReflectionProperty(ConfigurationLoader::class, 'configPaths'))->setValue($loader, [
            'framework' => $this->root . '/framework',
            'application' => $this->root . '/app',
        ]);

        $config = $loader->loadConfig('schedule');

        self::assertSame([['name' => 'app_only', 'schedule' => '* * * * *']], $config['jobs']);
        self::assertSame(['keep' => 'framework', 'tags' => ['z']], $config['options']);
    }

    public function testAFileListReplacesAPackageDefaultList(): void
    {
        // Package defaults merge UNDER the file config through the same rule.
        $merge = new \ReflectionMethod(ApplicationContext::class, 'deepMerge');

        $merged = $merge->invoke(null, ['items' => ['a', 'b', 'c'], 'map' => ['k' => 1, 'j' => 2]], [
            'items' => ['z'],
            'map' => ['k' => 9],
        ]);

        self::assertSame(['items' => ['z'], 'map' => ['k' => 9, 'j' => 2]], $merged);
    }

    public function testAnEmptyArrayAddsNothingInsteadOfWipingTheValueBelow(): void
    {
        // 1.86.0 treated [] as a list and let it replace: a package shipping `'source_roots' => []`
        // wiped the uploads root another package contributed. [] means "nothing to add", as it
        // did under array_replace_recursive.
        $merge = new \ReflectionMethod(ApplicationContext::class, 'deepMerge');

        self::assertSame(
            ['roots' => ['uploads' => '/site/storage/uploads'], 'items' => ['a', 'b']],
            $merge->invoke(null, ['roots' => ['uploads' => '/site/storage/uploads'], 'items' => ['a', 'b']], [
                'roots' => [],
                'items' => [],
            ])
        );

        $loader = new ConfigurationLoader($this->root, 'testing', $this->root . '/app');
        $mergeConfigs = new \ReflectionMethod(ConfigurationLoader::class, 'mergeConfigs');
        self::assertSame(
            ['roots' => ['uploads' => '/x'], 'items' => ['a']],
            $mergeConfigs->invoke($loader, ['roots' => ['uploads' => '/x'], 'items' => ['a']], [
                'roots' => [],
                'items' => [],
            ])
        );
    }
}

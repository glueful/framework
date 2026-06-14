<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support\Documentation;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Container;
use Glueful\Container\Definition\ValueDefinition;
use Glueful\Services\FileFinder;
use Glueful\Support\Documentation\CommentsDocGenerator;
use Glueful\Support\Documentation\DocGenerator;
use Glueful\Support\Documentation\OpenApiGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Covers Phase-0 Fix 2: include_extensions / include_routes /
 * include_framework_routes gate which fragments are generated and merged.
 *
 * Uses injected test doubles so no full app boot is required: a fake
 * CommentsDocGenerator returns a fixed fragment list, and a spy DocGenerator
 * records which fragment files were merged.
 */
final class OpenApiGeneratorFlagGatingTest extends TestCase
{
    private string $tmpDir;
    private string $extDir;
    private string $routesDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/openapi_gate_' . uniqid();
        $this->extDir = $this->tmpDir . '/json-definitions/extensions';
        $this->routesDir = $this->tmpDir . '/json-definitions/routes';
        mkdir($this->extDir . '/aegis', 0755, true);
        mkdir($this->routesDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
    }

    public function testIncludeExtensionsFalseSkipsExtensionFragments(): void
    {
        $spy = $this->runDocs(['include_extensions' => false]);

        self::assertSame([], $spy->extensionFiles, 'No extension fragments when include_extensions=false');
        self::assertNotSame([], $spy->routeFiles, 'Route fragments still merged');
    }

    public function testIncludeRoutesFalseSkipsRouteFragments(): void
    {
        $spy = $this->runDocs(['include_routes' => false]);

        self::assertSame([], $spy->routeFiles, 'No route fragments when include_routes=false');
        self::assertNotSame([], $spy->extensionFiles, 'Extension fragments still merged');
    }

    public function testDefaultsIncludeBothExtensionAndRouteFragments(): void
    {
        $spy = $this->runDocs([]);

        self::assertNotSame([], $spy->extensionFiles);
        self::assertNotSame([], $spy->routeFiles);
    }

    public function testIncludeFrameworkRoutesFalseExcludesFrameworkRouteFragments(): void
    {
        // health.json matches a real framework route file (routes/health.php),
        // so it must be dropped when include_framework_routes=false; app.json
        // (an app route) must survive.
        $spy = $this->runDocs(['include_framework_routes' => false], includeHealthFragment: true);

        $names = array_map(static fn ($p) => basename($p), $spy->routeFiles);
        self::assertNotContains('health.json', $names, 'Framework route fragment must be excluded');
        self::assertContains('app.json', $names, 'App route fragment must survive');
    }

    /**
     * Run processExtensionAndRouteDocs (non-force) with the given option
     * overrides and return the spy DocGenerator.
     *
     * @param array<string, bool> $optionOverrides
     */
    private function runDocs(array $optionOverrides, bool $includeHealthFragment = false): SpyDocGenerator
    {
        // Build extension + route fragments on disk.
        $extFragment = $this->extDir . '/aegis/aegis.json';
        file_put_contents($extFragment, '{}');

        $appFragment = $this->routesDir . '/app.json';
        file_put_contents($appFragment, '{}');

        $generatedRouteFiles = [$appFragment];
        if ($includeHealthFragment) {
            $healthFragment = $this->routesDir . '/health.json';
            file_put_contents($healthFragment, '{}');
            $generatedRouteFiles[] = $healthFragment;
        }

        $context = $this->makeContext($optionOverrides);

        $comments = new FakeCommentsDocGenerator(
            $context,
            generated: array_merge([$extFragment], $generatedRouteFiles),
        );
        $spy = new SpyDocGenerator(context: $context);

        $generator = new OpenApiGenerator(
            $context,
            $spy,
            $comments,
            new FileFinder(),
            true,
        );
        $generator->onProgress(static function (): void {
            // Suppress generator log output during the test.
        });

        $method = new \ReflectionMethod($generator, 'processExtensionAndRouteDocs');
        $method->setAccessible(true);
        $method->invoke($generator, false);

        return $spy;
    }

    /**
     * @param array<string, bool> $optionOverrides
     */
    private function makeContext(array $optionOverrides): ApplicationContext
    {
        $context = new ApplicationContext($this->tmpDir);
        $container = new Container();
        $container->load([
            ApplicationContext::class => new ValueDefinition(ApplicationContext::class, $context),
        ]);
        $context->setContainer($container);

        $context->mergeConfigDefaults('documentation', [
            'paths' => [
                'extension_definitions' => $this->extDir,
                'route_definitions' => $this->routesDir,
            ],
            'options' => array_merge([
                'include_extensions' => true,
                'include_routes' => true,
            ], $optionOverrides),
            'sources' => [
                'include_framework_routes' => $optionOverrides['include_framework_routes'] ?? true,
            ],
        ]);

        return $context;
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}

/**
 * Fake comment generator: generateAll() returns a fixed fragment list.
 */
final class FakeCommentsDocGenerator extends CommentsDocGenerator
{
    /** @param list<string> $generated */
    public function __construct(ApplicationContext $context, private array $generated)
    {
        parent::__construct(
            context: $context,
            localExtensionsPath: sys_get_temp_dir(),
            outputPath: sys_get_temp_dir(),
            routesPath: sys_get_temp_dir(),
            routesOutputPath: sys_get_temp_dir(),
            extensionsManager: new \Glueful\Extensions\ExtensionManager(
                $context->getContainer()
            ),
        );
    }

    public function generateAll(): array
    {
        return $this->generated;
    }
}

/**
 * Spy assembler: records the file lists handed to the list-based merge methods.
 */
final class SpyDocGenerator extends DocGenerator
{
    /** @var list<string> */
    public array $extensionFiles = [];

    /** @var list<string> */
    public array $routeFiles = [];

    public function generateFromExtensionFiles(array $files): void
    {
        $this->extensionFiles = array_values($files);
    }

    public function generateFromRouteFiles(array $files): void
    {
        $this->routeFiles = array_values($files);
    }
}

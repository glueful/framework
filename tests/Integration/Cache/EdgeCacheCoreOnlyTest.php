<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Cache;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\Contracts\EdgeCacheInterface;
use Glueful\Cache\NullEdgeCache;
use Glueful\Console\Application as ConsoleApplication;
use Glueful\Controllers\Traits\ResponseCachingTrait;
use Glueful\Http\Response;
use Glueful\Testing\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Core-only acceptance for the CDN extraction.
 *
 * With NO `glueful/cdn` extension installed, a booted framework must:
 *   1. resolve EdgeCacheInterface to the no-op NullEdgeCache;
 *   2. run ResponseCachingTrait::edgeCacheResponse() with no edge cache-control
 *      headers (generateCacheHeaders() === []) while still emitting Surrogate-Key;
 *   3. expose NO `cache:purge` console command (other cache:* commands remain);
 *   4. carry no CDN code references in runtime/core source (src/, config/, routes/);
 *   5. declare no CDN dependency in composer.json.
 */
final class EdgeCacheCoreOnlyTest extends TestCase
{
    private string $appPath;

    protected function setUp(): void
    {
        $this->appPath = sys_get_temp_dir() . '/glueful-edge-core-' . uniqid('', true);
        $cfg = $this->appPath . '/config';
        mkdir($cfg, 0755, true);
        file_put_contents($cfg . '/app.php', "<?php\nreturn ['name' => 'Test', 'env' => 'testing'];\n");
        file_put_contents(
            $cfg . '/database.php',
            "<?php\nreturn ['engine' => 'sqlite', 'sqlite' => ['primary' => '" . $this->appPath . "/t.sqlite'], "
            . "'pooling' => ['enabled' => false]];\n"
        );
        file_put_contents(
            $cfg . '/cache.php',
            "<?php\nreturn ['enabled' => false, 'default' => 'array', "
            . "'stores' => ['array' => ['driver' => 'array']]];\n"
        );
        file_put_contents($cfg . '/session.php', "<?php\nreturn ['jwt_key' => 'test'];\n");
        file_put_contents($cfg . '/security.php', "<?php\nreturn ['csrf' => ['enabled' => false]];\n");
        parent::setUp();
    }

    protected function getBasePath(): string
    {
        return $this->appPath;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (isset($this->appPath) && is_dir($this->appPath)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->appPath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->appPath);
        }
    }

    /** 1. The seam resolves to the no-op edge cache. */
    public function test_edge_cache_interface_resolves_to_null_edge_cache(): void
    {
        self::assertInstanceOf(NullEdgeCache::class, $this->get(EdgeCacheInterface::class));
    }

    /** 2. The response caching trait runs with no edge headers; Surrogate-Key still emitted. */
    public function test_edge_cache_response_emits_no_edge_headers(): void
    {
        $context = $this->app()->getContext();

        $request = Request::create('/api/posts');
        $request->headers->set('Accept', 'application/json');
        $controller = new EdgeCachingControllerStub($context, $request);

        $response = Response::success(['ok' => true]);

        // The trait calls header() directly; suppress the "headers already sent"
        // notice that occurs under PHPUnit's started output buffer.
        $returned = @$controller->callEdgeCacheResponse($response, '/api/posts');

        self::assertInstanceOf(Response::class, $returned);
        self::assertSame($response, $returned, 'NullEdgeCache adds no edge headers; response is untouched');

        /** @var EdgeCacheInterface $edge */
        $edge = $context->getContainer()->get(EdgeCacheInterface::class);
        self::assertSame(
            [],
            $edge->generateCacheHeaders('/api/posts', 'application/json'),
            'core-only seam must produce no edge cache-control headers'
        );

        // Surrogate-Key is emitted regardless of provider (targeted-purge contract).
        // The trait writes it via raw header(); the only way to observe it is xdebug's
        // header collection — which requires xdebug.mode=develop. function_exists() is
        // NOT a sufficient guard: xdebug can be loaded with mode=off (as on the CI
        // runners, which set xdebug.mode=off), where xdebug_get_headers() exists but
        // returns []. Probe real capture capability so the assertion runs where headers
        // can actually be observed and is a no-op (not a false failure) where they can't.
        $canCaptureHeaders = false;
        if (\function_exists('xdebug_get_headers')) {
            @\header('X-Edge-Capture-Probe: 1');
            foreach (xdebug_get_headers() as $h) {
                if (stripos($h, 'X-Edge-Capture-Probe:') === 0) {
                    $canCaptureHeaders = true;
                    break;
                }
            }
            @\header_remove('X-Edge-Capture-Probe');
        }

        if ($canCaptureHeaders) {
            $hasSurrogate = false;
            foreach (xdebug_get_headers() as $h) {
                if (stripos($h, 'Surrogate-Key:') === 0) {
                    $hasSurrogate = true;
                }
                self::assertStringNotContainsStringIgnoringCase(
                    'Cache-Control:',
                    $h,
                    'core-only path must not emit an edge Cache-Control header'
                );
            }
            self::assertTrue($hasSurrogate, 'Surrogate-Key header must still be emitted in core-only mode');
        } else {
            self::assertTrue(true, 'header() capture needs xdebug.mode=develop; seam behavior asserted above');
        }
    }

    /** 3. cache:purge is gone; sibling cache:* commands survive. */
    public function test_cache_purge_command_is_absent_while_other_cache_commands_remain(): void
    {
        $console = new ConsoleApplication($this->getContainer());

        self::assertFalse($console->has('cache:purge'), 'cache:purge belongs to the CDN extension, not core');
        self::assertTrue($console->has('cache:clear'), 'cache:clear is core');
        self::assertTrue($console->has('cache:status'), 'cache:status is core');
    }

    /** 4. No CDN code references in runtime/core source. */
    public function test_no_cdn_references_in_runtime_source(): void
    {
        $root = \dirname(__DIR__, 3);
        $roots = [$root . '/src', $root . '/config', $root . '/routes'];
        $needles = ['EdgeCacheService', 'CDNAdapterManager', 'Glueful\\Cache\\CDN\\'];

        $matches = [];
        foreach ($roots as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $contents = (string) file_get_contents($file->getPathname());
                foreach ($needles as $needle) {
                    if (str_contains($contents, $needle)) {
                        $matches[] = $file->getPathname() . ' :: ' . $needle;
                    }
                }
            }
        }

        self::assertSame([], $matches, "CDN code must not appear in runtime/core source:\n" . implode("\n", $matches));
    }

    /** 5. composer.json declares no CDN dependency. */
    public function test_composer_json_has_no_cdn_dependency(): void
    {
        $root = \dirname(__DIR__, 3);
        /** @var array{require?: array<string, string>, 'require-dev'?: array<string, string>} $composer */
        $composer = json_decode((string) file_get_contents($root . '/composer.json'), true);

        $require = array_merge($composer['require'] ?? [], $composer['require-dev'] ?? []);

        self::assertArrayNotHasKey('glueful/cdn', $require, 'core must not depend on the CDN extension');

        foreach (array_keys($require) as $package) {
            self::assertStringNotContainsStringIgnoringCase(
                'cdn',
                (string) $package,
                "core composer.json must not pull a CDN SDK ({$package})"
            );
        }
    }
}

/**
 * Minimal controller exercising ResponseCachingTrait without the full BaseController stack.
 * Mirrors the properties the trait reads: getContext(), $request, $currentUser.
 */
final class EdgeCachingControllerStub
{
    use ResponseCachingTrait;

    /** @phpstan-ignore-next-line trait reads $currentUser */
    protected ?object $currentUser = null;

    public function __construct(
        private readonly ApplicationContext $context,
        protected Request $request,
    ) {
    }

    public function getContext(): ApplicationContext
    {
        return $this->context;
    }

    public function callEdgeCacheResponse(Response $response, string $pattern): Response
    {
        return $this->edgeCacheResponse($response, $pattern);
    }
}

<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Routing;

use Glueful\Routing\FrontendMountRegistry;
use Glueful\Routing\SpaMountController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Asset/index SERVING behaviour of the SPA mount controller: mime typing, the
 * cache split (immutable hashed assets vs revalidatable shell), security headers,
 * path-traversal/dotfile/php denial, and the SPA deep-link fallback. This logic
 * previously lived in ServiceProvider::serveFrontend() closures and moved here so
 * the route table stays cacheable; these tests are ported byte-for-byte in intent.
 */
class SpaMountControllerTest extends TestCase
{
    private string $dir;
    private SpaMountController $controller;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/spa_mount_' . uniqid();
        mkdir($this->dir . '/assets', 0755, true);
        file_put_contents($this->dir . '/index.html', '<!doctype html><title>App</title>');
        file_put_contents($this->dir . '/favicon.ico', 'ico');
        file_put_contents($this->dir . '/assets/app-C5kJ8nQ2.js', 'console.log(1)');
        file_put_contents($this->dir . '/style.css', 'body{}');
        file_put_contents($this->dir . '/.env', 'SECRET=1');
        file_put_contents($this->dir . '/config.php', '<?php');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->dir);
        }
    }

    /** Controller with a mount at $prefix pointing at the fixture bundle. */
    private function mount(string $prefix = '/admin', bool $spaFallback = true): SpaMountController
    {
        $registry = new FrontendMountRegistry();
        $registry->register($prefix, (string) realpath($this->dir), $spaFallback, $prefix);
        return new SpaMountController($registry);
    }

    public function testUnknownMountReturns404(): void
    {
        $controller = $this->mount('/admin');
        // No mount owns /portal — must 404, never leak the /admin bundle.
        self::assertSame(404, $controller->asset(Request::create('/portal/x'), 'x')->getStatusCode());
        self::assertSame(404, $controller->root(Request::create('/portal'))->getStatusCode());
    }

    public function testTraversalDotfileAndPhpAreDenied(): void
    {
        $controller = $this->mount('/admin');
        foreach (['../../../etc/passwd', '../.env', '.env', 'config.php'] as $bad) {
            $resp = $controller->asset(Request::create("/admin/$bad"), $bad);
            self::assertSame(404, $resp->getStatusCode(), "$bad must be denied");
        }
    }

    public function testNonHashedAssetServedWithNoCacheAndSecurityHeaders(): void
    {
        $resp = $this->mount('/admin')->asset(Request::create('/admin/style.css'), 'style.css');

        self::assertSame(200, $resp->getStatusCode());
        self::assertInstanceOf(BinaryFileResponse::class, $resp);
        self::assertSame('nosniff', $resp->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('no-cache', (string) $resp->headers->get('Cache-Control'));
    }

    public function testTextAssetsGetExtensionMimeNotSniffedTextPlain(): void
    {
        // finfo content-sniffing calls css/js "text/plain" (no magic bytes), and
        // these responses carry X-Content-Type-Options: nosniff — so a wrong
        // type makes browsers REFUSE stylesheets/module scripts outright. The
        // extension map must win for known extensions; sniffing is only the
        // fallback for extensionless files.
        $controller = $this->mount('/admin');
        $css = $controller->asset(Request::create('/admin/style.css'), 'style.css');
        self::assertSame('text/css', $css->headers->get('Content-Type'));
        $js = $controller->asset(
            Request::create('/admin/assets/app-C5kJ8nQ2.js'),
            'assets/app-C5kJ8nQ2.js',
        );
        self::assertStringContainsString('javascript', (string) $js->headers->get('Content-Type'));
    }

    public function testHashedAssetServedImmutable(): void
    {
        $resp = $this->mount('/admin')->asset(
            Request::create('/admin/assets/app-C5kJ8nQ2.js'),
            'assets/app-C5kJ8nQ2.js',
        );

        self::assertSame(200, $resp->getStatusCode());
        self::assertStringContainsString('immutable', (string) $resp->headers->get('Cache-Control'));
        self::assertStringContainsString('max-age=31536000', (string) $resp->headers->get('Cache-Control'));
        self::assertNotEmpty($resp->headers->get('ETag'));
    }

    public function testRootServesIndexWithNoCache(): void
    {
        $resp = $this->mount('/admin')->root(Request::create('/admin'));

        self::assertSame(200, $resp->getStatusCode());
        self::assertStringContainsString('no-cache', (string) $resp->headers->get('Cache-Control'));
    }

    public function testRouteLikeDeepLinkFallsBackToIndex(): void
    {
        $resp = $this->mount('/admin')->asset(Request::create('/admin/posts/123'), 'posts/123');

        self::assertSame(200, $resp->getStatusCode());
        self::assertStringContainsString('no-cache', (string) $resp->headers->get('Cache-Control'));
    }

    public function testMissingAssetIsA404NotIndex(): void
    {
        $resp = $this->mount('/admin')->asset(Request::create('/admin/missing.js'), 'missing.js');
        self::assertSame(404, $resp->getStatusCode());
    }

    public function testDotRuleTreatsDottedPathAsAsset(): void
    {
        $resp = $this->mount('/admin')->asset(Request::create('/admin/docs.v1'), 'docs.v1');
        self::assertSame(404, $resp->getStatusCode());
    }

    public function testSpaFallbackFalseReturns404OnMissAndRoot(): void
    {
        $controller = $this->mount('/downloads', spaFallback: false);

        $miss = $controller->asset(Request::create('/downloads/nope'), 'nope');
        self::assertSame(404, $miss->getStatusCode());

        $root = $controller->root(Request::create('/downloads'));
        self::assertSame(404, $root->getStatusCode());

        // A real file is still served with spaFallback:false.
        $hit = $controller->asset(Request::create('/downloads/style.css'), 'style.css');
        self::assertSame(200, $hit->getStatusCode());
    }

    public function testLongestPrefixWinsWhenMountsNest(): void
    {
        // Two mounts, one nested under the other; a request under the deeper mount
        // must resolve to it, not the shallower parent.
        $registry = new FrontendMountRegistry();
        $registry->register('/admin', (string) realpath($this->dir), true, 'admin');
        $registry->register('/admin/reports', (string) realpath($this->dir), true, 'reports');
        $match = $registry->match('/admin/reports/q3');
        self::assertNotNull($match);
        self::assertSame('/admin/reports', $match['prefix']);
    }
}

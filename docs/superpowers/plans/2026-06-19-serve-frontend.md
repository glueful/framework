# `serveFrontend()` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace `ServiceProvider::mountStatic()` with `serveFrontend(string $path, string $dir, array $options = [])` — serve a built SPA (or plain static bundle) at any literal path, with secure asset serving, an `index.html` deep-link fallback, and a cache split (immutable hashed assets, `no-cache` shell) — and delete the dead `SpaManager`/`StaticFileDetector`/`SpaProvider`.

**Architecture:** One new protected method on `Glueful\Extensions\ServiceProvider` plus four private helpers (asset server, cache-control, index server, warning log). It registers two GET routes at the literal `$path` (root + `{rest:.+}` catch-all). The serve engine is the proven `mountStatic` `$serveFile` logic (traversal guard, dotfile/`.php` denial, mime, `SecurityHeaders`, ETag/304). No request-path normalization — the router already `rtrim`s request paths before matching, so `/admin/` resolves to the root route for free.

**Tech Stack:** PHP 8.3, PHPUnit 10, Symfony HttpFoundation (`BinaryFileResponse`, `Response`, `MimeTypes`). Tests under `tests/Integration/Extensions/` and `tests/Unit/Extensions/`. Run one class with `vendor/bin/phpunit --filter <ClassName>`; lint `composer phpcs`.

**Spec:** `docs/superpowers/specs/2026-06-17-framework-serve-frontend-design.md`

---

## File map

- Modify: `src/Extensions/ServiceProvider.php` — add `serveFrontend()` + private helpers (`frontendAssetServer`, `frontendCacheControl`, `frontendIndexServer`, `logFrontendWarning`); later remove `mountStatic()`.
- Create: `tests/Integration/Extensions/ServeFrontendTest.php` — full behavioral + boot-guard suite (supersedes `MountStaticSecurityTest`).
- Delete: `tests/Integration/Extensions/MountStaticSecurityTest.php` — coverage ported to `ServeFrontendTest`.
- Modify: `tests/Unit/Extensions/ServiceProviderTest.php` — retarget the two `mountStatic` reflection tests to `serveFrontend`.
- Modify: `src/Routing/Router.php` — fix the HEAD body-strip so it does not throw on a `BinaryFileResponse`.
- Create: `tests/Integration/Extensions/ServeFrontendDispatchTest.php` — real-`Router::dispatch` tests (HEAD, request trailing-slash, static-route precedence).
- Delete: `src/Extensions/SpaManager.php`, `src/Helpers/StaticFileDetector.php`, `src/Container/Providers/SpaProvider.php`.
- Modify: `src/Container/Bootstrap/ContainerFactory.php` — remove the `SpaProvider::class` entry (line ~177).
- Modify: `CHANGELOG.md` — `[Unreleased]` entry.

Tasks are ordered so each ends green: Task 1 adds `serveFrontend` alongside the still-present `mountStatic`; Task 2 fixes the Router HEAD bug and adds real-dispatch tests; Task 3 removes `mountStatic`; Task 4 deletes the dead classes; Task 5 documents.

---

## Task 1: Implement `serveFrontend()` + helpers

**Files:**
- Modify: `src/Extensions/ServiceProvider.php`
- Test: `tests/Integration/Extensions/ServeFrontendTest.php`

- [ ] **Step 1: Write the failing test.** Create `tests/Integration/Extensions/ServeFrontendTest.php`:
```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Extensions;

use Glueful\Extensions\ServiceProvider;
use Glueful\Routing\Route;
use Glueful\Routing\Router;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ServeFrontendTest extends TestCase
{
    private string $dir;
    private ContainerInterface&MockObject $container;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/serve_frontend_' . uniqid();
        mkdir($this->dir . '/assets', 0755, true);
        file_put_contents($this->dir . '/index.html', '<!doctype html><title>App</title>');
        file_put_contents($this->dir . '/favicon.ico', 'ico');
        file_put_contents($this->dir . '/assets/app-C5kJ8nQ2.js', 'console.log(1)');
        file_put_contents($this->dir . '/style.css', 'body{}');
        file_put_contents($this->dir . '/.env', 'SECRET=1');
        file_put_contents($this->dir . '/config.php', '<?php');

        $this->container = $this->createMock(ContainerInterface::class);
        $this->container->method('has')->willReturnCallback(
            static fn (string $id): bool => $id === Router::class,
        );
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

    /**
     * Mount and capture the two registered route handlers.
     *
     * @param  array<string, mixed> $options
     * @return array{0: array<string, callable>, 1: int}
     */
    private function mount(string $path, string $dir, array $options = []): array
    {
        $routes = [];
        $calls = 0;
        $router = $this->createMock(Router::class);
        $router->method('get')->willReturnCallback(function ($p, $handler) use (&$routes, &$calls) {
            $routes[$p] = $handler;
            $calls++;
            $route = $this->createMock(Route::class);
            $route->method('where')->willReturnSelf();
            return $route;
        });
        $this->container->method('get')->with(Router::class)->willReturn($router);

        $provider = new class ($this->container) extends ServiceProvider {
            /** @param array<string, mixed> $options */
            public function expose(string $path, string $dir, array $options): void
            {
                $this->serveFrontend($path, $dir, $options);
            }
        };
        $provider->expose($path, $dir, $options);

        return [$routes, $calls];
    }

    public function testInvalidMountPathThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        [$routes] = $this->mount('admin', $this->dir); // no leading slash
    }

    public function testTrailingSlashMountArgumentThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        [$routes] = $this->mount('/admin/', $this->dir);
    }

    public function testMissingDirIsNoOp(): void
    {
        [$routes, $calls] = $this->mount('/admin', $this->dir . '/does-not-exist');
        self::assertSame(0, $calls);
    }

    public function testMissingIndexWithSpaFallbackIsNoOp(): void
    {
        unlink($this->dir . '/index.html');
        [$routes, $calls] = $this->mount('/admin', $this->dir);
        self::assertSame(0, $calls, 'No index.html + spaFallback => no routes registered');
    }

    public function testValidMountRegistersTwoRoutes(): void
    {
        [$routes, $calls] = $this->mount('/admin', $this->dir);
        self::assertSame(2, $calls);
        self::assertArrayHasKey('/admin', $routes);
        self::assertArrayHasKey('/admin/{rest}', $routes);
    }

    public function testTraversalDotfileAndPhpAreDenied(): void
    {
        [$routes] = $this->mount('/admin', $this->dir);
        $serve = $routes['/admin/{rest}'];
        foreach (['../../../etc/passwd', '../.env', '.env', 'config.php'] as $bad) {
            $resp = $serve(Request::create("/admin/$bad"), $bad);
            self::assertSame(404, $resp->getStatusCode(), "$bad must be denied");
        }
    }

    public function testNonHashedAssetServedWithNoCacheAndSecurityHeaders(): void
    {
        [$routes] = $this->mount('/admin', $this->dir);
        $resp = $routes['/admin/{rest}'](Request::create('/admin/style.css'), 'style.css');

        self::assertSame(200, $resp->getStatusCode());
        self::assertInstanceOf(BinaryFileResponse::class, $resp);
        self::assertSame('nosniff', $resp->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('no-cache', (string) $resp->headers->get('Cache-Control'));
    }

    public function testHashedAssetServedImmutable(): void
    {
        [$routes] = $this->mount('/admin', $this->dir);
        $resp = $routes['/admin/{rest}'](Request::create('/admin/assets/app-C5kJ8nQ2.js'), 'assets/app-C5kJ8nQ2.js');

        self::assertSame(200, $resp->getStatusCode());
        self::assertStringContainsString('immutable', (string) $resp->headers->get('Cache-Control'));
        self::assertStringContainsString('max-age=31536000', (string) $resp->headers->get('Cache-Control'));
        self::assertNotEmpty($resp->headers->get('ETag'));
    }

    public function testRootServesIndexWithNoCache(): void
    {
        [$routes] = $this->mount('/admin', $this->dir);
        $resp = $routes['/admin'](Request::create('/admin'));

        self::assertSame(200, $resp->getStatusCode());
        self::assertStringContainsString('no-cache', (string) $resp->headers->get('Cache-Control'));
    }

    public function testRouteLikeDeepLinkFallsBackToIndex(): void
    {
        [$routes] = $this->mount('/admin', $this->dir);
        $resp = $routes['/admin/{rest}'](Request::create('/admin/posts/123'), 'posts/123');

        self::assertSame(200, $resp->getStatusCode());
        self::assertStringContainsString('no-cache', (string) $resp->headers->get('Cache-Control'));
    }

    public function testMissingAssetIsA404NotIndex(): void
    {
        [$routes] = $this->mount('/admin', $this->dir);
        $resp = $routes['/admin/{rest}'](Request::create('/admin/missing.js'), 'missing.js');
        self::assertSame(404, $resp->getStatusCode());
    }

    public function testDotRuleTreatsDottedPathAsAsset(): void
    {
        [$routes] = $this->mount('/admin', $this->dir);
        $resp = $routes['/admin/{rest}'](Request::create('/admin/docs.v1'), 'docs.v1');
        self::assertSame(404, $resp->getStatusCode());
    }

    public function testSpaFallbackFalseReturns404OnMissAndRoot(): void
    {
        [$routes] = $this->mount('/downloads', $this->dir, ['spaFallback' => false]);

        $miss = $routes['/downloads/{rest}'](Request::create('/downloads/nope'), 'nope');
        self::assertSame(404, $miss->getStatusCode());

        $root = $routes['/downloads'](Request::create('/downloads'));
        self::assertSame(404, $root->getStatusCode());

        // A real file is still served with spaFallback:false.
        $hit = $routes['/downloads/{rest}'](Request::create('/downloads/style.css'), 'style.css');
        self::assertSame(200, $hit->getStatusCode());
    }
}
```

- [ ] **Step 2: Run it; verify it fails.**

Run: `vendor/bin/phpunit --filter ServeFrontendTest`
Expected: FAIL — `serveFrontend()` does not exist (`ReflectionException`/`Error`).

- [ ] **Step 3: Implement `serveFrontend()` + helpers.** In `src/Extensions/ServiceProvider.php`, add the method (place it next to the existing `mountStatic()`):
```php
    /**
     * Serve a prebuilt SPA (or static bundle) at a literal path, with safe asset
     * serving and an optional index.html deep-link fallback for client-side routing.
     *
     * @param string $path  Literal mount path, e.g. '/admin' or '/app/console'. Strict:
     *                      a leading '/', lowercase [a-z0-9-] segments, no trailing slash.
     * @param string $dir   Filesystem directory of the built bundle.
     * @param array{spaFallback?: bool, name?: string} $options
     */
    protected function serveFrontend(string $path, string $dir, array $options = []): void
    {
        if (preg_match('#^/[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*$#', $path) !== 1) {
            throw new \InvalidArgumentException(
                "Invalid mount path '{$path}'. Use a leading slash and lowercase "
                . "[a-z0-9-] segments with no trailing slash (e.g. '/admin')."
            );
        }

        $spaFallback = (bool) ($options['spaFallback'] ?? true);
        $name = (string) ($options['name'] ?? $path);

        if (!$this->app->has(\Glueful\Routing\Router::class) || !is_dir($dir)) {
            return;
        }
        $realDir = realpath($dir);
        if ($realDir === false) {
            return;
        }
        if ($spaFallback && !is_file($realDir . DIRECTORY_SEPARATOR . 'index.html')) {
            $this->logFrontendWarning(
                "serveFrontend('{$path}') skipped: {$name} bundle at {$realDir} has no index.html."
            );
            return;
        }

        /** @var \Glueful\Routing\Router $router */
        $router = $this->app->get(\Glueful\Routing\Router::class);

        $serveAsset = $this->frontendAssetServer($realDir);
        $serveIndex = $this->frontendIndexServer($realDir);

        $router->get($path, function (
            \Symfony\Component\HttpFoundation\Request $request
        ) use ($spaFallback, $serveIndex) {
            return $spaFallback
                ? $serveIndex($request)
                : new \Symfony\Component\HttpFoundation\Response('', 404);
        });

        $router->get($path . '/{rest}', function (
            \Symfony\Component\HttpFoundation\Request $request,
            string $rest
        ) use ($realDir, $spaFallback, $serveAsset, $serveIndex) {
            if (headers_sent()) {
                return new \Symfony\Component\HttpFoundation\Response('', 404);
            }

            $basename = basename($rest);
            if ($basename === '' || $basename[0] === '.' || str_ends_with(strtolower($basename), '.php')) {
                return new \Symfony\Component\HttpFoundation\Response('', 404);
            }

            // Reject path-traversal sequences outright — a `..` segment must 404, never
            // fall through to the SPA shell (the realpath check below also rejects an
            // escaped *file*, but an extension-less traversal path would otherwise reach
            // the index.html fallback).
            if (preg_match('#(^|/)\.\.(/|$)#', $rest) === 1) {
                return new \Symfony\Component\HttpFoundation\Response('', 404);
            }

            $requested = realpath($realDir . DIRECTORY_SEPARATOR . $rest);
            if (
                $requested !== false
                && str_starts_with($requested, $realDir . DIRECTORY_SEPARATOR)
                && is_file($requested)
            ) {
                return $serveAsset($request, $requested, $basename);
            }

            if (!$spaFallback) {
                return new \Symfony\Component\HttpFoundation\Response('', 404);
            }
            // "A dot means an asset": a missing asset is a 404, never the HTML shell.
            if (pathinfo($rest, PATHINFO_EXTENSION) !== '') {
                return new \Symfony\Component\HttpFoundation\Response('', 404);
            }
            return $serveIndex($request);
        })->where('rest', '.+');
    }

    /**
     * Closure that streams a built asset with mime, security headers, the cache
     * split, ETag/Last-Modified and 304 handling.
     *
     * @return \Closure(\Symfony\Component\HttpFoundation\Request, string, string): \Symfony\Component\HttpFoundation\Response
     */
    private function frontendAssetServer(string $realDir): \Closure
    {
        return function (
            \Symfony\Component\HttpFoundation\Request $request,
            string $realPath,
            string $basename
        ): \Symfony\Component\HttpFoundation\Response {
            $mtime = filemtime($realPath) !== false ? filemtime($realPath) : time();
            $etag = md5_file($realPath) !== false ? md5_file($realPath) : sha1($realPath);

            $guesser = \Symfony\Component\Mime\MimeTypes::getDefault();
            $mimeGuess = mime_content_type($realPath);
            $mime = $guesser->guessMimeType($realPath)
                ?? ($mimeGuess !== false ? $mimeGuess : 'application/octet-stream');

            $resp = new \Symfony\Component\HttpFoundation\BinaryFileResponse($realPath);
            $resp->headers->set('Content-Type', $mime);
            foreach (\Glueful\Security\SecurityHeaders::defaultStaticAssetHeaders() as $header => $value) {
                $resp->headers->set($header, $value);
            }
            $resp->headers->set('Cache-Control', $this->frontendCacheControl($basename));
            $resp->setEtag('"' . $etag . '"');
            $resp->setLastModified((new \DateTimeImmutable())->setTimestamp($mtime));
            $resp->setContentDisposition(
                \Symfony\Component\HttpFoundation\ResponseHeaderBag::DISPOSITION_INLINE,
                $basename
            );
            $resp->isNotModified($request);
            return $resp;
        };
    }

    /**
     * Cache-Control for a served file: content-hashed assets are immutable;
     * everything else (incl. index.html) revalidates so deploys are seen.
     */
    private function frontendCacheControl(string $basename): string
    {
        return preg_match('/[.\-_][A-Za-z0-9]{8,}\.[A-Za-z0-9]+$/', $basename) === 1
            ? 'public, max-age=31536000, immutable'
            : 'no-cache';
    }

    /**
     * Closure that serves index.html (200, no-cache, hardened headers, revalidatable).
     *
     * @return \Closure(\Symfony\Component\HttpFoundation\Request): \Symfony\Component\HttpFoundation\Response
     */
    private function frontendIndexServer(string $realDir): \Closure
    {
        return function (
            \Symfony\Component\HttpFoundation\Request $request
        ) use ($realDir): \Symfony\Component\HttpFoundation\Response {
            $index = $realDir . DIRECTORY_SEPARATOR . 'index.html';
            if (!is_file($index)) {
                return new \Symfony\Component\HttpFoundation\Response('', 404);
            }
            $resp = new \Symfony\Component\HttpFoundation\BinaryFileResponse($index);
            $resp->headers->set('Content-Type', 'text/html; charset=UTF-8');
            foreach (\Glueful\Security\SecurityHeaders::defaultStaticAssetHeaders() as $header => $value) {
                $resp->headers->set($header, $value);
            }
            $resp->headers->set('Cache-Control', 'no-cache');
            $mtime = filemtime($index) !== false ? filemtime($index) : time();
            $etag = md5_file($index) !== false ? md5_file($index) : sha1($index);
            $resp->setEtag('"' . $etag . '"');
            $resp->setLastModified((new \DateTimeImmutable())->setTimestamp($mtime));
            $resp->isNotModified($request);
            return $resp;
        };
    }

    /** Emit a boot-time warning through the container's logger when available. */
    private function logFrontendWarning(string $message): void
    {
        if ($this->app->has(\Psr\Log\LoggerInterface::class)) {
            $this->app->get(\Psr\Log\LoggerInterface::class)->warning($message);
        }
    }
```

- [ ] **Step 4: Run it; verify it passes.**

Run: `vendor/bin/phpunit --filter ServeFrontendTest`
Expected: PASS — all cases (validation, no-op guards, traversal/dotfile/php denial, hashed/non-hashed cache, root + deep-link index, missing-asset 404, dot-rule, `spaFallback:false`).

- [ ] **Step 5: phpcs + commit.**
```bash
composer phpcs
git add src/Extensions/ServiceProvider.php tests/Integration/Extensions/ServeFrontendTest.php
git commit -m "Add serveFrontend() for SPA/static serving at a literal path"
```

---

## Task 2: Fix Router HEAD handling + real-dispatch tests

The spec assumes "register GET, get HEAD for free." But `Router::dispatch()` strips the HEAD
body with `$response->setContent('')`, and `BinaryFileResponse::setContent()` **throws
`LogicException` on any non-null value** — so HEAD to a `serveFrontend` route (which returns
`BinaryFileResponse`) currently 500s. This is a general Router bug (any `BinaryFileResponse`,
e.g. file downloads); fix it once, and add real-`dispatch` coverage for HEAD plus the spec's
trailing-slash-normalization and static-route-precedence contracts that handler-capture tests
cannot prove.

**Files:**
- Modify: `src/Routing/Router.php`
- Test: `tests/Integration/Extensions/ServeFrontendDispatchTest.php`

- [ ] **Step 1: Write the failing test.** Create `tests/Integration/Extensions/ServeFrontendDispatchTest.php`:
```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Extensions;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ServiceProvider;
use Glueful\Routing\RouteCache;
use Glueful\Routing\Router;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ServeFrontendDispatchTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/serve_frontend_dispatch_' . uniqid();
        mkdir($this->dir, 0755, true);
        file_put_contents($this->dir . '/index.html', '<!doctype html><title>App</title>');
        file_put_contents($this->dir . '/style.css', 'body{}');
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/index.html');
        @unlink($this->dir . '/style.css');
        @rmdir($this->dir);
    }

    /** Build a REAL Router with the bundle mounted at /admin. */
    private function mountedRouter(): Router
    {
        $context = new ApplicationContext(sys_get_temp_dir() . '/sfd_ctx_' . uniqid());
        (new RouteCache($context))->clear();

        $container = new class implements ContainerInterface {
            /** @var array<string, mixed> */
            public array $services = [];
            public function has(string $id): bool
            {
                return array_key_exists($id, $this->services);
            }
            public function get(string $id): mixed
            {
                if ($this->has($id)) {
                    return $this->services[$id];
                }
                throw new class ("Service '$id' not found")
                    extends \RuntimeException
                    implements \Psr\Container\NotFoundExceptionInterface {
                };
            }
        };
        $container->services[ApplicationContext::class] = $context;
        $router = new Router($container);
        $container->services[Router::class] = $router;

        $provider = new class ($container) extends ServiceProvider {
            public function expose(string $path, string $dir): void
            {
                $this->serveFrontend($path, $dir);
            }
        };
        $provider->expose('/admin', $this->dir);

        return $router;
    }

    public function testHeadOnIndexDoesNotThrowAndHasEmptyBody(): void
    {
        $router = $this->mountedRouter();
        $resp = $router->dispatch(Request::create('/admin', 'HEAD'));

        self::assertSame(200, $resp->getStatusCode());
        self::assertSame('', (string) $resp->getContent());
        self::assertNotEmpty($resp->headers->get('Content-Type'));
    }

    public function testHeadOnAssetDoesNotThrow(): void
    {
        $router = $this->mountedRouter();
        $resp = $router->dispatch(Request::create('/admin/style.css', 'HEAD'));

        self::assertSame(200, $resp->getStatusCode());
        self::assertSame('', (string) $resp->getContent());
    }

    public function testRequestTrailingSlashServesIndex(): void
    {
        $router = $this->mountedRouter();
        // Router rtrims the request path before matching, so /admin/ hits the root route.
        $resp = $router->dispatch(Request::create('/admin/', 'GET'));

        self::assertSame(200, $resp->getStatusCode());
        // Index is a BinaryFileResponse (getContent() === false); assert via Content-Type.
        self::assertStringContainsString('text/html', (string) $resp->headers->get('Content-Type'));
    }

    public function testStaticConfigRouteIsNotShadowedBySpaCatchAll(): void
    {
        $router = $this->mountedRouter();
        // A real static sibling route under the mount prefix must win over /admin/{rest}.
        $router->get('/admin/config.json', static fn (): Response => new Response('CONFIG', 200));

        $resp = $router->dispatch(Request::create('/admin/config.json', 'GET'));
        self::assertSame('CONFIG', (string) $resp->getContent());
    }
}
```

- [ ] **Step 2: Run it; verify the HEAD cases fail.**

Run: `vendor/bin/phpunit --filter ServeFrontendDispatchTest`
Expected: the two HEAD tests ERROR with `LogicException: The content cannot be set on a BinaryFileResponse instance` (the trailing-slash and precedence tests already pass — they exercise GET).

- [ ] **Step 3: Fix the Router HEAD body-strip.** In `src/Routing/Router.php` (`dispatch()`), replace the HEAD body-strip block (`Response` and `BinaryFileResponse` are already imported on line 8):
```php
        // Handle HEAD requests (remove body but keep headers). BinaryFileResponse rejects
        // setContent() with anything but null, so swap it for a body-less Response that
        // preserves the status and headers — HEAD must never 500 or stream a body.
        if ($originalMethod === 'HEAD') {
            if ($response instanceof BinaryFileResponse) {
                $stripped = new Response('', $response->getStatusCode());
                $stripped->headers->replace($response->headers->all());
                $response = $stripped;
            } else {
                $response->setContent('');
            }
        }
```

- [ ] **Step 4: Run it; verify it passes.**

Run: `vendor/bin/phpunit --filter ServeFrontendDispatchTest`
Expected: PASS — HEAD on index and asset return 200 with an empty body and preserved headers; `/admin/` serves the index; `/admin/config.json` is not shadowed.

- [ ] **Step 5: Guard existing HEAD behavior.**

Run: `vendor/bin/phpunit --filter RouterTest`
Expected: PASS — non-`BinaryFileResponse` HEAD responses still strip via `setContent('')`; the change only adds a branch for `BinaryFileResponse`.

- [ ] **Step 6: phpcs + commit.**
```bash
composer phpcs
git add src/Routing/Router.php tests/Integration/Extensions/ServeFrontendDispatchTest.php
git commit -m "Fix HEAD handling for BinaryFileResponse in Router::dispatch()"
```

---

## Task 3: Remove `mountStatic()` and retarget its tests

**Files:**
- Modify: `src/Extensions/ServiceProvider.php`
- Delete: `tests/Integration/Extensions/MountStaticSecurityTest.php`
- Modify: `tests/Unit/Extensions/ServiceProviderTest.php`

- [ ] **Step 1: Delete `mountStatic()`.** In `src/Extensions/ServiceProvider.php`, remove the entire `mountStatic(string $mount, string $dir): void` method (the docblock above it through its closing brace — the `$serveFile`/`$indexCallback` closures and the two `/extensions/{mount}` route registrations).

- [ ] **Step 2: Delete the old security test.**
```bash
git rm tests/Integration/Extensions/MountStaticSecurityTest.php
```
Its coverage (traversal, dotfile/php denial, legit assets, index, security + cache headers) now lives in `ServeFrontendTest`.

- [ ] **Step 3: Retarget the two unit tests.** In `tests/Unit/Extensions/ServiceProviderTest.php`, replace `testMountStaticWithInvalidMountName()` and `testMountStaticWithValidMountName()` with:
```php
    public function testServeFrontendWithInvalidMountPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid mount path');

        $provider = new TestServiceProvider($this->container);

        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('serveFrontend');
        $method->setAccessible(true);
        $method->invoke($provider, 'Invalid_Mount!', '/some/dir', []);
    }

    public function testServeFrontendWithValidMountRegistersRoutes(): void
    {
        $router = $this->createMock(Router::class);
        $mockRoute = $this->createMock(Route::class);
        $mockRoute->method('where')->willReturnSelf();

        $router->expects($this->exactly(2))
               ->method('get')
               ->willReturn($mockRoute);

        $this->container->method('has')
                       ->with(\Glueful\Routing\Router::class)
                       ->willReturn(true);
        $this->container->method('get')
                       ->with(\Glueful\Routing\Router::class)
                       ->willReturn($router);

        $provider = new TestServiceProvider($this->container);

        // spaFallback defaults true, so the bundle must contain index.html.
        $staticDir = sys_get_temp_dir() . '/test_frontend_' . uniqid();
        mkdir($staticDir);
        file_put_contents($staticDir . '/index.html', '<!doctype html>');

        try {
            $reflection = new \ReflectionClass($provider);
            $method = $reflection->getMethod('serveFrontend');
            $method->setAccessible(true);
            $method->invoke($provider, '/valid-mount', $staticDir, []);
        } finally {
            unlink($staticDir . '/index.html');
            rmdir($staticDir);
        }
    }
```
> If `TestServiceProvider` (the in-file stub) has a wrapper method calling `mountStatic`, update or remove it — the tests above invoke `serveFrontend` directly via reflection and do not need a wrapper. Confirm with: `grep -n "mountStatic" tests/Unit/Extensions/ServiceProviderTest.php` (expect no matches after the edit).

- [ ] **Step 4: Run the affected suites; verify they pass.**

Run: `vendor/bin/phpunit --filter ServiceProviderTest && vendor/bin/phpunit --filter ServeFrontendTest`
Expected: PASS. Then confirm the live serve code and tests no longer reference the removed method:
```bash
grep -rn "mountStatic" src/Extensions/ServiceProvider.php tests/
```
Expected: no matches. (`src/Extensions/SpaManager.php` still mentions `mountStatic` in comments/log text — that file is deleted in Task 4, so the full-tree check belongs there, not here.)

- [ ] **Step 5: phpcs + commit.**
```bash
composer phpcs
git add src/Extensions/ServiceProvider.php tests/Unit/Extensions/ServiceProviderTest.php \
  tests/Integration/Extensions/MountStaticSecurityTest.php
git commit -m "Remove mountStatic() in favour of serveFrontend()"
```

---

## Task 4: Delete the dead SPA machinery

**Files:**
- Delete: `src/Extensions/SpaManager.php`, `src/Helpers/StaticFileDetector.php`, `src/Container/Providers/SpaProvider.php`
- Modify: `src/Container/Bootstrap/ContainerFactory.php`

- [ ] **Step 1: Confirm they are unreferenced.**
```bash
grep -rn "SpaManager\|StaticFileDetector\|SpaProvider" src/ tests/ \
  | grep -vE "src/Extensions/SpaManager.php|src/Helpers/StaticFileDetector.php|src/Container/Providers/SpaProvider.php"
```
Expected: a single match — the `SpaProvider::class` entry in `src/Container/Bootstrap/ContainerFactory.php` (~line 177). (If any other match appears, stop and reassess — something still depends on them.)

- [ ] **Step 2: Remove the provider registration.** In `src/Container/Bootstrap/ContainerFactory.php`, delete the line registering `\Glueful\Container\Providers\SpaProvider::class` from the providers list (line ~177), including a trailing comma so the array stays valid.

- [ ] **Step 3: Delete the three dead classes.**
```bash
git rm src/Extensions/SpaManager.php src/Helpers/StaticFileDetector.php \
  src/Container/Providers/SpaProvider.php
```

- [ ] **Step 4: Verify the container boots and the suite is green.**
```bash
grep -rn "SpaManager\|StaticFileDetector\|SpaProvider" src/ tests/   # expect: no matches
vendor/bin/phpunit tests/Unit/Extensions tests/Integration/Extensions
vendor/bin/phpunit --filter Container
```
Expected: no matches; both suites PASS (container compiles without the removed provider).

- [ ] **Step 5: phpcs + commit.**
```bash
composer phpcs
git add -A src/Extensions/SpaManager.php src/Helpers/StaticFileDetector.php \
  src/Container/Providers/SpaProvider.php src/Container/Bootstrap/ContainerFactory.php
git commit -m "Delete dead SpaManager/StaticFileDetector/SpaProvider"
```

---

## Task 5: CHANGELOG

**Files:**
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Add the `[Unreleased]` entry.** Under `## [Unreleased]` (create `### Added`/`### Fixed`/`### Removed` subsections as needed):
```markdown
### Added
- **`ServiceProvider::serveFrontend(string $path, string $dir, array $options = [])`** — serve a first-party SPA or static bundle at any literal path (e.g. `/admin`), with secure asset serving (traversal/dotfile/`.php` denial, mime, ETag/304), an `index.html` deep-link fallback for client-side routing (`spaFallback`, default true), and a cache split (immutable content-hashed assets, `no-cache` shell). Request trailing slashes are handled by the router; the mount argument itself is strict.

### Fixed
- **`HEAD` requests to a `BinaryFileResponse` no longer 500.** `Router::dispatch()` stripped the HEAD body with `setContent('')`, which `BinaryFileResponse` rejects; it now swaps in a body-less `Response` preserving status and headers. Affects any file/download route, not just `serveFrontend()`.

### Removed
- **`ServiceProvider::mountStatic()`** and the unused `SpaManager` / `StaticFileDetector` / `SpaProvider` — superseded by `serveFrontend()`, which serves at any literal path (not just `/extensions/{mount}`) and adds the SPA deep-link fallback `mountStatic()` lacked. No production consumers existed.
```

- [ ] **Step 2: Commit.**
```bash
git add CHANGELOG.md
git commit -m "Changelog: serveFrontend() replaces mountStatic()"
```

---

## Follow-ups (not in this plan)

- **Lemma `LemmaServiceProvider::boot()`** can call `serveFrontend('/admin', …)` once this ships in a tagged framework release — already specced in the Lemma Admin SPA plan (Task 0c), blocked on that release per the release-first rule.
- A minor framework release wraps these `[Unreleased]` entries.

## Self-review

- **Spec coverage:** strict path validation + no-op guards (missing dir / missing index) → Task 1 boot tests; serve engine + traversal/dotfile/`.php` denial + security headers → Task 1; cache split (hashed immutable vs `no-cache`) → Task 1 `frontendCacheControl` + tests; root index + deep-link fallback + missing-asset 404 + dot-rule + `spaFallback:false` → Task 1; **HEAD (Router fix), request trailing-slash normalization, and static-route precedence → Task 2 real-`dispatch` tests** (the spec's Testing section requires the precedence case, and the trailing-slash fix specifically called for full dispatch); consolidation removal (`mountStatic` + 3 dead classes + `ContainerFactory:177`) → Tasks 3–4. All mapped.
- **Placeholder scan:** none — full method/helper code and full test code given; every command has an expected result.
- **Type/name consistency:** `serveFrontend`/`frontendAssetServer`/`frontendCacheControl`/`frontendIndexServer`/`logFrontendWarning` are referenced identically across the method, helpers, and tests. The cache regex `/[.\-_][A-Za-z0-9]{8,}\.[A-Za-z0-9]+$/` matches `app-C5kJ8nQ2.js`/`app.4e1f9c2a.css` and not `favicon.ico`/`index.html`/`manifest.webmanifest` (verified against the spec's stated cases). The index server takes a `Request` (passed by both the root handler and the fallback) so it can compute ETag-based 304s.
- **Green per task:** Task 1 adds alongside `mountStatic` (both pass); Task 2 fixes the Router HEAD bug (adds a `BinaryFileResponse` branch, existing HEAD behavior unchanged); Task 3 removes `mountStatic` and retargets its tests (grep gate narrowed to `ServiceProvider.php` + `tests/` since `SpaManager` still names it until Task 4); Task 4 deletes provably-unreferenced classes; Task 5 is docs.
```

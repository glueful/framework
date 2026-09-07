<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Http;

use PHPUnit\Framework\TestCase;

/**
 * `php -S … router.php` is the documented local quickstart. When a mounted SPA lives at
 * public/admin/index.html, the built-in server resolves a deep link such as /admin/setup to
 * that DIRECTORY INDEX: SCRIPT_NAME=/admin/index.html, PATH_INFO=/setup. Symfony's Request
 * then infers '/admin' as the base path and strips it, so the router sees '/setup' and the
 * deep link 404s locally while nginx/Apache (SCRIPT_NAME=/index.php) serve it fine. The router
 * script must present the front controller the way a real web server does.
 */
final class BuiltInServerRouterTest extends TestCase
{
    private string $docroot;

    protected function setUp(): void
    {
        $this->docroot = sys_get_temp_dir() . '/glueful-router-' . uniqid('', true);
        mkdir($this->docroot . '/admin', 0755, true);
        file_put_contents($this->docroot . '/admin/index.html', '<!doctype html><title>spa</title>');
        // A stand-in front controller that reports what the framework would see.
        file_put_contents($this->docroot . '/index.php', <<<'PHP_'
            <?php
            require getenv('GLUEFUL_AUTOLOAD');
            $r = Symfony\Component\HttpFoundation\Request::createFromGlobals();
            header('Content-Type: text/plain');
            echo $r->getPathInfo(), "\n", $r->getBaseUrl(), "\n";
            PHP_);
    }

    protected function tearDown(): void
    {
        @unlink($this->docroot . '/admin/index.html');
        @unlink($this->docroot . '/index.php');
        @rmdir($this->docroot . '/admin');
        @rmdir($this->docroot);
    }

    public function testADeepLinkUnderAMountedSpaReachesTheFrontControllerWithItsFullPath(): void
    {
        $port = random_int(20000, 40000);
        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
        $cmd = sprintf(
            'GLUEFUL_AUTOLOAD=%s exec %s -d display_errors=0 -S 127.0.0.1:%d -t %s %s',
            escapeshellarg($autoload),
            escapeshellarg(PHP_BINARY),
            $port,
            escapeshellarg($this->docroot),
            escapeshellarg(dirname(__DIR__, 3) . '/router.php'),
        );
        $proc = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        self::assertIsResource($proc, 'could not start the built-in server');

        try {
            $body = $this->fetchWithRetry("http://127.0.0.1:{$port}/admin/setup");
            [$pathInfo, $baseUrl] = explode("\n", trim($body)) + [1 => ''];

            self::assertSame('/admin/setup', $pathInfo, 'the router script must not let the built-in server strip the mount prefix');
            self::assertSame('', $baseUrl);
        } finally {
            proc_terminate($proc, 9);
            proc_close($proc);
        }
    }

    private function fetchWithRetry(string $url): string
    {
        for ($i = 0; $i < 40; $i++) {
            $body = @file_get_contents($url);
            if ($body !== false) {
                return $body;
            }
            usleep(100_000);
        }
        self::fail("server did not answer at {$url}");
    }
}

<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Extensions;

use PHPUnit\Framework\TestCase;

/**
 * The extension list (config/extensions.php) and the extension cache (bootstrap/cache/extensions.php)
 * are PHP files the framework writes and then requires. A process with OPcache on keeps serving the
 * compiled old file, for good when timestamp checks are off, so each writer invalidates the file it
 * wrote: a later require in the same process, a web request under PHP-FPM included, sees the new one.
 */
final class ExtensionFilesOpcacheTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/glueful-opcache-' . bin2hex(random_bytes(4));
        mkdir($this->base . '/config', 0777, true);
        mkdir($this->base . '/bootstrap/cache', 0777, true);
        file_put_contents($this->base . '/config/extensions.php', "<?php\n\nreturn [\n    'enabled' => [\n    ],\n];\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->base));
    }

    /** @return list<string>|null the providers the child's second require saw; null without OPcache */
    private function child(string $mode): ?array
    {
        $cmd = [
            PHP_BINARY,
            '-d', 'zend_extension=opcache',
            '-d', 'opcache.enable_cli=1',
            '-d', 'opcache.validate_timestamps=0',
            '-d', 'opcache.file_update_protection=0',
            dirname(__DIR__, 2) . '/Fixtures/opcache_extension_files_child.php',
            $mode,
            $this->base,
        ];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($proc);
        $out = trim((string) stream_get_contents($pipes[1]));
        $err = (string) stream_get_contents($pipes[2]);
        proc_close($proc);
        $last = trim((string) strrchr("\n" . $out, "\n"));
        if ($last === 'none') {
            return null;
        }
        $decoded = json_decode($last, true);
        self::assertIsArray($decoded, $out . $err);
        return $decoded;
    }

    public function testTheStateWriterInvalidatesTheListItWrote(): void
    {
        $enabled = $this->child('config');
        if ($enabled === null) {
            self::markTestSkipped('OPcache is not available in this PHP');
        }
        self::assertSame(['OpcacheChildAlphaProvider'], $enabled);
    }

    public function testTheCacheWriterInvalidatesTheCacheItWrote(): void
    {
        $providers = $this->child('cache');
        if ($providers === null) {
            self::markTestSkipped('OPcache is not available in this PHP');
        }
        self::assertSame(['OpcacheChildAlphaProvider', 'OpcacheChildBetaProvider'], $providers);
    }
}

<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Container\Providers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Console\Commands\VersionCommand;
use Glueful\Container\Providers\ConsoleProvider;
use Glueful\Container\Providers\TagCollector;
use PHPUnit\Framework\TestCase;

/**
 * The production command manifest used to live in a SHARED temp file (the framework package's
 * own storage/ never exists in a dist install, so every host fell through to
 * /tmp/glueful_commands_manifest.php) and was trusted verbatim. A manifest written by an older
 * framework on the same host — or by another site's user — then fed phantom command classes
 * into every boot: container compilation failed on them, and resolving the tagged commands
 * threw a 500 out of the console itself. The manifest belongs to the APP's storage/cache and
 * every cached class is re-validated before use.
 */
final class ConsoleProviderCacheTest extends TestCase
{
    private const PHANTOM = 'Glueful\\Console\\Commands\\Archive\\ManageCommand';

    private string $base;
    private ?string $previousEnv = null;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/glueful-console-cache-' . uniqid('', true);
        mkdir($this->base . '/storage/cache', 0755, true);
        $this->previousEnv = $_ENV['APP_ENV'] ?? null;
        $_ENV['APP_ENV'] = 'production';
    }

    protected function tearDown(): void
    {
        if ($this->previousEnv === null) {
            unset($_ENV['APP_ENV']);
        } else {
            $_ENV['APP_ENV'] = $this->previousEnv;
        }
        @unlink($this->manifest());
        @rmdir($this->base . '/storage/cache');
        @rmdir($this->base . '/storage');
        @rmdir($this->base);
    }

    public function testAStaleManifestEntryIsDroppedAndTheManifestRewritten(): void
    {
        file_put_contents(
            $this->manifest(),
            "<?php\nreturn " . var_export([VersionCommand::class, self::PHANTOM], true) . ";\n",
        );

        $defs = $this->provider()->defs();

        self::assertArrayNotHasKey(self::PHANTOM, $defs, 'a cached class that no longer exists must not be registered');
        self::assertArrayHasKey(VersionCommand::class, $defs, 'real commands are still registered');

        $rewritten = require $this->manifest();
        self::assertNotContains(self::PHANTOM, $rewritten, 'the manifest is rewritten without the phantom');
        self::assertContains(VersionCommand::class, $rewritten);
    }

    public function testTheManifestIsWrittenToTheAppStorageCache(): void
    {
        self::assertFileDoesNotExist($this->manifest());

        $this->provider()->defs();

        self::assertFileExists($this->manifest(), 'production writes the manifest under the app base path, not a shared temp file');
    }

    private function provider(): ConsoleProvider
    {
        return new ConsoleProvider(new TagCollector(), new ApplicationContext($this->base, 'production'));
    }

    private function manifest(): string
    {
        return $this->base . '/storage/cache/glueful_commands_manifest.php';
    }
}

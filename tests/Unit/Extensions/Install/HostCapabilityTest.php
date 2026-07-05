<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions\Install;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Install\HostCapability;
use Glueful\Support\Process\ComposerBinaryResolver;
use PHPUnit\Framework\TestCase;

final class HostCapabilityTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];
    private ?string $prevComposerEnv = null;

    protected function setUp(): void
    {
        // Make composer resolution deterministic regardless of the CI PATH:
        // point COMPOSER_BINARY at a known executable so forInstall() reaches the
        // writability checks instead of short-circuiting on composer_missing.
        $this->prevComposerEnv = getenv('COMPOSER_BINARY') ?: null;
        putenv('COMPOSER_BINARY=' . PHP_BINARY);
    }

    protected function tearDown(): void
    {
        if ($this->prevComposerEnv === null) {
            putenv('COMPOSER_BINARY');
        } else {
            putenv('COMPOSER_BINARY=' . $this->prevComposerEnv);
        }
        foreach ($this->tempDirs as $dir) {
            $this->rmrf($dir);
        }
    }

    public function test_fully_writable_base_passes(): void
    {
        $base = $this->makeBase();
        mkdir($base . '/config', 0755, true);
        mkdir($base . '/bootstrap/cache', 0755, true);

        $cap = new HostCapability(ApplicationContext::forTesting($base), new ComposerBinaryResolver());
        $this->assertNull($cap->forInstall());
        $this->assertNull($cap->forToggle());
    }

    public function test_flags_a_readonly_existing_path(): void
    {
        $base = $this->makeBase();
        mkdir($base . '/config', 0755, true);
        mkdir($base . '/bootstrap/cache', 0755, true);
        chmod($base . '/bootstrap/cache', 0555); // exists, not writable

        $cap = new HostCapability(ApplicationContext::forTesting($base), new ComposerBinaryResolver());
        $result = $cap->forToggle();

        $this->assertIsArray($result);
        $this->assertSame('read_only_filesystem', $result['reason']);
        $this->assertStringContainsString('bootstrap/cache', $result['detail']);
    }

    public function test_flags_missing_vendor_under_unwritable_base(): void
    {
        // Base exists but is read-only; vendor/ and composer.lock do NOT exist yet.
        // A naive file_exists() check would skip them and pass — it must not.
        $base = $this->makeBase(0555);

        $cap = new HostCapability(ApplicationContext::forTesting($base), new ComposerBinaryResolver());
        $result = $cap->forInstall();

        $this->assertIsArray($result, 'missing vendor/composer.lock under a read-only base must fail preflight');
        $this->assertSame('read_only_filesystem', $result['reason']);
    }

    private function makeBase(int $mode = 0755): string
    {
        $dir = sys_get_temp_dir() . '/hc_' . bin2hex(random_bytes(6));
        mkdir($dir, 0755, true);
        $this->tempDirs[] = $dir;
        if ($mode !== 0755) {
            chmod($dir, $mode);
        }
        return $dir;
    }

    private function rmrf(string $dir): void
    {
        if (!file_exists($dir)) {
            return;
        }
        @chmod($dir, 0755);
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

<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions;

use Glueful\Extensions\ExtensionStateWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExtensionStateWriter::class)]
final class ExtensionStateWriterTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/glueful-sw-' . uniqid('', true) . '.php';
        file_put_contents($this->path, "<?php\n\nreturn [\n    'enabled' => [\n    ],\n];\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @unlink($this->path . '.bak');
    }

    /** @return list<string> */
    private function loaded(): array
    {
        return (require $this->path)['enabled'];
    }

    public function testAddAppendsAndIsIdempotent(): void
    {
        $w = new ExtensionStateWriter();
        $w->enable($this->path, 'Glueful\\Extensions\\Aegis\\Services\\AegisServiceProvider');
        $w->enable($this->path, 'Glueful\\Extensions\\Aegis\\Services\\AegisServiceProvider'); // idempotent

        $this->assertSame(
            ['Glueful\\Extensions\\Aegis\\Services\\AegisServiceProvider'],
            $this->loaded()
        );
    }

    public function testRemove(): void
    {
        $w = new ExtensionStateWriter();
        $w->enable($this->path, 'A\\B');
        $w->enable($this->path, 'C\\D');
        $w->disable($this->path, 'A\\B');
        $this->assertSame(['C\\D'], array_values($this->loaded()));
    }

    public function testDryRunWritesNothing(): void
    {
        $before = file_get_contents($this->path);
        (new ExtensionStateWriter())->enable($this->path, 'A\\B', dryRun: true);
        $this->assertSame($before, file_get_contents($this->path));
    }

    public function testBackupCreated(): void
    {
        (new ExtensionStateWriter())->enable($this->path, 'A\\B', backup: true);
        $this->assertFileExists($this->path . '.bak');
    }

    public function testRefusesNonTrivialEnabled(): void
    {
        file_put_contents(
            $this->path,
            "<?php\nreturn ['enabled' => env('X') ? ['A\\\\B'] : []];\n"
        );
        $this->expectException(\RuntimeException::class);
        (new ExtensionStateWriter())->enable($this->path, 'C\\D');
    }

    public function testWrittenFileHasNoTrailingWhitespace(): void
    {
        $w = new ExtensionStateWriter();
        $w->enable($this->path, 'A\\B');
        $w->enable($this->path, 'C\\D');
        $w->disable($this->path, 'A\\B');

        $lines = explode("\n", (string) file_get_contents($this->path));
        foreach ($lines as $i => $line) {
            $this->assertSame(
                rtrim($line),
                $line,
                "line " . ($i + 1) . " has trailing whitespace: " . var_export($line, true)
            );
        }
    }

    public function testAcceptsCommentedDefaultTemplate(): void
    {
        // Mirrors the shipped config/extensions.php (a comment inside `enabled`).
        $commented = "<?php\nreturn [\n    'enabled' => [\n"
            . "        // 'Glueful\\\\Extensions\\\\Aegis\\\\Services\\\\AegisServiceProvider',\n"
            . "    ],\n];\n";
        file_put_contents($this->path, $commented);
        (new ExtensionStateWriter())->enable($this->path, 'X\\Y');
        $this->assertSame(['X\\Y'], array_values($this->loaded()));
    }
}

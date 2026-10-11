<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Installer;

use Glueful\Installer\EnvWriter;
use PHPUnit\Framework\TestCase;

final class EnvWriterTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/envwriter_' . uniqid() . '.env';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testAReplacedFileKeepsTheModeAndGroupOfTheOneItReplaces(): void
    {
        // The web server often reads .env through its group while the deploy user writes it: a fresh
        // temp file (0600, the writer's own group) would lock the web server out.
        file_put_contents($this->path, "APP_ENV=local\n");
        chmod($this->path, 0640);
        $group = filegroup($this->path);
        (new EnvWriter($this->path))->set('APP_ENV', 'production');
        clearstatcache();
        self::assertSame(0640, fileperms($this->path) & 0777);
        self::assertSame($group, filegroup($this->path));
        self::assertSame("APP_ENV=production\n", file_get_contents($this->path));
    }

    public function testWritingTheSameValuesLeavesTheFileUntouched(): void
    {
        file_put_contents($this->path, "APP_ENV=local\nDB_DRIVER=pgsql\n");
        $inode = fileinode($this->path);
        (new EnvWriter($this->path))->setMany(['APP_ENV' => 'local', 'DB_DRIVER' => 'pgsql']);
        clearstatcache();
        self::assertSame($inode, fileinode($this->path), 'nothing changed, nothing replaced');
    }

    public function testAppendsMissingKeyAndReadsItBack(): void
    {
        file_put_contents($this->path, "# comment\nAPP_ENV=local\n");
        $w = new EnvWriter($this->path);
        $w->set('DB_DRIVER', 'pgsql');

        self::assertSame('pgsql', $w->get('DB_DRIVER'));
        self::assertStringContainsString('# comment', file_get_contents($this->path));
        self::assertStringContainsString('APP_ENV=local', file_get_contents($this->path));
    }

    public function testUpdatesExistingKeyInPlace(): void
    {
        file_put_contents($this->path, "DB_DRIVER=sqlite\nAPP_ENV=local\n");
        $w = new EnvWriter($this->path);
        $w->set('DB_DRIVER', 'mysql');

        self::assertSame('mysql', $w->get('DB_DRIVER'));
        // Exactly one DB_DRIVER line.
        self::assertSame(1, substr_count(file_get_contents($this->path), 'DB_DRIVER='));
    }

    public function testQuotesAndRoundTripsValuesWithSpecialChars(): void
    {
        file_put_contents($this->path, "");
        $w = new EnvWriter($this->path);
        $password = 'p@ss word#with="quotes"';
        $w->set('DB_PASSWORD', $password);

        $reread = new EnvWriter($this->path);
        self::assertSame($password, $reread->get('DB_PASSWORD'));
    }

    public function testSetManyAndPreservesOrder(): void
    {
        file_put_contents($this->path, "A=1\nB=2\n");
        $w = new EnvWriter($this->path);
        $w->setMany(['B' => '20', 'C' => '3']);

        $lines = explode("\n", trim(file_get_contents($this->path)));
        self::assertSame('A=1', $lines[0]);
        self::assertSame('B=20', $lines[1]);
        self::assertSame('C=3', $lines[2]);
    }
}

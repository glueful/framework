<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\DevelopmentQueryMonitor;
use PHPUnit\Framework\TestCase;

/**
 * The development query log resolved its path from the framework's own folder when no context was
 * set — vendor/glueful/storage/logs in an app — and `migrate:run` in a fresh app died on
 * file_put_contents(): the folder doesn't exist and is often not writable. A dev-only diagnostic must
 * write under the app, create its folder, and never break the command it observes.
 */
final class DevelopmentQueryMonitorLogPathTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/glueful-querymon-' . uniqid('', true);
        mkdir($this->base, 0777, true);
        (new \ReflectionProperty(DevelopmentQueryMonitor::class, 'enabled'))->setValue(null, true);
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(DevelopmentQueryMonitor::class, 'enabled'))->setValue(null, false);
        DevelopmentQueryMonitor::setContext(null);
        DevelopmentQueryMonitor::reset();
        exec('rm -rf ' . escapeshellarg($this->base));
    }

    public function testQueriesAreLoggedUnderTheAppAndTheFolderIsCreated(): void
    {
        DevelopmentQueryMonitor::setContext(new ApplicationContext($this->base, 'development'));

        DevelopmentQueryMonitor::logQuery('SELECT 1', [], 0.001, 'probe');

        $log = $this->base . '/storage/logs/queries-' . date('Y-m-d') . '.log';
        self::assertFileExists($log);
        self::assertStringContainsString('SELECT 1', (string) file_get_contents($log));
    }

    public function testWithoutAContextNothingIsWrittenAndNothingThrows(): void
    {
        $fallback = dirname(__DIR__, 4) . '/../storage/logs/queries-' . date('Y-m-d') . '.log';
        $before = is_file($fallback) ? filesize($fallback) : null;

        DevelopmentQueryMonitor::logQuery('SELECT 2', [], 0.001);

        clearstatcache();
        self::assertSame($before, is_file($fallback) ? filesize($fallback) : null);
    }

    public function testAnUnwritableLogFolderNeverBreaksTheQuery(): void
    {
        file_put_contents($this->base . '/storage', 'not a directory');
        DevelopmentQueryMonitor::setContext(new ApplicationContext($this->base, 'development'));

        DevelopmentQueryMonitor::logQuery('SELECT 3', [], 0.001);

        self::assertSame(1, DevelopmentQueryMonitor::getStats()['query_count']);
    }
}

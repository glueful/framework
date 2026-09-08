<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Security;

use Glueful\Security\RecommendationLog;
use PHPUnit\Framework\TestCase;

/**
 * Production-boot RECOMMENDATIONS used to hit error_log on every request (PHP-FPM boots per
 * request), so a correctly configured host still got one line per hit for every recommendation
 * that applied. They are advisory: log them once per boot cache — again only when the set
 * changes or the cache is cleared — while WARNINGS keep their per-request logging elsewhere.
 */
final class RecommendationLogTest extends TestCase
{
    private string $dir;

    /** @var list<string> */
    private array $sink = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/reclog_' . uniqid();
        mkdir($this->dir, 0755, true);
        $this->sink = [];
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function log(): RecommendationLog
    {
        return new RecommendationLog($this->dir, function (string $line): void {
            $this->sink[] = $line;
        });
    }

    public function testTheFirstBootLogsEveryRecommendationWithTheSecurityPrefix(): void
    {
        $logged = $this->log()->logOnce(['CSP_HEADER is empty - …', 'HSTS_HEADER not configured - …']);

        self::assertTrue($logged);
        self::assertSame([
            '[security] RECOMMENDATION: CSP_HEADER is empty - …',
            '[security] RECOMMENDATION: HSTS_HEADER not configured - …',
        ], $this->sink);
    }

    public function testTheSameSetIsNotLoggedAgainOnLaterBoots(): void
    {
        $this->log()->logOnce(['CSP_HEADER is empty - …']);
        $this->sink = [];

        self::assertFalse($this->log()->logOnce(['CSP_HEADER is empty - …']));
        self::assertFalse($this->log()->logOnce(['CSP_HEADER is empty - …']));
        self::assertSame([], $this->sink, 'a fresh instance per boot, same marker on disk: silent');
    }

    public function testAChangedSetLogsAgain(): void
    {
        $this->log()->logOnce(['CSP_HEADER is empty - …']);
        $this->sink = [];

        self::assertTrue($this->log()->logOnce(['CSP_HEADER is empty - …', 'LOG_LEVEL set to debug - …']));
        self::assertCount(2, $this->sink);
    }

    public function testClearingTheCacheDirectoryLogsAgain(): void
    {
        $this->log()->logOnce(['CSP_HEADER is empty - …']);
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        $this->sink = [];

        self::assertTrue($this->log()->logOnce(['CSP_HEADER is empty - …']));
    }

    public function testNothingToRecommendLogsNothingAndForgetsTheMarker(): void
    {
        $this->log()->logOnce(['CSP_HEADER is empty - …']);
        $this->sink = [];

        self::assertFalse($this->log()->logOnce([]));
        self::assertSame([], $this->sink);
        // The operator fixed it; if it regresses later it must be logged again.
        self::assertTrue($this->log()->logOnce(['CSP_HEADER is empty - …']));
    }

    public function testAnUnwritableCacheDirectoryFallsBackToLoggingEveryBoot(): void
    {
        $notADir = $this->dir . '/file';
        file_put_contents($notADir, 'x');
        $log = new RecommendationLog($notADir, function (string $line): void {
            $this->sink[] = $line;
        });

        self::assertTrue($log->logOnce(['CSP_HEADER is empty - …']));
        self::assertTrue($log->logOnce(['CSP_HEADER is empty - …']));
        self::assertCount(2, $this->sink);
    }
}

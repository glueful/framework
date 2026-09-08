<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Services;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Services\HealthService;
use PHPUnit\Framework\TestCase;

/**
 * The config health check folded production RECOMMENDATIONS into its "warning" status, so a
 * host that deliberately ran without, say, a CSP reported degraded health to every monitor.
 * Recommendations are advisory: they ride along in the payload under their own key while the
 * status stays "ok". Production WARNINGS (debug on, weak DB password …) still fail the check.
 */
final class HealthConfigurationCheckTest extends TestCase
{
    private const KEYS = [
        'APP_ENV', 'APP_DEBUG', 'APP_KEY', 'JWT_KEY', 'DB_PASSWORD', 'HSTS_HEADER', 'CSP_HEADER',
        'FORCE_HTTPS', 'LOG_LEVEL', 'CORS_ALLOWED_ORIGINS',
    ];

    /** @var array<string, string|null> */
    private array $saved = [];

    private string $dir;

    protected function setUp(): void
    {
        foreach (self::KEYS as $k) {
            $this->saved[$k] = $_ENV[$k] ?? null;
            unset($_ENV[$k]);
        }
        $this->dir = sys_get_temp_dir() . '/health_cfg_' . uniqid();
        mkdir($this->dir, 0755, true);
        file_put_contents($this->dir . '/.env', "APP_ENV=production\n");

        $_ENV['APP_ENV'] = 'production';
        $_ENV['APP_DEBUG'] = 'false';
        $_ENV['APP_KEY'] = str_repeat('k', 40);
        $_ENV['JWT_KEY'] = str_repeat('j', 40);
        $_ENV['DB_PASSWORD'] = 'a-strong-database-password';
        $_ENV['HSTS_HEADER'] = 'max-age=31536000';
        $_ENV['CSP_HEADER'] = '';
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            if ($v === null) {
                unset($_ENV[$k]);
            } else {
                $_ENV[$k] = $v;
            }
        }
        @unlink($this->dir . '/.env');
        @rmdir($this->dir);
    }

    private function check(): array
    {
        return HealthService::checkConfiguration(ApplicationContext::forTesting($this->dir));
    }

    public function testARecommendationLeavesTheStatusOkAndIsListedUnderItsOwnKey(): void
    {
        $check = $this->check();

        self::assertSame('ok', $check['status'], 'advisory recommendations must not degrade health');
        self::assertSame('Configuration is valid', $check['message']);
        self::assertArrayHasKey('recommendations', $check);
        self::assertCount(1, $check['recommendations']);
        self::assertStringContainsString('CSP_HEADER', $check['recommendations'][0]);
        self::assertArrayNotHasKey('warnings', $check);
    }

    public function testNoRecommendationsMeansNoRecommendationsKey(): void
    {
        $_ENV['CSP_HEADER'] = "default-src 'self'";

        $check = $this->check();

        self::assertSame('ok', $check['status']);
        self::assertArrayNotHasKey('recommendations', $check);
    }

    public function testAProductionWarningStillFailsTheCheck(): void
    {
        $_ENV['APP_DEBUG'] = 'true';

        $check = $this->check();

        self::assertSame('error', $check['status']);
        self::assertNotEmpty(array_filter($check['issues'], static fn (string $i): bool => str_contains($i, 'APP_DEBUG')));
        self::assertArrayHasKey('recommendations', $check, 'the CSP recommendation still rides along');
    }
}

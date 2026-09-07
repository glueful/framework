<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Security;

use Glueful\Security\SecurityManager;
use PHPUnit\Framework\TestCase;

/**
 * In production `config('app.force_https')` defaults to TRUE when FORCE_HTTPS is unset, so the
 * boot-time recommendation "FORCE_HTTPS not enabled" fired on every correctly configured
 * production host. It must read the effective value, not the raw variable.
 */
final class ProductionValidationHttpsTest extends TestCase
{
    /** @var array<string, string|null> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['APP_ENV', 'FORCE_HTTPS', 'APP_DEBUG'] as $k) {
            $this->saved[$k] = $_ENV[$k] ?? null;
        }
        $_ENV['APP_ENV'] = 'production';
        $_ENV['APP_DEBUG'] = 'false';
        unset($_ENV['FORCE_HTTPS']);
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
    }

    public function testUnsetForceHttpsIsNotRecommendedAgainstInProduction(): void
    {
        $recommendations = SecurityManager::validateProductionEnvironment()['recommendations'] ?? [];

        self::assertSame([], array_values(array_filter(
            $recommendations,
            static fn (string $r): bool => str_contains($r, 'FORCE_HTTPS'),
        )), 'unset FORCE_HTTPS means enabled in production');
    }

    public function testExplicitlyDisabledForceHttpsIsStillRecommendedAgainst(): void
    {
        $_ENV['FORCE_HTTPS'] = 'false';

        $recommendations = SecurityManager::validateProductionEnvironment()['recommendations'] ?? [];

        self::assertNotEmpty(array_filter(
            $recommendations,
            static fn (string $r): bool => str_contains($r, 'FORCE_HTTPS'),
        ));
    }
}

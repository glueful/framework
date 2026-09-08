<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Security;

use Glueful\Security\SecurityManager;
use PHPUnit\Framework\TestCase;

/**
 * The production-boot recommendation about `CSP_HEADER` must describe what the variable actually
 * does now that the framework sends it: nothing is sent while it is empty, a set value goes out
 * verbatim, and `CSP_REPORT_ONLY=true` lets an operator audit before enforcing. Setting the
 * variable silences the recommendation.
 */
final class ProductionValidationCspTest extends TestCase
{
    /** @var array<string, string|null> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['APP_ENV', 'APP_DEBUG', 'CSP_HEADER'] as $k) {
            $this->saved[$k] = $_ENV[$k] ?? null;
        }
        $_ENV['APP_ENV'] = 'production';
        $_ENV['APP_DEBUG'] = 'false';
        unset($_ENV['CSP_HEADER']);
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

    public function testEmptyCspHeaderRecommendationExplainsWhatSettingItDoes(): void
    {
        $_ENV['CSP_HEADER'] = '';

        $csp = $this->cspRecommendations();

        self::assertCount(1, $csp);
        self::assertSame(
            'CSP_HEADER is empty - no Content-Security-Policy is sent on responses that do not set their own; '
            . 'set it to a policy to send verbatim (CSP_REPORT_ONLY=true audits before enforcing)',
            $csp[0],
        );
    }

    public function testConfiguredCspHeaderIsNotRecommendedAgainst(): void
    {
        $_ENV['CSP_HEADER'] = "default-src 'self'";

        self::assertSame([], $this->cspRecommendations());
    }

    /** @return list<string> */
    private function cspRecommendations(): array
    {
        $recommendations = SecurityManager::validateProductionEnvironment()['recommendations'] ?? [];

        return array_values(array_filter(
            $recommendations,
            static fn (string $r): bool => str_contains($r, 'CSP_HEADER'),
        ));
    }
}

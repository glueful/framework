<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Console\Commands\Security;

use Glueful\Console\Commands\Security\ReportCommand;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Smoke tests for security:report.
 *
 * Pins the post-TG-2 contract: the report exposes only sections backed by
 * real introspection (production readiness, environment, system info,
 * compliance, recommendations). The previous fabricated telemetry sections
 * (authentication, audit_summary, vulnerabilities, metrics) must stay gone.
 */
final class ReportCommandTest extends TestCase
{
    private function makeCommand(): ReportCommand
    {
        $stubContainer = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException("Test stub: no service '{$id}'");
            }

            public function has(string $id): bool
            {
                return false;
            }
        };

        return new ReportCommand($stubContainer);
    }

    public function testJsonReportContainsOnlyRealSections(): void
    {
        $tester = new CommandTester($this->makeCommand());
        $exitCode = $tester->execute(['--format' => 'json']);

        self::assertSame(0, $exitCode, $tester->getDisplay());

        $display = $tester->getDisplay();
        $jsonStart = strpos($display, '{');
        self::assertNotFalse($jsonStart, 'No JSON payload found in output');

        $jsonEnd = strrpos($display, '}');
        self::assertNotFalse($jsonEnd);
        $json = substr($display, $jsonStart, $jsonEnd - $jsonStart + 1);

        $data = json_decode($json, true);
        self::assertIsArray($data, 'Report output was not valid JSON');

        self::assertArrayHasKey('metadata', $data);
        self::assertArrayHasKey('security_config', $data);
        self::assertArrayHasKey('system_health', $data);
        self::assertArrayHasKey('recommendations', $data);
        self::assertArrayHasKey('summary', $data);

        self::assertArrayNotHasKey('authentication', $data);
        self::assertArrayNotHasKey('audit_summary', $data);
        self::assertArrayNotHasKey('vulnerabilities', $data);
        self::assertArrayNotHasKey('metrics', $data);
        // Compliance was hardcoded ('Partial', 'Enabled', ...) with no real
        // introspection — removed in the TG-2 follow-up so the report only
        // surfaces sections derived from real signals.
        self::assertArrayNotHasKey('compliance', $data);
    }

    public function testProductionReadinessSectionUsesRealSecurityManagerData(): void
    {
        $tester = new CommandTester($this->makeCommand());
        $tester->execute(['--format' => 'json']);

        $display = $tester->getDisplay();
        $jsonStart = strpos($display, '{');
        $jsonEnd = strrpos($display, '}');
        self::assertNotFalse($jsonStart);
        self::assertNotFalse($jsonEnd);
        $data = json_decode(substr($display, $jsonStart, $jsonEnd - $jsonStart + 1), true);
        self::assertIsArray($data);

        $score = $data['security_config']['production_readiness']['score'];
        self::assertIsInt($score);
        self::assertGreaterThanOrEqual(0, $score);
        self::assertLessThanOrEqual(100, $score);

        self::assertArrayHasKey('warnings', $data['security_config']['production_readiness']);
        self::assertArrayHasKey('recommendations', $data['security_config']['production_readiness']);
    }

    public function testInvalidFormatReturnsFailure(): void
    {
        $tester = new CommandTester($this->makeCommand());
        $exitCode = $tester->execute(['--format' => 'pdf']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Invalid format: pdf', $tester->getDisplay());
    }

    public function testRemovedOptionsAreNoLongerAccepted(): void
    {
        $tester = new CommandTester($this->makeCommand());

        $this->expectException(\Symfony\Component\Console\Exception\InvalidOptionException::class);
        $tester->execute(['--include-vulnerabilities' => true]);
    }

    public function testEmailOptionIsRemoved(): void
    {
        $tester = new CommandTester($this->makeCommand());

        $this->expectException(\Symfony\Component\Console\Exception\InvalidOptionException::class);
        $tester->execute(['--email' => 'test@example.com']);
    }
}

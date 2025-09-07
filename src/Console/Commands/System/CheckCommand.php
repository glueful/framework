<?php

namespace Glueful\Console\Commands\System;

use Glueful\Console\BaseCommand;
use Glueful\Services\HealthService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * System Check Command
 * - Comprehensive system health and configuration validation
 * - Production readiness assessment
 * - Automatic issue detection and fixing capabilities
 * - Detailed reporting with verbose output options
 * - Security and performance checks
 * @package Glueful\Console\Commands\System
 */
#[AsCommand(
    name: 'system:check',
    description: 'Validate framework installation and configuration'
)]
class CheckCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setDescription('Validate framework installation and configuration')
             ->setHelp('This command validates the framework installation, checks system requirements, ' .
                      'and verifies configuration for optimal performance and security.')
             ->addOption(
                 'details',
                 'd',
                 InputOption::VALUE_NONE,
                 'Show detailed information for each check'
             )
             ->addOption(
                 'fix',
                 'f',
                 InputOption::VALUE_NONE,
                 'Attempt to automatically fix common issues'
             )
             ->addOption(
                 'production',
                 'p',
                 InputOption::VALUE_NONE,
                 'Check production readiness and security'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $verbose = (bool) $input->getOption('details');
        $fix = (bool) $input->getOption('fix');
        $production = (bool) $input->getOption('production');

        $this->info("🔍 Glueful Framework System Check");
        $this->line("");

        $checks = [
            'PHP Version' => $this->checkPhpVersion(),
            'Extensions' => $this->checkPhpExtensions(),
            'Permissions' => $this->checkPermissions($fix),
            'Configuration' => $this->checkConfiguration($production),
            'Database' => $this->checkDatabase(),
            'Security' => $this->checkSecurity($production)
        ];

        $passed = 0;
        $total = count($checks);

        foreach ($checks as $category => $result) {
            $status = $result['passed'] === true ? '✅' : '❌';
            $this->line(sprintf("%-15s %s %s", $category, $status, $result['message']));

            if ($verbose === true && count($result['details']) > 0) {
                foreach ($result['details'] as $detail) {
                    $this->line("                  $detail");
                }
            }

            if ($result['passed'] === true) {
                $passed++;
            }
        }

        $this->line("");
        if ($passed === $total) {
            $this->success("🎉 All checks passed! Framework is ready.");
            return self::SUCCESS;
        } else {
            $this->warning("⚠️  $passed/$total checks passed. Please address the issues above.");
            if ($verbose !== true) {
                $this->info("💡 Run with --details for more details");
            }
            return self::FAILURE;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function checkPhpVersion(): array
    {
        $required = '8.2.0';
        $current = PHP_VERSION;
        $passed = version_compare($current, $required, '>=');

        return [
            'passed' => $passed,
            'message' => $passed ?
                "PHP $current (>= $required)" :
                "PHP $current (requires >= $required)",
            'details' => $passed ? [] : [
                "Update PHP to version $required or higher",
                "Current version: $current"
            ]
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkPhpExtensions(): array
    {
        $required = ['pdo', 'pdo_mysql', 'json', 'mbstring', 'openssl', 'curl'];
        $missing = [];

        foreach ($required as $ext) {
            if (!extension_loaded($ext)) {
                $missing[] = $ext;
            }
        }

        return [
            'passed' => count($missing) === 0,
            'message' => count($missing) === 0 ?
                'All required extensions loaded' :
                'Missing extensions: ' . implode(', ', $missing),
            'details' => count($missing) === 0 ? [] : array_map(
                fn($ext) => "Install php-$ext extension",
                $missing
            )
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkPermissions(bool $fix = false): array
    {
        $dirs = [
            'storage' => 0755,
            'storage/logs' => 0755,
            'storage/cache' => 0755,
            'storage/sessions' => 0755
        ];

        $issues = [];
        $baseDir = base_path();

        foreach ($dirs as $dir => $requiredPerms) {
            $path = "$baseDir/$dir";

            if (!is_dir($path)) {
                if ($fix) {
                    mkdir($path, $requiredPerms, true);
                    $this->line("Created directory: $dir");
                } else {
                    $issues[] = "Directory missing: $dir";
                }
                continue;
            }

            if (!is_writable($path)) {
                if ($fix) {
                    chmod($path, $requiredPerms);
                    $this->line("Fixed permissions: $dir");
                } else {
                    $issues[] = "Directory not writable: $dir";
                }
            }
        }

        return [
            'passed' => count($issues) === 0,
            'message' => count($issues) === 0 ?
                'All directories writable' :
                count($issues) . ' permission issues',
            'details' => $fix ? [] : array_merge($issues, [
                'Run with --fix to attempt automatic fixes'
            ])
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkConfiguration(bool $production): array
    {
        $issues = [];

        // Check for .env file
        $envPath = base_path('.env');
        if (!file_exists($envPath)) {
            $issues[] = '.env file not found - copy .env.example';
        }

        // Production-specific checks
        if ($production === true) {
            $debugEnabled = getenv('APP_DEBUG') === 'true';
            if ($debugEnabled) {
                $issues[] = 'APP_DEBUG should be false in production';
            }

            $jwtSecret = getenv('JWT_SECRET');
            if ($jwtSecret === false || strlen($jwtSecret) < 32) {
                $issues[] = 'JWT_SECRET must be set and at least 32 characters';
            }
        }

        return [
            'passed' => count($issues) === 0,
            'message' => count($issues) === 0 ?
                'Configuration valid' :
                count($issues) . ' configuration issues',
            'details' => $issues
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkSecurity(bool $production): array
    {
        $issues = [];

        // Check if running as root (bad practice)
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $issues[] = 'Running as root user (security risk)';
        }

        // Check for common security files
        $publicEnv = base_path('public/.env');
        if (file_exists($publicEnv)) {
            $issues[] = '.env file in public directory (critical security risk)';
        }

        // Production-specific security checks
        if ($production === true) {
            $publicIndex = base_path('public/index.php');
            if (file_exists($publicIndex)) {
                $content = file_get_contents($publicIndex);
                if ($content !== false && strpos($content, 'error_reporting(E_ALL)') !== false) {
                    $issues[] = 'Error reporting enabled in production';
                }
            }
        }

        return [
            'passed' => count($issues) === 0,
            'message' => count($issues) === 0 ?
                'Security checks passed' :
                count($issues) . ' security issues found',
            'details' => $issues
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkDatabase(): array
    {
        try {
            // Use ConnectionValidator to avoid duplicate queries with bootstrap validation
            $healthResult = \Glueful\Database\ConnectionValidator::performHealthCheck();
            /** @var HealthService $healthService */
            $healthService = $this->getService(HealthService::class);
            $healthServiceClass = get_class($healthService);
            return $healthServiceClass::convertToSystemCheckFormat($healthResult['details'] ?? $healthResult);
        } catch (\Exception $e) {
            return [
                'passed' => false,
                'message' => 'Database check failed: ' . $e->getMessage(),
                'details' => [
                    'Ensure database configuration is correct in .env',
                    'Verify database server is running',
                    'Check database credentials'
                ]
            ];
        }
    }
}

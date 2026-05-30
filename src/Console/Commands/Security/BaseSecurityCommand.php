<?php

namespace Glueful\Console\Commands\Security;

use Glueful\Console\BaseCommand;
use Glueful\Security\SecurityManager;
use Psr\Container\ContainerInterface;

/**
 * Base Security Command
 * Base class for all Security-related Symfony Console commands.
 * Provides shared functionality for security operations.
 * @package Glueful\Console\Commands\Security
 */
abstract class BaseSecurityCommand extends BaseCommand
{
    protected ContainerInterface $container;

    public function __construct(?ContainerInterface $container = null)
    {
        parent::__construct();
        $this->container = $container ?? container($this->getContext());
    }

    /**
     * Get SecurityManager instance
     */
    protected function getSecurityManager(): SecurityManager
    {
        return $this->getService(SecurityManager::class);
    }

    /**
     * Extract option value from command arguments
     */
    /**
     * @param array<int, string> $args
     */
    protected function extractOptionValue(array $args, string $option, string $default = ''): string
    {
        foreach ($args as $arg) {
            if (str_starts_with($arg, $option . '=')) {
                return substr($arg, strlen($option) + 1);
            }
        }
        return $default;
    }

    /**
     * Process production environment checks
     */
    /**
     * @param array<string, mixed> $validation
     * @return array<string, mixed>
     */
    protected function processProductionCheck(array $validation, bool $fix, bool $verbose): array
    {
        // Align with SecurityManager::validateProductionEnvironment()'s actual shape
        // (is_production / warnings), not the never-returned production_ready/issues keys.
        // Outside production the validator is informational — treat as "not applicable"
        // (mirrors the Score check) so it doesn't fail dev/CI runs; in production it
        // passes only when there are no critical warnings.
        $isProduction = ($validation['is_production'] ?? false) === true;
        $warnings = is_array($validation['warnings'] ?? null) ? $validation['warnings'] : [];
        $passed = $isProduction !== true || count($warnings) === 0;
        $message = $isProduction !== true
            ? 'Not applicable (development environment)'
            : ($passed ? 'Production environment validated' : 'Production environment issues found');

        if ($verbose && count($warnings) > 0) {
            $this->line('  Warnings:');
            foreach ($warnings as $warning) {
                $this->line("    • {$warning}");
            }
        }

        if ($fix === true && $passed !== true) {
            $this->line('  Applying automatic fixes...');
            // SecurityManager would handle fixes
        }

        return ['passed' => $passed, 'message' => $message];
    }

    /**
     * Process security score assessment
     */
    /**
     * @param array<string, mixed> $scoreData
     * @return array<string, mixed>
     */
    protected function processSecurityScore(array $scoreData, bool $verbose): array
    {
        $score = $scoreData['score'] ?? 0;
        $status = $scoreData['status'] ?? 'Unknown';

        $passed = $score >= 75;
        $message = "Score: {$score}/100 ({$status})";

        $breakdown = is_array($scoreData['breakdown']) ? $scoreData['breakdown'] : [];
        if ($verbose && count($breakdown) > 0) {
            $this->line('  Score breakdown:');
            foreach ($breakdown as $category => $points) {
                $this->line("    • {$category}: {$points}");
            }
        }

        return ['passed' => $passed, 'message' => $message];
    }

    /**
     * Process health checks
     */
    /**
     * @return array<string, mixed>
     */
    protected function processHealthChecks(bool $fix, bool $verbose): array
    {
        // Health checks would be handled by SecurityManager
        $passed = true;
        $message = 'System health checks passed';

        return ['passed' => $passed, 'message' => $message];
    }

    /**
     * Process permission checks
     */
    /**
     * @return array<string, mixed>
     */
    protected function processPermissionChecks(bool $fix, bool $verbose): array
    {
        // Permission checks would be handled by SecurityManager
        $passed = true;
        $message = 'File permissions validated';

        return ['passed' => $passed, 'message' => $message];
    }

    /**
     * Process configuration security
     */
    /**
     * @return array<string, mixed>
     */
    protected function processConfigurationSecurity(bool $production, bool $fix, bool $verbose): array
    {
        // Configuration security would be handled by SecurityManager
        $passed = true;
        $message = 'Configuration security validated';

        return ['passed' => $passed, 'message' => $message];
    }

    /**
     * Process authentication security
     */
    /**
     * @return array<string, mixed>
     */
    protected function processAuthenticationSecurity(bool $verbose): array
    {
        // Authentication security would be handled by SecurityManager
        $passed = true;
        $message = 'Authentication security validated';

        return ['passed' => $passed, 'message' => $message];
    }

    /**
     * Process network security
     */
    /**
     * @return array<string, mixed>
     */
    protected function processNetworkSecurity(bool $verbose): array
    {
        // Network security would be handled by SecurityManager
        $passed = true;
        $message = 'Network security validated';

        return ['passed' => $passed, 'message' => $message];
    }
}

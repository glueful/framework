<?php

namespace Glueful\Console\Commands\Security;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Console\BaseCommand;
use Glueful\Database\Connection;
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

    public function __construct(?ContainerInterface $container = null, ?ApplicationContext $context = null)
    {
        // Hand BaseCommand the booted container and context: with no arguments it builds a fresh,
        // never-booted context, and every check would read that instead of the app's config.
        parent::__construct($container, $context);
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
     * Health: the database answers a query.
     *
     * @return array<string, mixed>
     */
    protected function processHealthChecks(bool $fix, bool $verbose): array
    {
        try {
            Connection::fromContext($this->getContext())->getPDO()->query('SELECT 1');
        } catch (\Throwable $e) {
            return $this->stepResult(['The database did not answer: ' . $e->getMessage()], 'Database reachable');
        }

        return $this->stepResult([], 'Database reachable');
    }

    /**
     * File permissions: .env is readable by its owner only, and storage/ is writable.
     *
     * @return array<string, mixed>
     */
    protected function processPermissionChecks(bool $fix, bool $verbose): array
    {
        $problems = [];
        $env = base_path($this->getContext(), '.env');
        if (is_file($env)) {
            $mode = fileperms($env) & 0o777;
            if (($mode & 0o007) !== 0) {
                $problems[] = sprintf('.env is readable or writable by every user (mode %o); chmod 600 it', $mode);
            }
        }
        $storage = base_path($this->getContext(), 'storage');
        if (is_dir($storage) && !is_writable($storage)) {
            $problems[] = 'storage/ is not writable by this process';
        }

        return $this->stepResult($problems, '.env is private and storage/ is writable');
    }

    /**
     * Configuration: the signing and encryption secrets are set and long enough.
     *
     * @return array<string, mixed>
     */
    protected function processConfigurationSecurity(bool $production, bool $fix, bool $verbose): array
    {
        $problems = [];
        $secrets = [
            'APP_KEY' => config($this->getContext(), 'app.key'),
            'JWT_KEY' => config($this->getContext(), 'session.jwt_key'),
            'TOKEN_SALT' => config($this->getContext(), 'session.token_salt'),
        ];
        foreach ($secrets as $name => $value) {
            if (!is_string($value) || $value === '') {
                $problems[] = "{$name} is not set";
            } elseif (strlen($value) < 32) {
                $problems[] = "{$name} is shorter than 32 characters";
            }
        }

        return $this->stepResult($problems, 'Signing and encryption secrets are set');
    }

    /**
     * Authentication: access tokens live at most a day and refresh tokens at most 90 days.
     *
     * @return array<string, mixed>
     */
    protected function processAuthenticationSecurity(bool $verbose): array
    {
        $problems = [];
        $access = (int) config($this->getContext(), 'session.access_token_lifetime', 3600);
        $refresh = (int) config($this->getContext(), 'session.refresh_token_lifetime', 604800);
        if ($access > 86400) {
            $problems[] = "ACCESS_TOKEN_LIFETIME is {$access}s; keep access tokens to a day or less";
        }
        if ($refresh > 90 * 86400) {
            $problems[] = "REFRESH_TOKEN_LIFETIME is {$refresh}s; keep refresh tokens to 90 days or less";
        }

        return $this->stepResult($problems, 'Token lifetimes are bounded');
    }

    /**
     * Network: CORS never lets every origin send credentials.
     *
     * @return array<string, mixed>
     */
    protected function processNetworkSecurity(bool $verbose): array
    {
        $problems = [];
        $origins = (array) config($this->getContext(), 'cors.allowed_origins', []);
        $credentials = (bool) config($this->getContext(), 'cors.allow_credentials', false);
        if ($credentials && in_array('*', $origins, true)) {
            $problems[] = 'CORS allows every origin (*) with credentials; list the origins instead';
        }

        return $this->stepResult($problems, 'CORS does not open credentials to every origin');
    }

    /**
     * @param list<string> $problems
     * @return array{passed: bool, message: string}
     */
    private function stepResult(array $problems, string $okMessage): array
    {
        return $problems === []
            ? ['passed' => true, 'message' => $okMessage]
            : ['passed' => false, 'message' => implode('; ', $problems)];
    }
}

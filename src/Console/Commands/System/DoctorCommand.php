<?php

namespace Glueful\Console\Commands\System;

use Glueful\Console\BaseCommand;
use Glueful\Routing\RouteCache;
use Glueful\Services\HealthService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'doctor',
    description: 'Run quick health checks for local development'
)]
class DoctorCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setDescription('Run quick health checks for local development')
            ->setHelp('Checks environment, cache, database, routing cache, and basic filesystem permissions.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->info('Glueful Doctor');
        $this->line('');

        $checks = [
            'Env' => $this->checkEnv(),
            'App Key' => $this->checkAppKey(),
            'Cache' => $this->checkCache(),
            'Database' => $this->checkDatabase(),
            'Route Cache' => $this->checkRouteCache(),
            'Storage' => $this->checkStoragePermissions(),
        ];

        $passed = 0;
        $total = count($checks);

        foreach ($checks as $name => $result) {
            $status = $result['passed'] ? '✅' : '❌';
            $this->line(sprintf('%-12s %s %s', $name, $status, $result['message']));
            if (count($result['details']) > 0) {
                foreach ($result['details'] as $detail) {
                    $this->line('              ' . $detail);
                }
            }
            if ($result['passed']) {
                $passed++;
            }
        }

        $this->line('');
        if ($passed === $total) {
            $this->success('All checks passed.');
            return self::SUCCESS;
        }

        $this->warning("{$passed}/{$total} checks passed.");
        return self::FAILURE;
    }

    /**
     * @return array{passed: bool, message: string, details: array<int, string>}
     */
    private function checkEnv(): array
    {
        $dotenvLoaded = isset($_ENV['DOTENV_LOADED']) || isset($_SERVER['DOTENV_LOADED']);
        $hasEnvFile = file_exists(base_path($this->getContext(), '.env'));

        if ($hasEnvFile && !$dotenvLoaded) {
            return [
                'passed' => false,
                'message' => '.env exists but not loaded',
                'details' => ['Check bootstrap and ensure Dotenv is loaded early.'],
            ];
        }

        return [
            'passed' => true,
            'message' => $hasEnvFile ? '.env loaded' : 'No .env file (ok if using system env)',
            'details' => [],
        ];
    }

    /**
     * @return array{passed: bool, message: string, details: array<int, string>}
     */
    private function checkAppKey(): array
    {
        $key = env('APP_KEY');
        if ($key === null || $key === '') {
            return [
                'passed' => false,
                'message' => 'APP_KEY not set',
                'details' => ['Run: php glueful generate:key'],
            ];
        }

        return [
            'passed' => true,
            'message' => 'APP_KEY set',
            'details' => [],
        ];
    }

    /**
     * @return array{passed: bool, message: string, details: array<int, string>}
     */
    private function checkCache(): array
    {
        try {
            $result = HealthService::checkCache($this->getContext());
            $status = $result['status'] ?? 'error';
            $passed = $status === 'ok' || $status === 'warning';
            $message = $result['message'] ?? 'Cache check completed';

            return [
                'passed' => $passed,
                'message' => $message,
                'details' => $status === 'warning' && isset($result['suggestion'])
                    ? [$result['suggestion']]
                    : [],
            ];
        } catch (\Throwable $e) {
            return [
                'passed' => false,
                'message' => 'Cache check failed',
                'details' => [$e->getMessage()],
            ];
        }
    }

    /**
     * @return array{passed: bool, message: string, details: array<int, string>}
     */
    private function checkDatabase(): array
    {
        try {
            $result = HealthService::checkDatabase($this->getContext());
            $status = $result['status'] ?? 'error';
            $passed = $status === 'ok' || $status === 'warning';
            $message = $result['message'] ?? 'Database check completed';
            $details = [];

            if ($status === 'warning' && isset($result['suggestion'])) {
                $details[] = $result['suggestion'];
            }

            return [
                'passed' => $passed,
                'message' => $message,
                'details' => $details,
            ];
        } catch (\Throwable $e) {
            return [
                'passed' => false,
                'message' => 'Database check failed',
                'details' => [$e->getMessage()],
            ];
        }
    }

    /**
     * @return array{passed: bool, message: string, details: array<int, string>}
     */
    private function checkRouteCache(): array
    {
        try {
            /** @var RouteCache $cache */
            $cache = $this->getService(RouteCache::class);
            $cachedSignature = $cache->getCachedSignature();
            $currentSignature = $cache->getSignature();
            $valid = $cachedSignature !== null && hash_equals($cachedSignature, $currentSignature);

            return [
                'passed' => $valid,
                'message' => $valid ? 'Route cache valid' : 'Route cache missing or stale',
                'details' => $valid ? [] : ['Run app once to regenerate or use route:cache:clear'],
            ];
        } catch (\Throwable $e) {
            return [
                'passed' => false,
                'message' => 'Route cache check failed',
                'details' => [$e->getMessage()],
            ];
        }
    }

    /**
     * @return array{passed: bool, message: string, details: array<int, string>}
     */
    private function checkStoragePermissions(): array
    {
        $base = base_path($this->getContext());
        $paths = [
            $base . '/storage',
            $base . '/storage/logs',
            $base . '/storage/cache',
        ];

        $missing = [];
        $unwritable = [];

        foreach ($paths as $path) {
            if (!is_dir($path)) {
                $missing[] = $path;
                continue;
            }
            if (!is_writable($path)) {
                $unwritable[] = $path;
            }
        }

        if ($missing !== [] || $unwritable !== []) {
            $details = [];
            foreach ($missing as $p) {
                $details[] = 'Missing: ' . $p;
            }
            foreach ($unwritable as $p) {
                $details[] = 'Not writable: ' . $p;
            }

            return [
                'passed' => false,
                'message' => 'Storage permissions issues',
                'details' => $details,
            ];
        }

        return [
            'passed' => true,
            'message' => 'Storage writable',
            'details' => [],
        ];
    }
}

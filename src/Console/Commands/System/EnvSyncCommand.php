<?php

namespace Glueful\Console\Commands\System;

use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'env:sync',
    description: 'Sync .env.example from config env() usage'
)]
class EnvSyncCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setDescription('Sync .env.example from config env() usage')
            ->setHelp('Scans config/*.php for env() usage and updates .env.example. ' .
                'Optionally creates/updates .env with missing keys.')
            ->addOption(
                'apply',
                null,
                InputOption::VALUE_NONE,
                'Create .env if missing and add missing keys'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $base = base_path($this->getContext());
        $configDir = $base . '/config';
        $examplePath = $base . '/.env.example';
        $envPath = $base . '/.env';

        if (!is_dir($configDir)) {
            $this->error('Config directory not found: ' . $configDir);
            return self::FAILURE;
        }

        $vars = $this->collectEnvVars($configDir);
        if ($vars === []) {
            $this->warning('No env() usage found in config.');
            return self::SUCCESS;
        }

        $this->writeEnvExample($examplePath, $vars);
        $this->success('Updated .env.example');

        if ((bool) $input->getOption('apply')) {
            $this->applyEnv($envPath, $vars);
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, string|null>
     */
    private function collectEnvVars(string $configDir): array
    {
        $vars = [];
        $files = glob($configDir . '/*.php') ?: [];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }
            preg_match_all(
                "/env\\(\\s*['\\\"]([A-Z0-9_]+)['\\\"]\\s*(?:,\\s*([^\\)]+))?\\)/",
                $content,
                $matches,
                PREG_SET_ORDER
            );

            foreach ($matches as $match) {
                $key = $match[1];
                $default = isset($match[2]) ? $this->normalizeDefault(trim($match[2])) : null;
                if (!array_key_exists($key, $vars)) {
                    $vars[$key] = $default;
                }
            }
        }

        ksort($vars);
        return $vars;
    }

    private function normalizeDefault(string $raw): ?string
    {
        if ($raw === '' || $raw === 'null') {
            return null;
        }

        if ($raw === 'true' || $raw === 'false') {
            return $raw;
        }

        if (is_numeric($raw)) {
            return $raw;
        }

        if (
            (str_starts_with($raw, "'") && str_ends_with($raw, "'")) ||
            (str_starts_with($raw, '"') && str_ends_with($raw, '"'))
        ) {
            return substr($raw, 1, -1);
        }

        return null;
    }

    /**
     * @param array<string, string|null> $vars
     */
    private function writeEnvExample(string $path, array $vars): void
    {
        $lines = [];
        foreach ($vars as $key => $default) {
            $value = $default ?? '';
            $lines[] = $key . '=' . $value;
        }

        file_put_contents($path, implode("\n", $lines) . "\n");
    }

    /**
     * @param array<string, string|null> $vars
     */
    private function applyEnv(string $path, array $vars): void
    {
        $existing = [];
        if (file_exists($path)) {
            $content = file_get_contents($path);
            if ($content !== false) {
                $lines = preg_split("/\\r?\\n/", $content);
                if ($lines !== false) {
                    foreach ($lines as $line) {
                        if (strpos($line, '=') !== false) {
                            [$k, $v] = explode('=', $line, 2);
                            $existing[$k] = $v;
                        }
                    }
                }
            }
        }

        $lines = [];
        foreach ($vars as $key => $default) {
            if (array_key_exists($key, $existing)) {
                $lines[] = $key . '=' . $existing[$key];
            } else {
                $lines[] = $key . '=' . ($default ?? '');
            }
        }

        file_put_contents($path, implode("\n", $lines) . "\n");
        $this->success('.env synced');
    }
}

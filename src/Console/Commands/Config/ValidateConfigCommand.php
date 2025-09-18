<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Config;

use Glueful\Console\BaseCommand;
use Glueful\Config\Contracts\ConfigValidatorInterface;
use Glueful\Helpers\ConfigManager;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Validate Configuration Command
 *
 * CLI command for validating configuration files against their registered schemas.
 * Supports validation of individual configurations or all configurations at once.
 * Provides detailed error reporting and validation summaries.
 *
 * @package Glueful\Console\Commands\Config
 */
class ValidateConfigCommand extends BaseCommand
{
    /**
     * Configure the command
     */
    protected function configure(): void
    {
        $this
            ->setName('config:validate')
            ->setDescription('Validate configuration files against their schemas')
            ->addArgument('config', InputArgument::OPTIONAL, 'Configuration name to validate')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Validate all configurations')
            ->addOption('verbose', 'v', InputOption::VALUE_NONE, 'Show detailed validation information')
            ->setHelp('
This command validates configuration files against their registered schemas.

<info>Examples:</info>
  <comment>php glueful config:validate database</comment>     Validate database configuration
  <comment>php glueful config:validate --all</comment>        Validate all configurations
  <comment>php glueful config:validate --all -v</comment>     Validate all with verbose output
            ');
    }

    /**
     * Execute the command
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var \Glueful\Config\Contracts\ConfigValidatorInterface $validator */
        $validator = $this->getService(\Glueful\Config\Contracts\ConfigValidatorInterface::class);
        $configManager = $this->getService(ConfigManager::class);

        if ((bool) $input->getOption('all')) {
            return $this->validateAllConfigs($validator, $configManager, $input->getOption('verbose'));
        }

        $configName = $input->getArgument('config');
        if ($configName === null) {
            $this->error('Please specify a config name or use --all flag');
            $this->showUsageHelp();
            return 1;
        }

        return $this->validateSingleConfig($configName, $validator, $configManager, $input->getOption('verbose'));
    }

    /**
     * Validate all registered configuration schemas
     */
    private function validateAllConfigs(
        \Glueful\Config\Contracts\ConfigValidatorInterface $validator,
        ConfigManager $configManager,
        bool $verbose
    ): int {
        $this->info('Validating all configuration files...');
        $this->line();
        $schemas = $this->discoverSchemas();
        $results = [];
        $totalErrors = 0;

        $this->progressBar(count($schemas), function ($progressBar) use (
            $schemas,
            $validator,
            $configManager,
            &$results,
            &$totalErrors,
            $verbose
        ) {
            foreach ($schemas as $configName) {
                $progressBar->setMessage("Validating {$configName}...");

                try {
                    $config = $configManager::get($configName);
                    $schema = $this->loadSchema($configName);
                    $processedConfig = $validator->validate($config, $schema);

                    $results[$configName] = [
                        'status' => 'valid',
                        'error' => null,
                        'processed_config' => $processedConfig
                    ];

                    if ($verbose) {
                        $this->line("  ✓ {$configName} - Valid");
                    }
                } catch (\InvalidArgumentException $e) {
                    $results[$configName] = [
                        'status' => 'invalid',
                        'error' => $e->getMessage(),
                        'processed_config' => null
                    ];

                    if ($verbose) {
                        $this->line("  ✗ {$configName} - Invalid: " . $e->getMessage());
                    }
                    $totalErrors++;
                } catch (\Exception $e) {
                    $results[$configName] = [
                        'status' => 'error',
                        'error' => $e->getMessage(),
                        'processed_config' => null
                    ];

                    if ($verbose) {
                        $this->line("  ✗ {$configName} - Error: " . $e->getMessage());
                    }
                    $totalErrors++;
                }

                $progressBar->advance();
            }
        });

        $this->line();
        $this->displayValidationSummary($results, $totalErrors);

        if ($verbose && $totalErrors > 0) {
            $this->displayDetailedErrors($results);
        }

        return $totalErrors > 0 ? 1 : 0;
    }

    /**
     * Validate a single configuration
     */
    private function validateSingleConfig(
        string $configName,
        \Glueful\Config\Contracts\ConfigValidatorInterface $validator,
        ConfigManager $configManager,
        bool $verbose
    ): int {
        if (!$this->schemaExists($configName)) {
            $this->error("No schema found for configuration: {$configName}");
            $this->showAvailableSchemas();
            return 1;
        }

        $this->info("Validating configuration '{$configName}'...");

        try {
            $config = $configManager::get($configName);
            $schema = $this->loadSchema($configName);
            $processedConfig = $validator->validate($config, $schema);

            $this->success("Configuration '{$configName}' is valid!");

            if ($verbose) {
                $this->displayConfigDetails($configName, $config, $processedConfig);
            }

            return 0;
        } catch (\InvalidArgumentException $e) {
            $this->error("Configuration '{$configName}' is invalid!");
            $this->line("Error: " . $e->getMessage());

            if ($verbose) {
                $this->displayValidationDetails($configName, $e->getMessage());
            }

            return 1;
        } catch (\Exception $e) {
            $this->error("Failed to load configuration '{$configName}': " . $e->getMessage());
            return 1;
        }
    }

    /**
     * Display validation summary
     *
     * @param array<string, mixed> $results
     */
    private function displayValidationSummary(array $results, int $totalErrors): void
    {
        $totalConfigs = count($results);
        $validConfigs = $totalConfigs - $totalErrors;

        $headers = ['Configuration', 'Status', 'Error'];
        $rows = [];

        foreach ($results as $configName => $result) {
            $status = $result['status'] === 'valid' ? '<info>✓ Valid</info>' : '<error>✗ Invalid</error>';
            $error = $result['error'] !== null ? substr($result['error'], 0, 60) . '...' : '-';
            $rows[] = [$configName, $status, $error];
        }

        $this->table($headers, $rows);

        $this->line();
        $this->info("Validation Summary:");
        $this->line("Total configurations: {$totalConfigs}");
        $this->line("Valid configurations: <info>{$validConfigs}</info>");
        $invalidText = $totalErrors > 0 ? "<error>{$totalErrors}</error>" : "<info>0</info>";
        $this->line("Invalid configurations: " . $invalidText);

        if ($totalErrors === 0) {
            $this->line();
            $this->success('🎉 All configurations are valid!');
        } else {
            $this->line();
            $this->warning("⚠️  {$totalErrors} configuration(s) have errors.");
        }
    }

    /**
     * Display detailed error information
     *
     * @param array<string, mixed> $results
     */
    private function displayDetailedErrors(array $results): void
    {
        $this->line();
        $this->error("Detailed Error Information:");
        $this->line("===========================");

        foreach ($results as $configName => $result) {
            if ($result['status'] !== 'valid') {
                $this->line();
                $this->warning("Configuration: {$configName}");
                $this->line("Status: " . ucfirst($result['status']));
                $this->line("Error: " . $result['error']);
            }
        }
    }

    /**
     * Display configuration details for verbose output
     *
     * @param array<string, mixed> $originalConfig
     * @param array<string, mixed> $processedConfig
     */
    private function displayConfigDetails(
        string $configName,
        array $originalConfig,
        array $processedConfig
    ): void {
        $this->line();
        $this->info("Configuration Details:");
        $this->line("=====================");

        $this->line();
        $this->line("Original configuration structure:");
        $this->displayConfigStructure($originalConfig, 1);

        $this->line();
        $this->line("Processed configuration structure:");
        $this->displayConfigStructure($processedConfig, 1);
    }

    /**
     * Display configuration structure recursively
     *
     * @param array<string, mixed> $config
     */
    private function displayConfigStructure(array $config, int $level): void
    {
        $indent = str_repeat('  ', $level);

        foreach ($config as $key => $value) {
            if (is_array($value)) {
                $this->line("{$indent}<comment>{$key}</comment>: array[" . count($value) . "]");
                if ($level < 3) { // Limit depth to avoid overwhelming output
                    $this->displayConfigStructure($value, $level + 1);
                }
            } else {
                $type = gettype($value);
                $displayValue = is_string($value) && strlen($value) > 50
                    ? substr($value, 0, 47) . '...'
                    : (string) $value;
                $this->line(
                    "{$indent}<comment>{$key}</comment>: <info>{$type}</info>({$displayValue})"
                );
            }
        }
    }

    /**
     * Display validation details for errors
     */
    private function displayValidationDetails(string $configName, string $message): void
    {
        $this->line();
        $this->error("Validation Details:");
        $this->line("==================");
        $this->line("Configuration: {$configName}");
        $this->line("Error: " . $message);
    }

    /**
     * Show usage help
     */
    private function showUsageHelp(): void
    {
        $this->line();
        $this->warning("Usage:");
        $this->line("  php glueful config:validate <config_name>   Validate specific config");
        $this->line("  php glueful config:validate --all           Validate all configs");
        $this->line("  php glueful config:validate --all --verbose Validate all with details");
    }

    /**
     * Show available schemas
     */
    private function showAvailableSchemas(): void
    {
        $schemas = $this->discoverSchemas();

        if ($schemas === []) {
            $this->warning("No configuration schemas are registered.");
            return;
        }

        $this->line();
        $this->info("Available configurations:");
        foreach ($schemas as $configName) {
            $this->line("  - <comment>{$configName}</comment>");
        }
    }

    /**
     * Discover schema names from src/Config/Schema directory
     * @return string[]
     */
    private function discoverSchemas(): array
    {
        $pattern = base_path('src/Config/Schema/*.php');
        $globResult = glob($pattern);
        $files = $globResult !== false ? $globResult : [];
        $names = [];
        foreach ($files as $file) {
            $names[] = basename($file, '.php');
        }
        sort($names);
        return $names;
    }

    private function schemaExists(string $configName): bool
    {
        return is_file(base_path('src/Config/Schema/' . $configName . '.php'));
    }

    /**
     * @return array<string, mixed>
     */
    private function loadSchema(string $configName): array
    {
        /** @var array<string, mixed> $schema */
        $schema = require base_path('src/Config/Schema/' . $configName . '.php');
        return $schema;
    }
}

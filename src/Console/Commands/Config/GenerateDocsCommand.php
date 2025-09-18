<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Config;

use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generate Configuration Documentation Command
 *
 * CLI command for generating configuration documentation from registered schemas.
 * Supports multiple output formats (YAML, XML) and creates comprehensive
 * documentation including reference files, templates, and index summaries.
 *
 * @package Glueful\Console\Commands\Config
 */
class GenerateDocsCommand extends BaseCommand
{
    /**
     * Configure the command
     */
    protected function configure(): void
    {
        $this
            ->setName('config:generate-docs')
            ->setDescription('Generate configuration documentation from schemas')
            ->addOption('format', 'f', InputOption::VALUE_OPTIONAL, 'Documentation format (yaml|xml)', 'yaml')
            ->addOption('output', 'o', InputOption::VALUE_OPTIONAL, 'Output directory', 'docs/config/')
            ->addOption('include-templates', 't', InputOption::VALUE_NONE, 'Include configuration templates')
            ->addOption('include-minimal', 'm', InputOption::VALUE_NONE, 'Include minimal configuration examples')
            ->setHelp('
This command generates comprehensive documentation for all registered configuration schemas.

<info>Examples:</info>
  <comment>php glueful config:generate-docs</comment>                        Generate YAML docs
  <comment>php glueful config:generate-docs --format=xml</comment>            Generate XML docs
  <comment>php glueful config:generate-docs --output=custom/path/</comment>   Custom output directory
  <comment>php glueful config:generate-docs -tm</comment>                     Include templates and minimal configs
            ');
    }

    /**
     * Execute the command
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = (string)$input->getOption('format');
        $outputDir = rtrim((string)$input->getOption('output'), '/') . '/';
        $includeTemplates = (bool)$input->getOption('include-templates');
        $includeMinimal = (bool)$input->getOption('include-minimal');

        // Validate format
        if (!in_array($format, ['yaml', 'xml'], true)) {
            $this->error("Invalid format '{$format}'. Supported formats: yaml, xml");
            return 1;
        }

        // Create output directory
        if (!$this->ensureDirectoryExists($outputDir)) {
            return 1;
        }

        $this->info("Generating configuration documentation in {$format} format...");
        $this->line("Output directory: {$outputDir}");
        $this->line();

        try {
            $names = $this->discoverSchemas();

            if ($names === []) {
                $this->warning('No configuration schemas found.');
                return 0;
            }

            $this->generateDocumentationFiles($names, $outputDir, $format, $includeTemplates, $includeMinimal);
            $this->generateIndexFile($names, $outputDir, $format);

            $this->line();
            $this->success('Documentation generated successfully!');
            $this->info("Generated files in: {$outputDir}");

            return 0;
        } catch (\Exception $e) {
            $this->error('Failed to generate documentation: ' . $e->getMessage());
            return 1;
        }
    }

    /**
     * Generate documentation files for all configurations
     *
     * @param array<string> $names
     */
    private function generateDocumentationFiles(
        array $names,
        string $outputDir,
        string $format,
        bool $includeTemplates,
        bool $includeMinimal
    ): void {
        $totalConfigs = count($names);

        $this->progressBar($totalConfigs, function ($progressBar) use (
            $names,
            $outputDir,
            $format,
            $includeTemplates,
            $includeMinimal
        ) {
            foreach ($names as $configName) {
                $progressBar->setMessage("Generating docs for {$configName}...");

                $schema = $this->loadSchema($configName);
                $this->generateReferenceFile($configName, $schema, $outputDir, $format);

                // Generate template files if requested
                if ($includeTemplates) {
                    $this->generateTemplateFile($schema, $configName, $outputDir);
                }

                // Generate minimal config files if requested
                if ($includeMinimal) {
                    $this->generateMinimalFile($schema, $configName, $outputDir);
                }

                $progressBar->advance();
            }
        });
    }

    /**
     * Generate reference documentation file
     *
     * @param array<string, mixed> $schema
     */
    private function generateReferenceFile(
        string $configName,
        array $schema,
        string $outputDir,
        string $format
    ): void {
        $filename = "{$outputDir}{$configName}.reference.{$format}";
        // Simple dependency-free: emit JSON inside the file for readability
        $content = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($filename, (string)$content);
        $this->line("  Generated: {$configName}.reference.{$format}");
    }

    /**
     * Generate template configuration file
     *
     * @param array<string, mixed> $schema
     */
    private function generateTemplateFile(array $schema, string $configName, string $outputDir): void
    {
        $filename = "{$outputDir}{$configName}.template.php";
        $template = $this->schemaToTemplate($schema, false);

        $content = "<?php\n\n";
        $content .= "/**\n";
        $content .= " * {$configName} Configuration Template\n";
        $content .= " * \n";
        $content .= " * File-based schema\n";
        $content .= " * Version: 1.0\n";
        $content .= " * \n";
        $content .= " * This file contains all available configuration options with their default values.\n";
        $content .= " * Copy and modify as needed for your application.\n";
        $content .= " */\n\n";
        $content .= "return " . $this->formatArrayAsPhp($template, 0) . ";\n";

        file_put_contents($filename, $content);
        $this->line("  Generated: {$configName}.template.php");
    }

    /**
     * Generate minimal configuration file
     *
     * @param array<string, mixed> $schema
     */
    private function generateMinimalFile(array $schema, string $configName, string $outputDir): void
    {
        $filename = "{$outputDir}{$configName}.minimal.php";
        $minimal = $this->schemaToTemplate($schema, true);

        $content = "<?php\n\n";
        $content .= "/**\n";
        $content .= " * {$configName} Minimal Configuration\n";
        $content .= " * \n";
        $content .= " * File-based schema\n";
        $content .= " * Version: 1.0\n";
        $content .= " * \n";
        $content .= " * This file contains only the required configuration options.\n";
        $content .= " * Use this as a starting point for your configuration.\n";
        $content .= " */\n\n";
        $content .= "return " . $this->formatArrayAsPhp($minimal, 0) . ";\n";

        file_put_contents($filename, $content);
        $this->line("  Generated: {$configName}.minimal.php");
    }

    /**
     * Generate index/summary file
     *
     * @param array<string> $names
     */
    private function generateIndexFile(array $names, string $outputDir, string $format): void
    {
        $indexFile = "{$outputDir}README.md";
        $content = $this->generateIndexContent($names, $format);

        file_put_contents($indexFile, $content);
        $this->line("Generated index file: README.md");
    }

    /**
     * Generate index content
     *
     * @param array<string> $names
     */
    private function generateIndexContent(array $names, string $format): string
    {
        $content = "# Configuration Documentation\n\n";
        $content .= "This directory contains configuration schema documentation for Glueful.\n\n";
        $content .= "Generated on: " . date('Y-m-d H:i:s') . "\n";
        $content .= "Format: " . strtoupper($format) . "\n\n";

        $content .= "## Available Configurations\n\n";
        $content .= "| Configuration | Description | Version | Files |\n";
        $content .= "|---------------|-------------|---------|-------|\n";

        foreach ($names as $configName) {
            $files = [];
            $files[] = "[Reference](./{$configName}.reference.{$format})";

            if (file_exists(dirname(__FILE__) . "/../../../{$configName}.template.php")) {
                $files[] = "[Template](./{$configName}.template.php)";
            }

            if (file_exists(dirname(__FILE__) . "/../../../{$configName}.minimal.php")) {
                $files[] = "[Minimal](./{$configName}.minimal.php)";
            }

            $filesStr = implode(' • ', $files);
            $content .= "| **{$configName}** | File-based schema | 1.0 | {$filesStr} |\n";
        }

        $content .= "\n## File Types\n\n";
        $content .= "- **Reference**: Complete schema documentation with all available options\n";
        $content .= "- **Template**: Full configuration file with default values\n";
        $content .= "- **Minimal**: Configuration file with only required options\n\n";

        $content .= "## Usage\n\n";
        $content .= "1. **Reference files** provide complete documentation of all configuration options\n";
        $content .= "2. **Template files** can be copied to your `config/` directory and customized\n";
        $content .= "3. **Minimal files** provide a starting point with only required settings\n\n";

        $content .= "## Validation\n\n";
        $content .= "Use the configuration validation command to check your configurations:\n\n";
        $content .= "```bash\n";
        $content .= "# Validate specific configuration\n";
        $content .= "php glueful config:validate database\n\n";
        $content .= "# Validate all configurations\n";
        $content .= "php glueful config:validate --all\n";
        $content .= "```\n";

        return $content;
    }

    /**
     * Format array as PHP code
     *
     * @param array<string, mixed> $array
     */
    private function formatArrayAsPhp(array $array, int $indent): string
    {
        if ($array === []) {
            return '[]';
        }

        $spaces = str_repeat('    ', $indent);
        $nextSpaces = str_repeat('    ', $indent + 1);

        $result = "[\n";

        foreach ($array as $key => $value) {
            $keyStr = is_string($key) ? "'{$key}'" : $key;

            if (is_array($value)) {
                $result .= "{$nextSpaces}{$keyStr} => " . $this->formatArrayAsPhp($value, $indent + 1) . ",\n";
            } elseif (is_string($value)) {
                $escapedValue = addslashes($value);
                $result .= "{$nextSpaces}{$keyStr} => '{$escapedValue}',\n";
            } elseif (is_bool($value)) {
                $boolStr = $value ? 'true' : 'false';
                $result .= "{$nextSpaces}{$keyStr} => {$boolStr},\n";
            } elseif (is_null($value)) {
                $result .= "{$nextSpaces}{$keyStr} => null,\n";
            } else {
                $result .= "{$nextSpaces}{$keyStr} => {$value},\n";
            }
        }

        $result .= "{$spaces}]";

        return $result;
    }

    /**
     * Ensure directory exists
     */
    private function ensureDirectoryExists(string $directory): bool
    {
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true)) {
                $this->error("Failed to create output directory: {$directory}");
                return false;
            }
            $this->line("Created output directory: {$directory}");
        }

        return true;
    }

    /**
     * Discover schema names
     * @return string[]
     */
    private function discoverSchemas(): array
    {
        $files = glob(base_path('src/Config/Schema/*.php'));
        if ($files === false) {
            $files = [];
        }
        $names = [];
        foreach ($files as $file) {
            $names[] = basename($file, '.php');
        }
        sort($names);
        return $names;
    }

    /**
     * Load array schema
     * @return array<string, mixed>
     */
    private function loadSchema(string $name): array
    {
        /** @var array<string, mixed> $schema */
        $schema = require base_path('src/Config/Schema/' . $name . '.php');
        return $schema;
    }

    /**
     * Convert schema to template array (defaults), or minimal required-only
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function schemaToTemplate(array $schema, bool $requiredOnly): array
    {
        $out = [];
        foreach ($schema as $key => $rules) {
            $isRequired = (bool)($rules['required'] ?? false);
            if ($requiredOnly && !$isRequired) {
                continue;
            }
            $default = $rules['default'] ?? null;
            $out[$key] = $default;
        }
        return $out;
    }
}

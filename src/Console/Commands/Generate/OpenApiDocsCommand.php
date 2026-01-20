<?php

namespace Glueful\Console\Commands\Generate;

use Glueful\Console\BaseCommand;
use Glueful\Support\Documentation\DocumentationUIGenerator;
use Glueful\Support\Documentation\OpenApiGenerator;
use Glueful\Services\FileFinder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generate OpenAPI Documentation Command
 *
 * Generates comprehensive OpenAPI/Swagger documentation including:
 * - Database-driven CRUD API definitions expanded from resource routes
 * - Route-based API definitions from OpenAPI annotations
 * - Complete openapi.json specification file
 *
 * Features:
 * - Automatic table schema discovery and endpoint expansion
 * - Progress indicators for generation process
 * - Multiple UI options (Scalar, Swagger UI, Redoc)
 * - Enhanced output formatting with tables
 *
 * @package Glueful\Console\Commands\Generate
 */
#[AsCommand(
    name: 'generate:openapi',
    description: 'Generate OpenAPI/Swagger documentation from database schema and route annotations'
)]
class OpenApiDocsCommand extends BaseCommand
{
    private ?FileFinder $fileFinder = null;

    protected function configure(): void
    {
        $this->setDescription(
            'Generate OpenAPI/Swagger documentation from database schema and route annotations'
        )
             ->setHelp(
                 'This command generates comprehensive API documentation including:\n' .
                 '• Database table endpoints expanded from resource routes\n' .
                 '• Route-based endpoints from OpenAPI annotations in route files\n' .
                 '• Complete openapi.json specification for API documentation\n\n' .
                 'The generated documentation can be used with API documentation tools like ' .
                 'RapiDoc, Swagger UI, Redoc, or Scalar.'
             )
             ->addOption(
                 'force',
                 'f',
                 InputOption::VALUE_NONE,
                 'Force regeneration of all route documentation files'
             )
             ->addOption(
                 'clean',
                 'c',
                 InputOption::VALUE_NONE,
                 'Clean all existing JSON definitions before generating new ones'
             )
             ->addOption(
                 'ui',
                 'u',
                 InputOption::VALUE_OPTIONAL,
                 'Generate interactive documentation UI (scalar, swagger-ui, redoc)',
                 false
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $force = (bool) $input->getOption('force');
        $clean = (bool) $input->getOption('clean');
        $ui = $input->getOption('ui');

        try {
            // Clean existing definitions if requested
            if ($clean) {
                $this->cleanDefinitionDirectories();
                $this->line(''); // Add blank line for visual separation
            }

            $this->info('Initializing OpenAPI Documentation Generator...');
            $generator = new OpenApiGenerator(null, null, null, true);

            // Display generation scope
            $this->displayGenerationScope($force, $ui);

            // Confirm if not forced
            if (!$force && !$this->confirmGeneration()) {
                $this->info('OpenAPI documentation generation cancelled.');
                return self::SUCCESS;
            }

            // Perform generation with progress indication
            $this->generateDocumentation($generator, $force);

            // Generate documentation UI if requested
            if ($ui !== false) {
                $this->generateDocumentationUI($ui);
            }

            $this->success('OpenAPI documentation generated successfully!');
            $this->displayGenerationResults($ui);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to generate OpenAPI documentation: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Display the generation scope table
     *
     * @param bool $force
     * @param string|false|null $ui
     */
    private function displayGenerationScope(bool $force, $ui): void
    {
        $this->info('Generation Scope:');

        $scope = [];
        $scope[] = ['Target', 'All database tables + route annotations'];
        $scope[] = ['Force Overwrite', $force ? 'Yes' : 'No'];
        $scope[] = ['Clean Before Generate', (bool) $this->input->getOption('clean') ? 'Yes' : 'No'];

        // UI generation info
        if ($ui !== false) {
            $uiType = is_string($ui) && $ui !== '' ? $ui : config('documentation.ui.default', 'scalar');
            $scope[] = ['Generate UI', "Yes ({$uiType})"];
        } else {
            $scope[] = ['Generate UI', 'No'];
        }

        $this->table(['Property', 'Value'], $scope);
    }

    private function confirmGeneration(): bool
    {
        return $this->confirm('Generate OpenAPI documentation for all tables and routes?', true);
    }

    private function generateDocumentation(OpenApiGenerator $generator, bool $force): void
    {
        $this->info('Generating OpenAPI documentation...');
        $this->line('Expanding resource routes with table schemas...');
        $this->line('Processing route annotations...');

        // Generate OpenAPI specification
        $generator->generateOpenApiSpec($force);
    }

    /**
     * Generate the documentation UI HTML file
     *
     * @param string|null $ui UI type or null for default
     */
    private function generateDocumentationUI($ui): void
    {
        $uiType = is_string($ui) && $ui !== '' ? $ui : config('documentation.ui.default', 'scalar');

        $this->line('');
        $this->info("Generating documentation UI ({$uiType})...");

        try {
            $uiGenerator = new DocumentationUIGenerator();
            $outputPath = $uiGenerator->generate($uiType);
            $this->line("Generated UI: {$outputPath}");
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            $this->tip('Supported UIs: ' . implode(', ', DocumentationUIGenerator::getSupportedUIs()));
        }
    }

    /**
     * Display the generation results
     *
     * @param string|false|null $ui
     */
    private function displayGenerationResults($ui = false): void
    {
        $this->line('');
        $this->info('Generation completed successfully!');

        $this->line('Expanded resource routes to table-specific endpoints');
        $this->line('Processed route-based API annotations');
        $this->line('Generated openapi.json specification');

        if ($ui !== false) {
            $uiType = is_string($ui) && $ui !== '' ? $ui : config('documentation.ui.default', 'scalar');
            $this->line("Generated {$uiType} documentation UI");
        }

        $this->line('');
        $this->info('Next steps:');
        $this->line('1. Review generated openapi.json');
        $this->line('2. Customize route annotations as needed for your API');

        $docsUrl = config('app.urls.docs');

        $this->line("3. Visit the API documentation at {$docsUrl}");
        $this->line('4. Test your API endpoints');
    }

    /**
     * Get FileFinder service instance
     *
     * @return FileFinder
     */
    private function getFileFinder(): FileFinder
    {
        if ($this->fileFinder === null) {
            $this->fileFinder = $this->getService(FileFinder::class);
        }
        return $this->fileFinder;
    }

    /**
     * Clean all JSON definition directories
     *
     * Removes all JSON files from json-definitions directories.
     *
     * @return void
     */
    private function cleanDefinitionDirectories(): void
    {
        $fileFinder = $this->getFileFinder();

        try {
            $apiDocDefinitionsPath = config('documentation.paths.output') . '/json-definitions';

            // Clean json-definitions directory (including subdirectories)
            if (is_dir($apiDocDefinitionsPath)) {
                $this->info("Cleaning API doc definitions from: {$apiDocDefinitionsPath}");

                // First, find and remove all subdirectories
                $finder = $fileFinder->createFinder();
                $directories = $finder->directories()->in($apiDocDefinitionsPath)->depth(0);

                $dirCount = 0;
                foreach ($directories as $directory) {
                    // Best-effort directory removal
                    @rmdir($directory->getPathname());
                    $dirCount++;
                }

                // Then, remove any remaining JSON files in the root
                $finder = $fileFinder->createFinder();
                $jsonFiles = $finder->files()->in($apiDocDefinitionsPath)->name('*.json')->depth(0);

                $fileCount = 0;
                foreach ($jsonFiles as $file) {
                    @unlink($file->getPathname());
                    $fileCount++;
                }

                $this->line("Removed {$dirCount} directories and {$fileCount} files from {$apiDocDefinitionsPath}");
            }

            $this->success('Definition directories cleaned successfully!');
        } finally {
            // no-op
        }
    }
}

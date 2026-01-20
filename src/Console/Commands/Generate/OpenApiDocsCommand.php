<?php

namespace Glueful\Console\Commands\Generate;

use Glueful\Console\BaseCommand;
use Glueful\Support\Documentation\DocumentationUIGenerator;
use Glueful\Support\Documentation\OpenApiGenerator;
use Glueful\Support\Documentation\TableDefinitionGenerator;
use Glueful\Services\FileFinder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generate OpenAPI Documentation Command
 *
 * Generates comprehensive OpenAPI/Swagger documentation including:
 * - Database-driven CRUD API definitions from schema analysis
 * - Route-based API definitions from OpenAPI annotations
 * - Complete swagger.json specification file
 * - Individual JSON definition files for each endpoint
 *
 * Features:
 * - Interactive prompts for database and table selection
 * - Progress indicators for generation process
 * - Detailed validation with helpful error messages
 * - Enhanced output formatting with tables
 * - Better error handling and recovery
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
                 '• Database-driven CRUD endpoints from schema analysis\n' .
                 '• Route-based endpoints from OpenAPI annotations in route files\n' .
                 '• Complete swagger.json specification for API documentation\n' .
                 '• Individual JSON definition files for each endpoint\n\n' .
                 'The generated documentation can be used with API documentation tools like ' .
                 'RapiDoc, Swagger UI, Redoc, or Scalar.'
             )
             ->addOption(
                 'database',
                 'd',
                 InputOption::VALUE_REQUIRED,
                 'Specific database name to generate definitions for'
             )
             ->addOption(
                 'table',
                 'T',
                 InputOption::VALUE_REQUIRED,
                 'Specific table name to generate definitions for (requires --database)'
             )
             ->addOption(
                 'force',
                 'f',
                 InputOption::VALUE_NONE,
                 'Force generation of new definitions, even if manual files exist'
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
        $database = $input->getOption('database');
        $table = $input->getOption('table');
        $force = $input->getOption('force');
        $clean = $input->getOption('clean');
        $ui = $input->getOption('ui');

        // Validate table option requires database
        if (($table !== null && $table !== '') && !($database !== null && $database !== '')) {
            $this->error('Table option requires database option to be specified.');
            $this->tip('Use: --database=mydb --table=users');
            return self::FAILURE;
        }

        try {
            // Clean existing definitions if requested
            if ((bool)$clean) {
                $this->cleanDefinitionDirectories();
                $this->line(''); // Add blank line for visual separation
            }

            $this->info('Initializing OpenAPI Documentation Generator...');
            $tableGenerator = new TableDefinitionGenerator();
            $generator = new OpenApiGenerator($tableGenerator, null, null, null, true);

            // Display generation scope
            $this->displayGenerationScope($database, $table, $force, $ui);

            // Confirm if not forced and potentially destructive
            if (!(bool)$force && !$this->confirmGeneration($database, $table)) {
                $this->info('OpenAPI documentation generation cancelled.');
                return self::SUCCESS;
            }

            // Perform generation with progress indication
            $this->generateDocumentation($generator, $tableGenerator, $database, $table, $force);

            // Generate documentation UI if requested
            if ($ui !== false) {
                $this->generateDocumentationUI($ui);
            }

            $this->success('OpenAPI documentation generated successfully!');
            $this->displayGenerationResults($database, $table, $ui);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to generate OpenAPI documentation: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Display the generation scope table
     *
     * @param string|null $database
     * @param string|null $table
     * @param bool $force
     * @param string|false|null $ui
     */
    private function displayGenerationScope(?string $database, ?string $table, bool $force, $ui): void
    {
        $this->info('Generation Scope:');

        $scope = [];
        if (($database !== null && $database !== '') && ($table !== null && $table !== '')) {
            $scope[] = ['Target', "Table '{$table}' in database '{$database}'"];
        } elseif ($database !== null && $database !== '') {
            $scope[] = ['Target', "All tables in database '{$database}'"];
        } else {
            $scope[] = ['Target', 'All tables in all databases'];
        }

        $scope[] = ['Force Overwrite', $force ? 'Yes' : 'No'];
        $scope[] = ['Clean Before Generate', (bool)$this->input->getOption('clean') ? 'Yes' : 'No'];

        // UI generation info
        if ($ui !== false) {
            $uiType = is_string($ui) && $ui !== '' ? $ui : config('documentation.ui.default', 'scalar');
            $scope[] = ['Generate UI', "Yes ({$uiType})"];
        } else {
            $scope[] = ['Generate UI', 'No'];
        }

        $this->table(['Property', 'Value'], $scope);
    }

    private function confirmGeneration(?string $database, ?string $table): bool
    {
        if (($database !== null && $database !== '') && ($table !== null && $table !== '')) {
            return $this->confirm("Generate OpenAPI docs for table '{$table}' in database '{$database}'?", true);
        } elseif ($database !== null && $database !== '') {
            return $this->confirm("Generate OpenAPI docs for all tables in database '{$database}'?", true);
        } else {
            return $this->confirm('Generate OpenAPI docs for all databases and tables?', false);
        }
    }

    private function generateDocumentation(
        OpenApiGenerator $generator,
        TableDefinitionGenerator $tableGenerator,
        ?string $database,
        ?string $table,
        bool $force
    ): void {
        $this->info('Generating OpenAPI documentation...');

        // Generate table definitions first
        if (($database !== null && $database !== '') && ($table !== null && $table !== '')) {
            $this->line("Processing table: {$table}");
            $tableGenerator->generateForTable($table, $database);
        } elseif ($database !== null && $database !== '') {
            $this->line("Processing database: {$database}");
            $tableGenerator->generateAll($database);
        } else {
            $this->line('Processing all databases...');
            $tableGenerator->generateAll();
        }

        // Generate OpenAPI specification
        $this->line('Generating OpenAPI specification...');
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
     * @param string|null $database
     * @param string|null $table
     * @param string|false|null $ui
     */
    private function displayGenerationResults(?string $database, ?string $table, $ui = false): void
    {
        $this->line('');
        $this->info('Generation completed successfully!');

        if (($database !== null && $database !== '') && ($table !== null && $table !== '')) {
            $this->line("Generated OpenAPI documentation for table: {$table}");
        } elseif ($database !== null && $database !== '') {
            $this->line("Generated OpenAPI documentation for database: {$database}");
        } else {
            $this->line('Generated OpenAPI documentation for all databases');
        }

        $this->line('Processed route-based API annotations');
        $this->line('Created individual endpoint definitions');
        $this->line('Generated swagger.json specification');

        if ($ui !== false) {
            $uiType = is_string($ui) && $ui !== '' ? $ui : config('documentation.ui.default', 'scalar');
            $this->line("Generated {$uiType} documentation UI");
        }

        $this->line('');
        $this->info('Next steps:');
        $this->line('1. Review generated swagger.json and API definition files');
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
     * Removes all JSON files from both api-json-definitions and json-definitions
     * directories, including subdirectories in json-definitions.
     *
     * @return void
     */
    private function cleanDefinitionDirectories(): void
    {
        $fileFinder = $this->getFileFinder();

        try {
            $jsonDefinitionsPath = config('documentation.paths.database_definitions');
            $apiDocDefinitionsPath = config('documentation.paths.output') . '/json-definitions';

            // Clean api-json-definitions directory (just .json files)
            if (is_dir($jsonDefinitionsPath)) {
                $this->info("Cleaning JSON definitions from: {$jsonDefinitionsPath}");

                $finder = $fileFinder->createFinder();
                $jsonFiles = $finder->files()->in($jsonDefinitionsPath)->name('*.json');

                $count = 0;
                foreach ($jsonFiles as $file) {
                    @unlink($file->getPathname());
                    $count++;
                }

                $this->line("Removed {$count} JSON files from {$jsonDefinitionsPath}");
            }

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

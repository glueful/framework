<?php

declare(strict_types=1);

namespace Glueful\Support\Documentation;

use Glueful\Extensions\ExtensionManager;
use Glueful\Services\FileFinder;

/**
 * OpenAPI Generator
 *
 * Orchestrates the full API documentation generation pipeline.
 * Coordinates TableDefinitionGenerator, DocGenerator, and CommentsDocGenerator
 * to produce a complete OpenAPI/Swagger specification.
 */
class OpenApiGenerator
{
    private TableDefinitionGenerator $tableGenerator;
    private DocGenerator $docGenerator;
    private CommentsDocGenerator $commentsGenerator;
    private FileFinder $fileFinder;

    private bool $runFromConsole;

    /** @var callable|null Progress callback */
    private $progressCallback = null;

    /**
     * Constructor
     *
     * @param TableDefinitionGenerator|null $tableGenerator Table definition generator
     * @param DocGenerator|null $docGenerator OpenAPI assembler
     * @param CommentsDocGenerator|null $commentsGenerator Route documentation generator
     * @param FileFinder|null $fileFinder File finder service
     * @param bool $runFromConsole Force console mode
     */
    public function __construct(
        ?TableDefinitionGenerator $tableGenerator = null,
        ?DocGenerator $docGenerator = null,
        ?CommentsDocGenerator $commentsGenerator = null,
        ?FileFinder $fileFinder = null,
        bool $runFromConsole = false
    ) {
        $this->tableGenerator = $tableGenerator ?? new TableDefinitionGenerator();
        $this->docGenerator = $docGenerator ?? new DocGenerator();
        $this->commentsGenerator = $commentsGenerator ?? new CommentsDocGenerator();
        $this->fileFinder = $fileFinder ?? container()->get(FileFinder::class);
        $this->runFromConsole = $runFromConsole || $this->isConsole();
    }

    /**
     * Generate complete API documentation
     *
     * Runs the full pipeline:
     * 1. Generate table definitions
     * 2. Process custom API docs
     * 3. Process table definitions
     * 4. Generate extension documentation
     * 5. Generate route documentation
     * 6. Assemble and write swagger.json
     *
     * @param bool $force Force regeneration of all files
     * @param string|null $database Specific database to process
     * @return string Path to generated swagger.json
     */
    public function generate(bool $force = false, ?string $database = null): string
    {
        $this->log("Starting API documentation generation...");

        // Step 1: Generate table definitions
        $this->log("Generating table definitions...");
        $tableFiles = $this->tableGenerator->generateAll($database);
        $this->log("Generated " . count($tableFiles) . " table definition(s)");

        // Step 2: Process custom API doc definitions
        $this->processCustomApiDocs();

        // Step 3: Process table definitions into OpenAPI spec
        $this->processTableDefinitions();

        // Step 4 & 5: Generate and process extension/route documentation
        $this->processExtensionAndRouteDocs($force);

        // Step 6: Write final swagger.json
        $outputPath = $this->writeSwaggerJson();

        $this->log("API documentation generated successfully at: $outputPath");

        return $outputPath;
    }

    /**
     * Generate only the OpenAPI spec (without regenerating table definitions)
     *
     * Useful when table definitions are already up-to-date.
     *
     * @param bool $force Force regeneration of extension/route docs
     * @return string Path to generated swagger.json
     */
    public function generateOpenApiSpec(bool $force = false): string
    {
        $this->log("Generating OpenAPI specification...");

        $this->processCustomApiDocs();
        $this->processTableDefinitions();
        $this->processExtensionAndRouteDocs($force);

        return $this->writeSwaggerJson();
    }

    /**
     * Set progress callback
     *
     * @param callable $callback Callback function(string $message, float $progress)
     * @return self
     */
    public function onProgress(callable $callback): self
    {
        $this->progressCallback = $callback;
        return $this;
    }

    /**
     * Process custom API documentation files
     */
    private function processCustomApiDocs(): void
    {
        $definitionsDocPath = config('documentation.paths.output') . '/json-definitions/';

        if (!is_dir($definitionsDocPath)) {
            return;
        }

        $finder = $this->fileFinder->createFinder();
        $docFiles = $finder->files()->in($definitionsDocPath)->name('*.json');

        foreach ($docFiles as $file) {
            try {
                $this->docGenerator->generateFromDocJson($file->getPathname());
                $this->log("Processed custom API doc: " . $file->getFilename());
            } catch (\Exception $e) {
                $this->log("Error processing doc definition {$file->getPathname()}: " . $e->getMessage());
            }
        }
    }

    /**
     * Process table definition files into OpenAPI spec
     */
    private function processTableDefinitions(): void
    {
        $definitionsPath = config('documentation.paths.database_definitions');

        $finder = $this->fileFinder->createFinder();
        $definitionFiles = $finder->files()->in($definitionsPath)->name('*.json');

        foreach ($definitionFiles as $file) {
            $parts = explode('.', basename($file->getFilename()));
            if (count($parts) !== 3) {
                continue; // Skip if not in format: dbname.tablename.json
            }

            try {
                $this->docGenerator->generateFromJson($file->getPathname());
                $this->log("Processed table definition: " . $file->getFilename());
            } catch (\Exception $e) {
                $this->log("Error processing table definition {$file->getPathname()}: " . $e->getMessage());
            }
        }
    }

    /**
     * Process extension and route documentation
     *
     * @param bool $force Force regeneration
     */
    private function processExtensionAndRouteDocs(bool $force): void
    {
        $extensionDocsDir = config('documentation.paths.extension_definitions');
        $routesDocsDir = config('documentation.paths.route_definitions');

        // Ensure directories exist
        $this->ensureDirectory($extensionDocsDir);
        $this->ensureDirectory($routesDocsDir);

        try {
            if ($force) {
                $this->forceGenerateExtensionDocs();
                $this->forceGenerateRouteDocs();
            } else {
                $generatedFiles = $this->commentsGenerator->generateAll();

                if ($generatedFiles !== []) {
                    $this->log("Generated documentation for " . count($generatedFiles) . " extension(s)/route(s)");
                    foreach ($generatedFiles as $file) {
                        $this->log("Generated: " . basename($file));
                    }
                } else {
                    $this->log("No extension route files found for documentation generation");
                }
            }

            // Process the generated documentation
            $this->docGenerator->generateFromExtensions($extensionDocsDir);
            $this->log("Processed extension API documentation");

            $this->docGenerator->generateFromRoutes($routesDocsDir);
            $this->log("Processed main routes API documentation");
        } catch (\Exception $e) {
            $this->log("Error generating documentation: " . $e->getMessage());
        }
    }

    /**
     * Force generate extension documentation
     *
     * Uses ExtensionManager to discover Composer-installed extensions.
     */
    private function forceGenerateExtensionDocs(): void
    {
        $this->log("Forcing generation of extension documentation...");

        $extensionManager = container()->get(ExtensionManager::class);
        $providers = $extensionManager->getProviders();
        $meta = $extensionManager->listMeta();

        foreach ($providers as $providerClass => $_provider) {
            $metadata = $meta[$providerClass] ?? [];
            $extensionName = $metadata['slug'] ?? basename(str_replace('\\', '/', $providerClass));

            // Use reflection to find the extension's routes file
            $routeFile = $this->findExtensionRoutesFile($providerClass, $extensionName);

            if ($routeFile !== null && file_exists($routeFile)) {
                $docFile = $this->commentsGenerator->generateForExtension($extensionName, $routeFile, true);
                if (is_string($docFile) && $docFile !== '') {
                    $this->log("Generated extension doc: " . basename($docFile));
                }
            }
        }
    }

    /**
     * Find the routes file for an extension based on its provider class
     *
     * Searches for routes in common locations relative to the provider file:
     * - Same directory as provider (src/routes.php)
     * - Package root (routes.php next to composer.json)
     * - Routes subdirectory (routes/*.php)
     *
     * @param string $providerClass The provider class name
     * @param string $extensionName The extension slug/name
     * @return string|null Path to routes file or null if not found
     */
    private function findExtensionRoutesFile(string $providerClass, string $extensionName): ?string
    {
        try {
            $reflection = new \ReflectionClass($providerClass);
            $providerFile = $reflection->getFileName();

            if ($providerFile === false) {
                return null;
            }

            // Find the package root by looking for composer.json
            $packageRoot = $this->findPackageRoot(dirname($providerFile));
            if ($packageRoot === null) {
                return null;
            }

            // Common route file locations (in order of preference)
            $routesPaths = [
                // Routes in src directory (e.g., aegis/src/routes.php)
                $packageRoot . '/src/routes.php',
                // Routes in package root (e.g., payvia/routes.php)
                $packageRoot . '/routes.php',
                // Named routes file
                $packageRoot . '/routes/' . strtolower($extensionName) . '.php',
                // Generic routes directory
                $packageRoot . '/routes/routes.php',
                $packageRoot . '/routes/api.php',
            ];

            foreach ($routesPaths as $path) {
                if (file_exists($path)) {
                    $realPath = realpath($path);
                    if ($realPath !== false) {
                        return $realPath;
                    }
                }
            }
        } catch (\ReflectionException) {
            // Provider class not found, skip
        }

        return null;
    }

    /**
     * Find the package root directory by looking for composer.json
     *
     * @param string $startDir Directory to start searching from
     * @param int $maxDepth Maximum directory levels to traverse up
     * @return string|null Package root path or null if not found
     */
    private function findPackageRoot(string $startDir, int $maxDepth = 5): ?string
    {
        $dir = $startDir;

        for ($i = 0; $i < $maxDepth; $i++) {
            if (file_exists($dir . '/composer.json')) {
                return $dir;
            }

            $parent = dirname($dir);
            if ($parent === $dir) {
                // Reached filesystem root
                break;
            }
            $dir = $parent;
        }

        return null;
    }

    /**
     * Force generate route documentation
     */
    private function forceGenerateRouteDocs(): void
    {
        $routeFiles = $this->fileFinder->findRouteFiles([config('documentation.sources.routes')]);

        foreach ($routeFiles as $routeFileObj) {
            $routeFile = $routeFileObj->getPathname();
            $routeName = $routeFileObj->getBasename('.php');
            $docFile = $this->commentsGenerator->generateForRouteFile($routeName, $routeFile, true);
            if (is_string($docFile) && $docFile !== '') {
                $this->log("Generated route doc: " . basename($docFile));
            }
        }
    }

    /**
     * Write final swagger.json file
     *
     * @return string Path to generated file
     */
    private function writeSwaggerJson(): string
    {
        $swaggerJson = $this->docGenerator->getSwaggerJson();
        $outputPath = config('documentation.paths.swagger');

        $this->ensureDirectory(dirname($outputPath));

        if (file_put_contents($outputPath, $swaggerJson) === false) {
            throw new \RuntimeException("Failed to write API documentation to: $outputPath");
        }

        return $outputPath;
    }

    /**
     * Ensure directory exists
     *
     * @param string $path Directory path
     */
    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

    /**
     * Check if running in console mode
     *
     * @return bool True if running from command line
     */
    private function isConsole(): bool
    {
        return php_sapi_name() === 'cli';
    }

    /**
     * Log messages with proper line endings
     *
     * @param string $message Message to log
     */
    private function log(string $message): void
    {
        if ($this->progressCallback !== null) {
            call_user_func($this->progressCallback, $message);
            return;
        }

        if ($this->runFromConsole) {
            if (ob_get_level() === 0) {
                ob_start();
            }

            echo $message . PHP_EOL;

            ob_flush();
            flush();
        } else {
            echo $message . "<br/>";
        }
    }

    /**
     * Get the underlying DocGenerator instance
     *
     * @return DocGenerator
     */
    public function getDocGenerator(): DocGenerator
    {
        return $this->docGenerator;
    }

    /**
     * Get the underlying TableDefinitionGenerator instance
     *
     * @return TableDefinitionGenerator
     */
    public function getTableGenerator(): TableDefinitionGenerator
    {
        return $this->tableGenerator;
    }

    /**
     * Get the underlying CommentsDocGenerator instance
     *
     * @return CommentsDocGenerator
     */
    public function getCommentsGenerator(): CommentsDocGenerator
    {
        return $this->commentsGenerator;
    }
}

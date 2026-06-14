<?php

declare(strict_types=1);

namespace Glueful\Support\Documentation;

use Glueful\Extensions\ExtensionManager;
use Glueful\Services\FileFinder;
use Glueful\Bootstrap\ApplicationContext;

/**
 * OpenAPI Generator
 *
 * Orchestrates the full API documentation generation pipeline.
 * Coordinates ResourceRouteExpander, DocGenerator, and CommentsDocGenerator
 * to produce a complete OpenAPI/Swagger specification.
 */
class OpenApiGenerator
{
    private DocGenerator $docGenerator;
    private CommentsDocGenerator $commentsGenerator;
    private FileFinder $fileFinder;
    private ResourceRouteExpander $resourceExpander;

    private bool $runFromConsole;

    /** @var callable|null Progress callback */
    private $progressCallback = null;

    private ApplicationContext $context;

    /**
     * Constructor
     *
     * @param DocGenerator|null $docGenerator OpenAPI assembler
     * @param CommentsDocGenerator|null $commentsGenerator Route documentation generator
     * @param FileFinder|null $fileFinder File finder service
     * @param bool $runFromConsole Force console mode
     * @param ResourceRouteExpander|null $resourceExpander Resource route expander
     * @param ApplicationContext $context Application context
     */
    public function __construct(
        ApplicationContext $context,
        ?DocGenerator $docGenerator = null,
        ?CommentsDocGenerator $commentsGenerator = null,
        ?FileFinder $fileFinder = null,
        bool $runFromConsole = false,
        ?ResourceRouteExpander $resourceExpander = null
    ) {
        $this->context = $context;
        $this->docGenerator = $docGenerator ?? new DocGenerator(context: $context);
        $this->commentsGenerator = $commentsGenerator ?? new CommentsDocGenerator($context);
        $this->fileFinder = $fileFinder ?? container($this->context)->get(FileFinder::class);
        $this->runFromConsole = $runFromConsole || $this->isConsole();
        $this->resourceExpander = $resourceExpander ?? new ResourceRouteExpander();

        $this->wireSecurityRegistry();
    }

    /**
     * Wire the configured SecuritySchemeRegistry into the underlying generators.
     *
     * Reads documentation.security_schemes and documentation.middleware_map from
     * config and propagates them to both DocGenerator and CommentsDocGenerator so
     * the emitted spec advertises the configured schemes and per-operation
     * security requirements.
     */
    private function wireSecurityRegistry(): void
    {
        $schemes = config($this->context, 'documentation.security_schemes', []);
        $middlewareMap = config($this->context, 'documentation.middleware_map', []);

        if (!is_array($schemes) || $schemes === []) {
            return;
        }

        $registry = new SecuritySchemeRegistry(
            $schemes,
            is_array($middlewareMap) ? $middlewareMap : [],
        );

        $this->docGenerator->setSecurityRegistry($registry);
        $this->commentsGenerator->setSecurityRegistry($registry);
    }

    /**
     * Generate complete API documentation
     *
     * Runs the full pipeline:
     * 1. Expand resource routes with table schemas (if enabled)
     * 2. Process custom API docs
     * 3. Generate extension documentation
     * 4. Generate route documentation (non-resource routes)
     * 5. Assemble and write openapi.json
     *
     * @param bool $force Force regeneration of all files
     * @return string Path to generated openapi.json
     */
    public function generate(bool $force = false): string
    {
        $this->log("Starting API documentation generation...");

        // Step 1: Expand {resource} routes to table-specific endpoints (if enabled)
        if ($this->shouldIncludeResourceRoutes()) {
            $this->log("Expanding resource routes with table schemas...");
            $this->expandResourceRoutes();
        } else {
            $this->log("Skipping resource routes generation (disabled in config)");
        }

        // Step 2: Process custom API doc definitions
        $this->processCustomApiDocs();

        // Step 3 & 4: Generate and process extension/route documentation
        $this->processExtensionAndRouteDocs($force);

        // Step 5: Write final openapi.json
        $outputPath = $this->writeSwaggerJson();

        $this->log("API documentation generated successfully at: $outputPath");

        return $outputPath;
    }

    /**
     * Expand resource routes with table schemas
     *
     * Expands {resource} routes directly to table-specific endpoints with full schemas.
     */
    private function expandResourceRoutes(): void
    {
        $tables = $this->resourceExpander->getTableNames();
        $this->log("Found " . count($tables) . " table(s) for resource expansion");

        $this->docGenerator->generateResourceRoutes($this->resourceExpander);

        foreach ($tables as $table) {
            $this->log("  - {$table}");
        }
    }

    /**
     * Generate only the OpenAPI spec
     *
     * @param bool $force Force regeneration of extension/route docs
     * @return string Path to generated openapi.json
     */
    public function generateOpenApiSpec(bool $force = false): string
    {
        $this->log("Generating OpenAPI specification...");

        if ($this->shouldIncludeResourceRoutes()) {
            $this->expandResourceRoutes();
        }
        $this->processCustomApiDocs();
        $this->processExtensionAndRouteDocs($force);

        return $this->writeSwaggerJson();
    }

    /**
     * Check if resource routes should be included in documentation
     *
     * @return bool True if resource routes should be generated
     */
    private function shouldIncludeResourceRoutes(): bool
    {
        return (bool) config($this->context, 'documentation.options.include_resource_routes', true);
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
     *
     * Processes JSON files in the json-definitions directory, excluding
     * 'extensions/' and 'routes/' subdirectories which are handled separately
     * by generateFromExtensions() and generateFromRoutes().
     */
    private function processCustomApiDocs(): void
    {
        $definitionsDocPath = config($this->context, 'documentation.paths.output') . '/json-definitions/';

        if (!is_dir($definitionsDocPath)) {
            return;
        }

        $finder = $this->fileFinder->createFinder();
        $docFiles = $finder->files()
            ->in($definitionsDocPath)
            ->name('*.json')
            ->exclude(['extensions', 'routes']); // These are processed separately

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
     * Process extension and route documentation
     *
     * @param bool $force Force regeneration
     */
    private function processExtensionAndRouteDocs(bool $force): void
    {
        $extensionDocsDir = config($this->context, 'documentation.paths.extension_definitions');
        $routesDocsDir = config($this->context, 'documentation.paths.route_definitions');

        // Ensure directories exist
        $this->ensureDirectory($extensionDocsDir);
        $this->ensureDirectory($routesDocsDir);

        $includeExtensions = (bool) config($this->context, 'documentation.options.include_extensions', true);
        $includeRoutes = (bool) config($this->context, 'documentation.options.include_routes', true);

        try {
            if ($force) {
                $extensionFiles = $includeExtensions ? $this->forceGenerateExtensionDocs() : [];
                $routeFiles = $includeRoutes ? $this->forceGenerateRouteDocs() : [];
            } else {
                [$extensionFiles, $routeFiles] = $this->splitGeneratedFiles(
                    $this->commentsGenerator->generateAll(),
                    $extensionDocsDir,
                    $routesDocsDir,
                );

                if (!$includeExtensions) {
                    $extensionFiles = [];
                }
                if (!$includeRoutes) {
                    $routeFiles = [];
                }

                // Apply include_framework_routes on the non-force path too. The
                // force path already skips the framework-routes scan when the
                // flag is false (so no framework fragments are generated there).
                if (
                    $routeFiles !== []
                    && !(bool) config($this->context, 'documentation.sources.include_framework_routes', true)
                ) {
                    $routeFiles = $this->excludeFrameworkRouteFragments($routeFiles);
                }

                $generatedFiles = array_merge($extensionFiles, $routeFiles);
                if ($generatedFiles !== []) {
                    $this->log("Generated documentation for " . count($generatedFiles) . " extension(s)/route(s)");
                    foreach ($generatedFiles as $file) {
                        $this->log("Generated: " . basename($file));
                    }
                } else {
                    $this->log("No extension route files found for documentation generation");
                }
            }

            // Merge ONLY the fragments generated by this run (gated by config flags).
            // This avoids re-merging stale fragments left over from previous runs.
            $this->docGenerator->generateFromExtensionFiles($extensionFiles);
            $this->log("Processed extension API documentation");

            $this->docGenerator->generateFromRouteFiles($routeFiles);
            $this->log("Processed main routes API documentation");
        } catch (\Exception $e) {
            $this->log("Error generating documentation: " . $e->getMessage());
        }
    }

    /**
     * Split a combined list of generated fragment files into extension vs route
     * fragments by directory prefix.
     *
     * CommentsDocGenerator::generateAll() returns extension fragments (written
     * under the extension definitions dir) followed by route fragments (written
     * under the route definitions dir). A path-prefix split is sufficient and
     * keeps the orchestration decoupled from generation order.
     *
     * @param list<string> $files Combined list of generated fragment paths
     * @return array{0: list<string>, 1: list<string>} [extensionFiles, routeFiles]
     */
    private function splitGeneratedFiles(array $files, string $extensionDocsDir, string $routesDocsDir): array
    {
        $extDirReal = realpath($extensionDocsDir);
        $routesDirReal = realpath($routesDocsDir);

        $extensionFiles = [];
        $routeFiles = [];

        foreach ($files as $file) {
            if (!is_string($file)) {
                continue;
            }

            $real = realpath($file);
            $candidate = $real !== false ? $real : $file;

            if ($extDirReal !== false && str_starts_with($candidate, $extDirReal)) {
                $extensionFiles[] = $file;
            } elseif ($routesDirReal !== false && str_starts_with($candidate, $routesDirReal)) {
                $routeFiles[] = $file;
            } elseif (str_starts_with($file, $extensionDocsDir)) {
                $extensionFiles[] = $file;
            } else {
                $routeFiles[] = $file;
            }
        }

        return [$extensionFiles, $routeFiles];
    }

    /**
     * Remove route fragments that correspond to framework route files.
     *
     * Used on the non-force path to honor include_framework_routes=false. Route
     * fragments are named after their source route file (e.g. health.php ->
     * health.json), so we match fragment basenames against the basenames of the
     * route files found in the framework routes directory.
     *
     * Limitation: matching is by basename, so an app route file whose basename
     * collides with a framework one (e.g. both ship `health.php`) would also be
     * excluded under include_framework_routes=false. This is unusual and only
     * affects the opt-in flag; a future phase can discriminate by source origin
     * path instead of basename.
     *
     * @param list<string> $routeFiles Route fragment paths
     * @return list<string> Filtered route fragment paths
     */
    private function excludeFrameworkRouteFragments(array $routeFiles): array
    {
        $frameworkRoutes = $this->resolveFrameworkRoutesPath();
        if ($frameworkRoutes === null || !is_dir($frameworkRoutes)) {
            return $routeFiles;
        }

        $frameworkNames = [];
        foreach ($this->fileFinder->findRouteFiles([$frameworkRoutes]) as $routeFileObj) {
            $frameworkNames[strtolower($routeFileObj->getBasename('.php'))] = true;
        }

        if ($frameworkNames === []) {
            return $routeFiles;
        }

        return array_values(array_filter(
            $routeFiles,
            static fn (string $file): bool =>
                !isset($frameworkNames[strtolower(basename($file, '.json'))])
        ));
    }

    /**
     * Force generate extension documentation
     *
     * Uses ExtensionManager to discover Composer-installed extensions.
     *
     * @return list<string> Fragment files generated by this run
     */
    private function forceGenerateExtensionDocs(): array
    {
        $this->log("Forcing generation of extension documentation...");

        $generated = [];
        $extensionManager = container($this->context)->get(ExtensionManager::class);
        $providers = $extensionManager->getProviders();
        $meta = $extensionManager->listMeta();

        foreach ($providers as $providerClass => $_provider) {
            // Skip app-level providers (not true extensions)
            // These are in the App\ namespace and would incorrectly pick up project routes
            if (str_starts_with($providerClass, 'App\\')) {
                continue;
            }

            $metadata = $meta[$providerClass] ?? [];
            $extensionName = $metadata['slug'] ?? basename(str_replace('\\', '/', $providerClass));

            // Use reflection to find the extension's routes file
            $routeFile = $this->findExtensionRoutesFile($providerClass, $extensionName);

            if ($routeFile !== null && file_exists($routeFile)) {
                $docFile = $this->commentsGenerator->generateForExtension($extensionName, $routeFile, true);
                if (is_string($docFile) && $docFile !== '') {
                    $generated[] = $docFile;
                    $this->log("Generated extension doc: " . basename($docFile));
                }
            }
        }

        return $generated;
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
     *
     * @return list<string> Fragment files generated by this run
     */
    private function forceGenerateRouteDocs(): array
    {
        $generated = [];
        $routePaths = [config($this->context, 'documentation.sources.routes')];

        // Include framework routes if enabled
        if ((bool) config($this->context, 'documentation.sources.include_framework_routes', true)) {
            $frameworkRoutes = $this->resolveFrameworkRoutesPath();
            if ($frameworkRoutes !== null && is_dir($frameworkRoutes)) {
                $routePaths[] = $frameworkRoutes;
                $this->log("Including framework routes from: $frameworkRoutes");
            }
        }

        $routeFiles = $this->fileFinder->findRouteFiles($routePaths);

        foreach ($routeFiles as $routeFileObj) {
            $routeFile = $routeFileObj->getPathname();
            $routeName = $routeFileObj->getBasename('.php');
            $docFile = $this->commentsGenerator->generateForRouteFile($routeName, $routeFile, true);
            if (is_string($docFile) && $docFile !== '') {
                $generated[] = $docFile;
                $this->log("Generated route doc: " . basename($docFile));
            }
        }

        return $generated;
    }

    /**
     * Resolve the framework routes path
     *
     * Checks for framework routes in:
     * 1. Config override (documentation.sources.framework_routes)
     * 2. Local development symlink (vendor/glueful/framework)
     * 3. Installed package (vendor/glueful/framework)
     *
     * @return string|null Path to framework routes directory or null if not found
     */
    private function resolveFrameworkRoutesPath(): ?string
    {
        // Check config override first
        $configPath = config($this->context, 'documentation.sources.framework_routes');
        if (is_string($configPath) && $configPath !== '' && is_dir($configPath)) {
            return $configPath;
        }

        // Check if running from within the framework itself
        $frameworkSrcDir = dirname(__DIR__, 3); // Go up from src/Support/Documentation to framework root
        $frameworkRoutesDir = $frameworkSrcDir . '/routes';
        if (is_dir($frameworkRoutesDir) && file_exists($frameworkSrcDir . '/composer.json')) {
            // Verify this is actually the framework by checking composer.json
            $composerJson = @file_get_contents($frameworkSrcDir . '/composer.json');
            if ($composerJson !== false && str_contains($composerJson, '"glueful/framework"')) {
                return $frameworkRoutesDir;
            }
        }

        // Check vendor directory
        $vendorFramework = base_path($this->context, 'vendor/glueful/framework/routes');
        if (is_dir($vendorFramework)) {
            return $vendorFramework;
        }

        return null;
    }

    /**
     * Write final openapi.json file
     *
     * @return string Path to generated file
     */
    private function writeSwaggerJson(): string
    {
        $swaggerJson = $this->docGenerator->getSwaggerJson();
        $outputPath = config($this->context, 'documentation.paths.openapi');

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
     * Get the underlying CommentsDocGenerator instance
     *
     * @return CommentsDocGenerator
     */
    public function getCommentsGenerator(): CommentsDocGenerator
    {
        return $this->commentsGenerator;
    }

    /**
     * Get the underlying ResourceRouteExpander instance
     *
     * @return ResourceRouteExpander
     */
    public function getResourceExpander(): ResourceRouteExpander
    {
        return $this->resourceExpander;
    }
}

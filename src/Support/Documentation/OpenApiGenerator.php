<?php

declare(strict_types=1);

namespace Glueful\Support\Documentation;

use Glueful\Services\FileFinder;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Routing\AttributeRouteLoader;
use Glueful\Routing\RouteManifest;
use Glueful\Routing\Router;

/**
 * OpenAPI Generator
 *
 * Orchestrates the full API documentation generation pipeline.
 * Coordinates ResourceRouteExpander, DocGenerator, and the code-first
 * RouteReflectionDocGenerator to produce a complete OpenAPI/Swagger
 * specification from the live route table.
 */
class OpenApiGenerator
{
    private DocGenerator $docGenerator;
    private FileFinder $fileFinder;
    private ResourceRouteExpander $resourceExpander;

    private bool $runFromConsole;

    /** @var callable|null Progress callback */
    private $progressCallback = null;

    private ApplicationContext $context;

    /**
     * The configured security registry, shared between the DocGenerator and the
     * reflect generator. Null when no security schemes are configured.
     */
    private ?SecuritySchemeRegistry $securityRegistry = null;

    /**
     * Constructor
     *
     * @param DocGenerator|null $docGenerator OpenAPI assembler
     * @param FileFinder|null $fileFinder File finder service
     * @param bool $runFromConsole Force console mode
     * @param ResourceRouteExpander|null $resourceExpander Resource route expander
     * @param ApplicationContext $context Application context
     */
    public function __construct(
        ApplicationContext $context,
        ?DocGenerator $docGenerator = null,
        ?FileFinder $fileFinder = null,
        bool $runFromConsole = false,
        ?ResourceRouteExpander $resourceExpander = null
    ) {
        $this->context = $context;
        $this->docGenerator = $docGenerator ?? new DocGenerator(context: $context);
        $this->fileFinder = $fileFinder ?? container($this->context)->get(FileFinder::class);
        $this->runFromConsole = $runFromConsole || $this->isConsole();
        $this->resourceExpander = $resourceExpander ?? new ResourceRouteExpander();

        $this->wireSecurityRegistry();
    }

    /**
     * Wire the configured SecuritySchemeRegistry into the underlying generators.
     *
     * Reads documentation.security_schemes and documentation.middleware_map from
     * config and propagates them to the DocGenerator (and, via the shared
     * registry, the reflect generator) so the emitted spec advertises the
     * configured schemes and per-operation security requirements.
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

        $this->securityRegistry = $registry;
        $this->docGenerator->setSecurityRegistry($registry);
    }

    /**
     * Generate complete API documentation
     *
     * Runs the full pipeline:
     * 1. Expand resource routes with table schemas (if enabled)
     * 2. Merge hand-written custom API JSON definitions (if any)
     * 3. Derive paths from the live route table (reflect)
     * 4. Assemble and write openapi.json
     *
     * @param bool $force Retained for backwards-compatible signature; unused.
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

        // Step 2: Merge hand-written custom API JSON definitions. This is
        // independent of route reflection — apps may ship hand-authored
        // OpenAPI fragments under docs/json-definitions/.
        $this->processCustomApiDocs();

        // Step 3: Derive paths from the live route table.
        $this->generateFromRouteReflection();

        // Step 4: Write final openapi.json
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
     * @param bool $force Retained for backwards-compatible signature; unused.
     * @return string Path to generated openapi.json
     */
    public function generateOpenApiSpec(bool $force = false): string
    {
        $this->log("Generating OpenAPI specification...");

        // Resource-route expansion is orthogonal DB-table synthesis
        // (still gated by include_resource_routes).
        if ($this->shouldIncludeResourceRoutes()) {
            $this->expandResourceRoutes();
        }

        // Merge hand-written custom API JSON definitions, then derive paths from
        // the live route table.
        $this->processCustomApiDocs();
        $this->generateFromRouteReflection();

        return $this->writeSwaggerJson();
    }

    /**
     * Derive OpenAPI paths from the live route table and merge them into the spec.
     *
     * Loads the route table (mirroring route:debug), guards against the compiled
     * route cache (which loses per-route metadata), and reuses the same
     * SecuritySchemeRegistry already wired into the DocGenerator so both share
     * one source of truth for schemes and middleware mapping.
     */
    private function generateFromRouteReflection(): void
    {
        $router = $this->obtainRouter();
        if ($router === null) {
            $this->log("Reflect generator: Router unavailable; no paths generated.");
            return;
        }

        $registry = $this->securityRegistry ?? new SecuritySchemeRegistry([], []);
        $reflect = new RouteReflectionDocGenerator($registry, $this->context);

        $this->docGenerator->mergePaths($reflect->generate($router));
        $this->log("Reflect generator: derived paths from the live route table.");
    }

    /**
     * Resolve and populate the application Router from the container.
     *
     * Mirrors route:debug's load sequence: RouteManifest::load() (idempotent)
     * plus attribute-route scanning of app/Controllers. If the router was built
     * from the compiled route cache (which strips where/name/rateLimit/scope/
     * fields metadata needed for reflection), the manifest is reset and reloaded
     * so reflection sees fresh Route objects.
     */
    private function obtainRouter(): ?Router
    {
        $container = container($this->context);
        if (!$container->has(Router::class)) {
            return null;
        }

        /** @var Router $router */
        $router = $container->get(Router::class);

        if ($router->wasLoadedFromCache()) {
            $this->log(
                "Reflect generator: route cache detected; rebuilding fresh routes "
                . "(ROUTE_CACHE strips per-route metadata)."
            );
            RouteManifest::reset();
        }

        RouteManifest::load($router, $this->context);

        $controllers = base_path($this->context, 'app/Controllers');
        if (is_dir($controllers) && $container->has(AttributeRouteLoader::class)) {
            /** @var AttributeRouteLoader $loader */
            $loader = $container->get(AttributeRouteLoader::class);
            $loader->scanDirectory($controllers);
        }

        return $router;
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
     * Get the underlying ResourceRouteExpander instance
     *
     * @return ResourceRouteExpander
     */
    public function getResourceExpander(): ResourceRouteExpander
    {
        return $this->resourceExpander;
    }
}

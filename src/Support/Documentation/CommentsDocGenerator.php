<?php

declare(strict_types=1);

namespace Glueful\Support\Documentation;

use ReflectionClass;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ExtensionManager;
use Glueful\Services\FileFinder;
use phpDocumentor\Reflection\DocBlockFactory;
use phpDocumentor\Reflection\DocBlockFactoryInterface;
use phpDocumentor\Reflection\DocBlock;

/**
 * Comments Documentation Generator
 *
 * Generates OpenAPI documentation for routes by:
 * - Scanning extension directories for route files
 * - Extracting route documentation from doc comments
 * - Generating OpenAPI specifications
 */
class CommentsDocGenerator
{
    /** @var string|null Base path for local extensions (fallback for reflection failures) */
    private ?string $localExtensionsPath;

    /** @var string Base path to routes directory */
    private string $routesPath;

    /** @var string Output directory for generated extension documentation */
    private string $outputPath;

    /** @var string Output directory for generated routes documentation */
    private string $routesOutputPath;

    /** @var array<string, mixed> Processed route information */
    private array $routeData = [];

    /** @var ExtensionManager Extensions manager for checking enabled extensions */
    private ExtensionManager $extensionsManager;

    /** @var ApplicationContext */
    private ApplicationContext $context;

    /** @var array<string, array{mtime: int, data: array<string, mixed>}> File parse cache */
    private array $parseCache = [];

    /** @var DocBlockFactoryInterface Doc block parser factory */
    private DocBlockFactoryInterface $docBlockFactory;

    /**
     * Constructor
     *
     * @param string|null $localExtensionsPath Custom path for local extensions (fallback)
     * @param string|null $outputPath Custom output path for extension documentation
     * @param string|null $routesPath Custom path to routes directory
     * @param string|null $routesOutputPath Custom output path for routes documentation
     * @param ExtensionManager|null $extensionsManager Extensions manager instance
     * @param ApplicationContext $context Application context
     */
    public function __construct(
        ApplicationContext $context,
        ?string $localExtensionsPath = null,
        ?string $outputPath = null,
        ?string $routesPath = null,
        ?string $routesOutputPath = null,
        ?ExtensionManager $extensionsManager = null
    ) {
        $this->context = $context;
        // Local extensions path is optional - only used as fallback when reflection fails
        $this->localExtensionsPath = $localExtensionsPath
            ?? config($this->context, 'app.paths.project_extensions');
        $this->outputPath = $outputPath
            ?? config($this->context, 'documentation.paths.extension_definitions');
        $this->routesPath = $routesPath
            ?? config($this->context, 'documentation.sources.routes');
        $this->routesOutputPath = $routesOutputPath
            ?? config($this->context, 'documentation.paths.route_definitions');
        $this->extensionsManager = $extensionsManager
            ?? container($this->context)->get(ExtensionManager::class);
        $this->docBlockFactory = DocBlockFactory::createInstance();
    }

    /**
     * Find matching closing brace/bracket in a string
     *
     * Handles nested braces and quoted strings properly.
     *
     * @param string $str String to search in
     * @param int $startPos Position of opening brace (or position to start searching from)
     * @param string $open Opening character (default '{')
     * @param string $close Closing character (default '}')
     * @return int Position of matching closing brace, or -1 if not found
     */
    private function findMatchingBrace(string $str, int $startPos, string $open = '{', string $close = '}'): int
    {
        $count = 0;
        $inQuotes = false;
        $length = strlen($str);

        for ($i = $startPos; $i < $length; $i++) {
            $char = $str[$i];

            if ($char === '"' && ($i === 0 || $str[$i - 1] !== '\\')) {
                $inQuotes = !$inQuotes;
            } elseif (!$inQuotes) {
                if ($char === $open) {
                    $count++;
                } elseif ($char === $close) {
                    $count--;
                    if ($count === 0) {
                        return $i;
                    }
                }
            }
        }

        return -1;
    }

    /**
     * Get cached parse result for a file
     *
     * Returns cached data if file hasn't been modified since last parse.
     *
     * @param string $file File path
     * @return array<string, mixed>|null Cached data or null if not cached/stale
     */
    private function getCachedParse(string $file): ?array
    {
        if (!file_exists($file)) {
            return null;
        }

        $mtime = filemtime($file);
        if ($mtime === false) {
            return null;
        }

        $cacheKey = $file;
        if (isset($this->parseCache[$cacheKey]) && $this->parseCache[$cacheKey]['mtime'] === $mtime) {
            return $this->parseCache[$cacheKey]['data'];
        }

        return null;
    }

    /**
     * Store parse result in cache
     *
     * @param string $file File path
     * @param array<string, mixed> $data Parsed data to cache
     */
    private function setCachedParse(string $file, array $data): void
    {
        $mtime = filemtime($file);
        if ($mtime === false) {
            return;
        }

        $this->parseCache[$file] = [
            'mtime' => $mtime,
            'data' => $data
        ];
    }

    /**
     * Clear the parse cache
     */
    public function clearCache(): void
    {
        $this->parseCache = [];
    }

    /**
     * Generate documentation for all extensions and routes
     *
     * Scans enabled extensions and routes directories and generates documentation
     *
     * @return array<string> List of generated documentation files
     */
    public function generateAll(): array
    {
        $generatedFiles = [];

        // Generate docs for all registered extensions (modern system doesn't distinguish enabled/disabled)
        $providers = $this->extensionsManager->getProviders();
        $meta = $this->extensionsManager->listMeta();

        foreach ($providers as $providerClass => $_provider) {
            $metadata = $meta[$providerClass] ?? [];
            $extensionName = $metadata['slug'] ?? basename(str_replace('\\', '/', $providerClass));

            // Try to find routes file - modern extensions use routes/ directory
            $routeFile = $this->findExtensionRoutesFile($providerClass, $extensionName);

            if ($routeFile !== null && file_exists($routeFile)) {
                $docFile = $this->generateForExtension($extensionName, $routeFile);
                if ($docFile !== null) {
                    $generatedFiles[] = $docFile;
                }
            }
        }

        // Then, generate docs for main routes
        $routeFiles = $this->generateForRoutes();
        $generatedFiles = array_merge($generatedFiles, $routeFiles);

        return $generatedFiles;
    }

    /**
     * Find the routes file for an extension based on its provider class
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
            if ($packageRoot !== null) {
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
            }
        } catch (\ReflectionException) {
            // Provider class not found, skip
        }

        // Fallback: try local extensions directory if available (for local dev)
        if ($this->localExtensionsPath !== null && is_dir($this->localExtensionsPath)) {
            $fallbackPaths = [
                $this->localExtensionsPath . '/' . $extensionName . '/src/routes.php',
                $this->localExtensionsPath . '/' . $extensionName . '/routes.php',
                $this->localExtensionsPath . '/' . $extensionName . '/routes/' . $extensionName . '.php',
                $this->localExtensionsPath . '/' . $extensionName . '/routes/routes.php',
            ];

            foreach ($fallbackPaths as $path) {
                if (file_exists($path)) {
                    return $path;
                }
            }
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
     * Generate documentation for all main route files
     *
     * Scans the routes directory and generates documentation for each route file
     *
     * @return array<string> List of generated documentation files
     */
    public function generateForRoutes(): array
    {
        $generatedFiles = [];

        // Create routes docs directory if it doesn't exist
        if (!is_dir($this->routesOutputPath)) {
            mkdir($this->routesOutputPath, 0755, true);
        }

        // Get all route files in the routes directory using FileFinder
        $fileFinder = container($this->context)->get(FileFinder::class);
        $routeFiles = $fileFinder->findRouteFiles([$this->routesPath]);

        foreach ($routeFiles as $routeFileObj) {
            $routeFile = $routeFileObj->getPathname();
            $routeName = basename($routeFile, '.php');
            $docFile = $this->generateForRouteFile($routeName, $routeFile);
            if ($docFile !== null) {
                $generatedFiles[] = $docFile;
            }
        }

        return $generatedFiles;
    }

    /**
     * Generate documentation for a specific route file
     *
     * @param string $routeName Route file name (without extension)
     * @param string $routeFile Path to route file
     * @param bool $forceGenerate Force generation even if manual file exists
     * @return string|null Path to generated file or null on failure
     */
    public function generateForRouteFile(string $routeName, string $routeFile, bool $forceGenerate = false): ?string
    {
        // Define output file path
        $outputFile = $this->routesOutputPath . '/' . strtolower($routeName) . '.json';

        // Parse routes file to extract doc comments
        $this->parseRouteDocComments($routeFile);

        if ($this->routeData === []) {
            return null;
        }

        // Generate OpenAPI specification
        $openApiSpec = $this->generateRouteOpenApiSpec($routeName);

        // Write to file
        file_put_contents($outputFile, json_encode($openApiSpec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $outputFile;
    }

    /**
     * Generate OpenAPI specification for a route file
     *
     * @param string $routeName Route file name
     * @return array<string, mixed> OpenAPI specification
     */
    private function generateRouteOpenApiSpec(string $routeName): array
    {
        $paths = [];

        // Get route prefix from config
        $routePrefixes = config($this->context, 'documentation.sources.route_prefixes', []);
        $routeFileName = strtolower($routeName) . '.php';
        $pathPrefix = $routePrefixes[$routeFileName] ?? '';

        // Format route name for display
        $formattedRouteName = str_replace(['_', '-'], ' ', $routeName);
        $formattedRouteName = ucwords($formattedRouteName);

        // Group routes by tag
        $routesByTag = [];
        foreach ($this->routeData as $route) {
            $tag = $route['tag'];

            if (!isset($routesByTag[$tag])) {
                $routesByTag[$tag] = [];
            }

            $routesByTag[$tag][] = $route;
        }

        // Create tags
        $tags = [];
        foreach (array_keys($routesByTag) as $tag) {
            $tags[] = [
                'name' => $tag,
                'description' => 'Operations related to ' . $tag
            ];
        }

        // Generate paths
        foreach ($this->routeData as $route) {
            // Apply route prefix from config
            $path = $pathPrefix . $route['path'];
            $method = strtolower($route['method']);

            // Initialize path if it doesn't exist
            if (!isset($paths[$path])) {
                $paths[$path] = [];
            }

            // Create operation object
            $operation = [
                'tags' => [$route['tag']],
                'summary' => $route['summary'],
                'description' => $route['description'],
                'responses' => $route['responses']
            ];

            // Add request body if present (auto-detects multipart/form-data for file uploads)
            if (($route['requestBody'] ?? []) !== []) {
                $operation['requestBody'] = $this->buildRequestBody($route['requestBody']);
            }

            // Add security requirement if authentication is required
            if ((bool)($route['requiresAuth'] ?? false)) {
                $operation['security'] = [['BearerAuth' => []]];
            }

            // Add path parameters if any
            if (($route['pathParams'] ?? []) !== []) {
                $operation['parameters'] = $route['pathParams'];
            }

            // Add operation to path
            $paths[$path][$method] = $operation;
        }

        // Create OpenAPI specification
        return [
            'openapi' => '3.0.0',
            'info' => [
                'title' => $formattedRouteName . ' Routes',
                'description' => 'API documentation for ' . $formattedRouteName . ' routes',
                'version' => config($this->context, 'app.version_full', '1.0.0')
            ],
            'servers' => config($this->context, 'documentation.servers', [
                [
                    'url' => rtrim(config($this->context, 'app.urls.base', 'http://localhost'), '/'),
                    'description' => 'API Server'
                ]
            ]),
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    'BearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'JWT'
                    ]
                ]
            ],
            'tags' => $tags
        ];
    }

    /**
     * Generate documentation for a specific extension
     *
     * @param string $extensionName Extension name
     * @param string $routeFile Path to routes file
     * @param bool $forceGenerate Force generation even if manual file exists
     * @return string|null Path to generated file or null on failure
     */
    public function generateForExtension(string $extensionName, string $routeFile, bool $forceGenerate = false): ?string
    {
        // Create extension docs directory if it doesn't exist
        $extDocsDir = $this->outputPath . '/' . $extensionName;
        if (!is_dir($extDocsDir)) {
            mkdir($extDocsDir, 0755, true);
        }

        // Define output file path
        $outputFile = $extDocsDir . '/' . strtolower($extensionName) . '.json';

        // Parse routes file to extract doc comments
        $this->parseRouteDocComments($routeFile);

        if ($this->routeData === []) {
            return null;
        }

        // Generate OpenAPI specification
        $openApiSpec = $this->generateOpenApiSpec($extensionName);

        // Write to file
        file_put_contents($outputFile, json_encode($openApiSpec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $outputFile;
    }

    /**
     * Parse route file to extract documentation from doc comments
     *
     * Uses caching to avoid re-parsing unchanged files.
     *
     * @param string $routeFile Path to routes file
     */
    private function parseRouteDocComments(string $routeFile): void
    {
        // Check cache first
        $cached = $this->getCachedParse($routeFile);
        if ($cached !== null) {
            $this->routeData = $cached;
            return;
        }

        $this->routeData = [];

        // Get file content
        $content = file_get_contents($routeFile);
        if (!$content) {
            return;
        }

        // Parse doc comment-based documentation
        $this->parseDocCommentBasedDocs($routeFile);

        // Cache the parsed data
        $this->setCachedParse($routeFile, $this->routeData);
    }

    /**
     * Parse doc comment-based documentation
     *
     * Uses PHP tokenizer and phpDocumentor library for robust parsing.
     *
     * @param string $routeFile Path to the routes file
     * @return bool True if any doc comment-based documentation was found
     */
    private function parseDocCommentBasedDocs(string $routeFile): bool
    {
        $content = file_get_contents($routeFile);
        if (!$content) {
            return false;
        }

        $foundDocComments = false;
        $docComments = $this->extractDocCommentsFromFile($content);

        foreach ($docComments as $docComment) {
            $routeInfo = $this->parseRouteDocBlock($docComment);
            if ($routeInfo !== null) {
                $this->routeData[] = $routeInfo;
                $foundDocComments = true;
            }
        }

        return $foundDocComments;
    }

    /**
     * Extract all doc comments from file content using PHP tokenizer
     *
     * @param string $content File content
     * @return array<string> Array of doc comment strings
     */
    private function extractDocCommentsFromFile(string $content): array
    {
        $docComments = [];
        $tokens = token_get_all($content);

        foreach ($tokens as $token) {
            if (is_array($token) && $token[0] === T_DOC_COMMENT) {
                $docComments[] = $token[1];
            }
        }

        return $docComments;
    }

    /**
     * Parse a doc block for route documentation
     *
     * @param string $docComment Raw doc comment string
     * @return array<string, mixed>|null Parsed route data or null if not a route doc
     */
    private function parseRouteDocBlock(string $docComment): ?array
    {
        try {
            $docBlock = $this->docBlockFactory->create($docComment);
        } catch (\Exception $e) {
            // Fall back to regex if doc block parsing fails
            return $this->parseRouteDocBlockFallback($docComment);
        }

        // Check for @route tag
        $routeTags = $docBlock->getTagsByName('route');
        if ($routeTags === []) {
            return null;
        }

        // Parse route tag: "METHOD /path"
        $routeTag = (string) $routeTags[0];
        if (!preg_match('/^([A-Z]+)\s+(.+)$/', trim($routeTag), $routeMatch)) {
            return null;
        }

        $httpMethod = $routeMatch[1];
        $routePath = trim($routeMatch[2]);

        // Extract tags using the library
        $summary = $this->getDocBlockTagValue($docBlock, 'summary');
        $description = $this->getDocBlockTagValue($docBlock, 'description');
        $tag = $this->getDocBlockTagValue($docBlock, 'tag');
        $requiresAuth = strtolower($this->getDocBlockTagValue($docBlock, 'requiresAuth')) === 'true';

        // For complex tags, fall back to original extraction methods
        $responses = $this->extractSimplifiedResponses($docComment);
        $requestBody = $this->extractSimplifiedRequestBody($docComment);
        $pathParams = $this->extractSimplifiedParameters($docComment);

        // Extract path parameters if not explicitly defined
        if ($pathParams === [] && strpos($routePath, '{') !== false) {
            $pathParams = $this->extractPathParameters($routePath);
        }

        return [
            'method' => strtoupper($httpMethod),
            'path' => $routePath,
            'summary' => $summary !== '' ? $summary : $docBlock->getSummary(),
            'description' => $description !== '' ? $description : $docBlock->getDescription()->render(),
            'tag' => $tag !== '' ? $tag : $this->deriveTagFromPath($routePath),
            'requiresAuth' => $requiresAuth,
            'responses' => $responses,
            'requestBody' => $requestBody,
            'pathParams' => $pathParams
        ];
    }

    /**
     * Get value from a doc block tag
     *
     * @param DocBlock $docBlock Parsed doc block
     * @param string $tagName Tag name (without @)
     * @return string Tag value or empty string
     */
    private function getDocBlockTagValue(DocBlock $docBlock, string $tagName): string
    {
        $tags = $docBlock->getTagsByName($tagName);
        if ($tags === []) {
            return '';
        }
        return trim((string) $tags[0]);
    }

    /**
     * Fallback parser for malformed doc blocks
     *
     * @param string $docComment Raw doc comment
     * @return array<string, mixed>|null Parsed route data or null
     */
    private function parseRouteDocBlockFallback(string $docComment): ?array
    {
        // Check for @route annotation using regex
        if (!preg_match('/@route\s+([A-Z]+)\s+([^\s\*]+)/', $docComment, $routeMatch)) {
            return null;
        }

        $httpMethod = $routeMatch[1];
        $routePath = $routeMatch[2];

        $summary = $this->extractDocTag($docComment, '@summary');
        $description = $this->extractDocTag($docComment, '@description');
        $tag = $this->extractDocTag($docComment, '@tag');
        $requiresAuth = strtolower($this->extractDocTag($docComment, '@requiresAuth')) === 'true';

        $responses = $this->extractSimplifiedResponses($docComment);
        $requestBody = $this->extractSimplifiedRequestBody($docComment);
        $pathParams = $this->extractSimplifiedParameters($docComment);

        if ($pathParams === [] && strpos($routePath, '{') !== false) {
            $pathParams = $this->extractPathParameters($routePath);
        }

        return [
            'method' => strtoupper($httpMethod),
            'path' => $routePath,
            'summary' => $summary,
            'description' => $description,
            'tag' => $tag !== '' ? $tag : $this->deriveTagFromPath($routePath),
            'requiresAuth' => $requiresAuth,
            'responses' => $responses,
            'requestBody' => $requestBody,
            'pathParams' => $pathParams
        ];
    }

    /**
     * Extract simplified request body format from doc comment
     * Format: @requestBody field1:type[enum]="description" field2:type="description" {required=field1,field2}
     *
     * @param string $docComment Doc comment to parse
     * @return array<string, mixed>|null Request body schema or null if not found
     */
    private function extractSimplifiedRequestBody(string $docComment): ?array
    {
        // Updated regex to capture multiline @requestBody content
        if (!preg_match('/@requestBody\s+([\s\S]*?)(?=@\w+|\*\/|$)/', $docComment, $matches)) {
            return null;
        }

        $requestBodyStr = $matches[1];
        $required = [];

        // Clean up the multiline content by removing comment markers and extra whitespace
        $requestBodyStr = preg_replace('/\*\s*/', ' ', $requestBodyStr);
        $requestBodyStr = preg_replace('/\s+/', ' ', $requestBodyStr);
        $requestBodyStr = trim($requestBodyStr);

        // Extract required fields if specified
        if (preg_match('/\{required=([^}]+)\}/', $requestBodyStr, $reqMatches)) {
            $required = array_map('trim', explode(',', $reqMatches[1]));
            // Remove the required part from the string
            $requestBodyStr = str_replace($reqMatches[0], '', $requestBodyStr);
        }

        // Parse fields - supports: name:type, name:type[], name:type[enum], name:type="desc"
        // Array syntax: name:type[] means array of that type (e.g., media:file[] = array of files)
        $properties = [];
        $pattern = '/(\w+):(file|string|integer|number|boolean|array|object)(\[\])?(?:\[([^\]]*)\])?(?:="([^"]*)")?/';

        preg_match_all($pattern, $requestBodyStr, $fieldMatches, PREG_SET_ORDER);

        foreach ($fieldMatches as $match) {
            $name = $match[1];
            $type = $match[2];
            // Check if [] suffix was captured (indicates array type)
            $arraySuffix = $match[3] ?? '';
            $isArray = $arraySuffix !== '';
            $enum = isset($match[4]) && $match[4] !== '' ? array_map('trim', explode(',', $match[4])) : null;
            $description = $match[5] ?? '';

            // Build the base property
            $itemProperty = ['type' => $type];

            if ($enum !== null) {
                $itemProperty['enum'] = $enum;
            }

            // Wrap in array if [] suffix was used
            if ($isArray) {
                $property = [
                    'type' => 'array',
                    'items' => $itemProperty
                ];
            } else {
                $property = $itemProperty;
            }

            if ($description !== '') {
                $property['description'] = $description;
            }

            $properties[$name] = $property;
        }

        $schema = [
            'type' => 'object',
            'properties' => $properties === [] ? new \stdClass() : $properties
        ];

        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * Extract simplified responses from doc comment
     * Format: @response code contentType "description" {schema}
     *
     * @param string $docComment Doc comment to parse
     * @return array<string, mixed> Response definitions
     */
    private function extractSimplifiedResponses(string $docComment): array
    {
        $responses = [];

        // First, find all @response positions
        preg_match_all(
            '/@response\s+(\d+)\s+([\w\/\-+]+)?\s+"([^"]*)"/',
            $docComment,
            $responseMatches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        );

        foreach ($responseMatches as $i => $match) {
            $statusCode = $match[1][0];
            $contentType = $match[2][0] !== '' ? $match[2][0] : 'application/json';
            $description = $match[3][0];

            // Find the position after the quoted description
            $startPos = $match[0][1] + strlen($match[0][0]);

            // Find the end position (next @response or end of comment)
            $endPos = strlen($docComment);
            if (isset($responseMatches[$i + 1])) {
                $endPos = $responseMatches[$i + 1][0][1];
            } else {
                // Look for end of comment or next @ tag that's not @response
                $pattern = '/\*\/|\*\s*@(?!response)[a-zA-Z]/';
                if (preg_match($pattern, $docComment, $endMatch, PREG_OFFSET_CAPTURE, $startPos)) {
                    $endPos = $endMatch[0][1];
                }
            }

            // Extract the content between start and end
            $content = substr($docComment, $startPos, $endPos - $startPos);

            // Check if there's a schema (starts with {)
            if (preg_match('/\s*\{([\s\S]*)\}\s*$/m', $content, $schemaMatch)) {
                $schemaContent = '{' . $schemaMatch[1] . '}';
                $schema = $this->parseSimplifiedSchema($schemaContent);

                $responses[$statusCode] = [
                    'description' => $description !== ''
                        ? $description
                        : $this->getDefaultResponseDescription($statusCode),
                    'content' => [
                        $contentType => [
                            'schema' => $schema
                        ]
                    ]
                ];
            } else {
                $responses[$statusCode] = [
                    'description' => $description !== ''
                        ? $description
                        : $this->getDefaultResponseDescription($statusCode)
                ];
            }
        }


        // Add default responses if none specified
        if ($responses === []) {
            $responses = [
                '200' => [
                    'description' => 'Successful operation'
                ],
                '400' => [
                    'description' => 'Bad request'
                ],
                '500' => [
                    'description' => 'Server error'
                ]
            ];
        }

        return $responses;
    }

    /**
     * Parse simplified schema format
     * Format: {field1:type="description", field2:{nestedField:type}}
     *
     * @param string $schemaStr Schema string to parse
     * @return array<string, mixed> Parsed schema
     */
    private function parseSimplifiedSchema(string $schemaStr): array
    {
        // Clean up the schema string - remove comment markers and normalize whitespace
        $schemaStr = preg_replace('/\*\s*/', ' ', $schemaStr);
        $schemaStr = preg_replace('/\s+/', ' ', $schemaStr);

        // Only trim outer braces if this is the root level
        if (substr($schemaStr, 0, 1) === '{' && substr($schemaStr, -1) === '}') {
            $schemaStr = substr($schemaStr, 1, -1);
        }
        $schemaStr = trim($schemaStr);

        $parts = [];
        $start = 0;
        $braceCount = 0;
        $bracketCount = 0;
        $inQuotes = false;

        // Split on commas, but respect nested objects, arrays, and quoted strings
        for ($i = 0; $i < strlen($schemaStr); $i++) {
            $char = $schemaStr[$i];

            if ($char === '"' && ($i === 0 || $schemaStr[$i - 1] !== '\\')) {
                $inQuotes = !$inQuotes;
            } elseif (!$inQuotes) {
                if ($char === '{') {
                    $braceCount++;
                } elseif ($char === '}') {
                    $braceCount--;
                } elseif ($char === '[') {
                    $bracketCount++;
                } elseif ($char === ']') {
                    $bracketCount--;
                } elseif ($char === ',' && $braceCount === 0 && $bracketCount === 0) {
                    $parts[] = substr($schemaStr, $start, $i - $start);
                    $start = $i + 1;
                }
            }
        }

        // Add the last part
        if ($start < strlen($schemaStr)) {
            $parts[] = substr($schemaStr, $start);
        }

        $properties = [];
        $type = 'object';

        // Process simple array notation
        if (strpos($schemaStr, '[') === 0 && substr($schemaStr, -1) === ']') {
            $type = 'array';
            $itemsSchema = $this->parseSimplifiedSchema(substr($schemaStr, 1, -1));
            return [
                'type' => 'array',
                'items' => $itemsSchema
            ];
        }

        // Process each part as a property
        foreach ($parts as $part) {
            $part = trim($part);

            // Check for array with object definition first (field:array=[{...}])
            if (preg_match('/^(\w+):array=\[/', $part, $arrayMatch)) {
                $name = $arrayMatch[1];
                $bracketStartPos = strpos($part, '[');
                $arrayEndPos = $this->findMatchingBrace($part, $bracketStartPos, '[', ']');

                if ($arrayEndPos > $bracketStartPos) {
                    $arrayStartPos = $bracketStartPos + 1;
                    $arrayContent = substr($part, $arrayStartPos, $arrayEndPos - $arrayStartPos);

                    // Remove outer braces if present and parse the object content
                    if (preg_match('/^\s*\{([\s\S]*)\}\s*$/', $arrayContent, $objectMatch)) {
                        $itemsSchema = $this->parseSimplifiedSchema('{' . $objectMatch[1] . '}');
                    } else {
                        // Fallback for non-object array items
                        $itemsSchema = ['type' => 'object'];
                    }

                    $properties[$name] = [
                        'type' => 'array',
                        'items' => $itemsSchema
                    ];
                }
            } elseif (preg_match('/(\w+):\{/', $part)) {
                // Check for nested object (field:{...})
                $colonPos = strpos($part, ':');
                $name = substr($part, 0, $colonPos);
                $openBracePos = strpos($part, '{', $colonPos);
                $closeBracePos = $this->findMatchingBrace($part, $openBracePos);

                if ($closeBracePos > $openBracePos) {
                    // Extract just the content between the braces
                    $nestedContent = substr($part, $openBracePos + 1, $closeBracePos - $openBracePos - 1);
                    $nestedSchema = $this->parseSimplifiedSchema('{' . $nestedContent . '}');
                    $properties[$name] = $nestedSchema;
                } else {
                    // If we can't find a closing brace, use the rest of the string
                    $nestedContent = substr($part, $openBracePos);
                    $nestedSchema = $this->parseSimplifiedSchema($nestedContent);
                    $properties[$name] = $nestedSchema;
                }
            } elseif (
                preg_match(
                    '/(\w+):(string|integer|number|boolean|array|object)(?:\[([^\]]*)\])?(?:="([^"]*)")?/',
                    $part,
                    $match
                )
            ) {
                $name = $match[1];
                $propType = $match[2];
                $description = isset($match[4]) ? $match[4] : (isset($match[3]) ? $match[3] : '');

                $property = ['type' => $propType];

                if (isset($match[3]) && preg_match('/^[^"=]/', $match[3])) {
                    // This is an enum
                    $property['enum'] = array_map('trim', explode(',', $match[3]));
                }

                if ($description !== '') {
                    $property['description'] = $description;
                }

                $properties[$name] = $property;
            } elseif (preg_match('/(\w+):(\[[^\]]+\])/', $part, $match)) {
                $name = $match[1];
                $itemsSchema = $this->parseSimplifiedSchema(substr($match[2], 1, -1));
                $properties[$name] = [
                    'type' => 'array',
                    'items' => $itemsSchema
                ];
            }
        }

        return [
            'type' => $type,
            'properties' => $properties === [] ? new \stdClass() : $properties
        ];
    }

    /**
     * Extract simplified parameters from doc comment
     * Format: @param name location type required "description"
     *
     * @param string $docComment Doc comment to parse
     * @return array<int, array<string, mixed>> Parameter definitions
     */
    private function extractSimplifiedParameters(string $docComment): array
    {
        $params = [];
        $pattern = '/@param\s+(\w+)\s+(path|query|header|cookie)\s+(string|integer|number|boolean|array|object)'
            . '\s+(true|false)\s+"([^"]*)"/';

        preg_match_all($pattern, $docComment, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $params[] = [
                'name' => $match[1],
                'in' => $match[2],
                'required' => $match[4] === 'true',
                'description' => $match[5],
                'schema' => ['type' => $match[3]]
            ];
        }

        return $params;
    }

    /**
     * Derive tag from path
     *
     * @param string $path API path
     * @return string Tag name
     */
    private function deriveTagFromPath(string $path): string
    {
        $pathParts = explode('/', trim($path, '/'));
        return ucfirst($pathParts[0] ?? 'default');
    }

    /**
     * Extract a specific tag value from a doc comment
     *
     * @param string $docComment Doc comment to parse
     * @param string $tagName Tag name to extract
     * @return string Tag value or empty string if not found
     */
    private function extractDocTag(string $docComment, string $tagName): string
    {
        $pattern = '/' . preg_quote($tagName) . '\s+([^\r\n]+)/';
        if (preg_match($pattern, $docComment, $matches)) {
            return trim($matches[1]);
        }
        return '';
    }

    /**
     * Extract path parameters from a route path
     *
     * @param string $path Route path
     * @return array<int, array<string, mixed>> Path parameters
     */
    private function extractPathParameters(string $path): array
    {
        $params = [];
        if (preg_match_all('/\{([^}]+)\}/', $path, $matches)) {
            foreach ($matches[1] as $param) {
                $params[] = [
                    'name' => $param,
                    'in' => 'path',
                    'required' => true,
                    'schema' => ['type' => 'string']
                ];
            }
        }
        return $params;
    }

    /**
     * Check if a request body schema contains file fields
     *
     * Detects both single file fields and arrays of files.
     *
     * @param array<string, mixed> $schema Request body schema
     * @return bool True if file fields are present
     */
    private function hasFileFields(array $schema): bool
    {
        if (!isset($schema['properties']) || !is_array($schema['properties'])) {
            return false;
        }

        foreach ($schema['properties'] as $property) {
            if (is_array($property)) {
                // Check for file type (before conversion)
                if (isset($property['type']) && $property['type'] === 'file') {
                    return true;
                }
                // Check for binary format (after conversion)
                if (isset($property['format']) && $property['format'] === 'binary') {
                    return true;
                }
                // Check for array of files
                if (isset($property['type']) && $property['type'] === 'array' && isset($property['items'])) {
                    $items = $property['items'];
                    if (is_array($items)) {
                        if (isset($items['type']) && $items['type'] === 'file') {
                            return true;
                        }
                        if (isset($items['format']) && $items['format'] === 'binary') {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * Convert file fields in schema to proper OpenAPI binary format
     *
     * OpenAPI 3.0 represents file uploads as: type: string, format: binary
     * Handles both single file fields and arrays of files.
     *
     * @param array<string, mixed> $schema Request body schema
     * @return array<string, mixed> Schema with file fields converted
     */
    private function convertFileFieldsToOpenApi(array $schema): array
    {
        if (!isset($schema['properties']) || !is_array($schema['properties'])) {
            return $schema;
        }

        foreach ($schema['properties'] as $name => $property) {
            if (!is_array($property)) {
                continue;
            }

            // Convert single file field
            if (isset($property['type']) && $property['type'] === 'file') {
                $schema['properties'][$name]['type'] = 'string';
                $schema['properties'][$name]['format'] = 'binary';
            }

            // Convert array of files
            if (isset($property['type']) && $property['type'] === 'array' && isset($property['items'])) {
                $items = $property['items'];
                if (is_array($items) && isset($items['type']) && $items['type'] === 'file') {
                    $schema['properties'][$name]['items']['type'] = 'string';
                    $schema['properties'][$name]['items']['format'] = 'binary';
                }
            }
        }

        return $schema;
    }

    /**
     * Get the appropriate content type for a request body
     *
     * Returns multipart/form-data if the schema contains file fields,
     * otherwise returns application/json.
     *
     * @param array<string, mixed> $schema Request body schema
     * @return string Content type
     */
    private function getRequestBodyContentType(array $schema): string
    {
        return $this->hasFileFields($schema) ? 'multipart/form-data' : 'application/json';
    }

    /**
     * Build the request body OpenAPI structure with appropriate content type
     *
     * @param array<string, mixed> $schema Request body schema
     * @return array<string, mixed> OpenAPI requestBody structure
     */
    private function buildRequestBody(array $schema): array
    {
        $contentType = $this->getRequestBodyContentType($schema);
        $convertedSchema = $this->convertFileFieldsToOpenApi($schema);

        return [
            'required' => true,
            'content' => [
                $contentType => [
                    'schema' => $convertedSchema
                ]
            ]
        ];
    }

    /**
     * Get standard description for HTTP status code
     *
     * @param string $statusCode HTTP status code
     * @return string Description
     */
    private function getDefaultResponseDescription(string $statusCode): string
    {
        $descriptions = [
            '200' => 'OK',
            '201' => 'Created',
            '204' => 'No Content',
            '400' => 'Bad Request',
            '401' => 'Unauthorized',
            '403' => 'Forbidden',
            '404' => 'Not Found',
            '500' => 'Internal Server Error'
        ];

        return $descriptions[$statusCode] ?? 'Response';
    }

    /**
     * Generate OpenAPI specification from parsed route data
     *
     * @param string $extensionName Extension name
     * @return array<string, mixed> OpenAPI specification
     */
    private function generateOpenApiSpec(string $extensionName): array
    {
        $paths = [];

        // Format extension name for display
        $formattedExtName = str_replace(['_', '-'], ' ', $extensionName);
        $formattedExtName = ucwords($formattedExtName);

        // Group routes by tag
        $routesByTag = [];
        foreach ($this->routeData as $route) {
            $tag = $route['tag'];

            if (!isset($routesByTag[$tag])) {
                $routesByTag[$tag] = [];
            }

            $routesByTag[$tag][] = $route;
        }

        // Create tags
        $tags = [];
        foreach (array_keys($routesByTag) as $tag) {
            $tags[] = [
                'name' => $tag,
                'description' => 'Operations related to ' . $tag
            ];
        }

        // Generate paths
        foreach ($this->routeData as $route) {
            $path = $route['path'];
            $method = strtolower($route['method']);

            // Initialize path if it doesn't exist
            if (!isset($paths[$path])) {
                $paths[$path] = [];
            }

            // Create operation object
            $operation = [
                'tags' => [$route['tag']],
                'summary' => $route['summary'],
                'description' => $route['description'],
                'responses' => $route['responses']
            ];

            // Add request body if present (auto-detects multipart/form-data for file uploads)
            if (($route['requestBody'] ?? []) !== []) {
                $operation['requestBody'] = $this->buildRequestBody($route['requestBody']);
            }

            // Add security requirement if authentication is required
            if ((bool)($route['requiresAuth'] ?? false)) {
                $operation['security'] = [['BearerAuth' => []]];
            }

            // Add path parameters if any
            if (($route['pathParams'] ?? []) !== []) {
                $operation['parameters'] = $route['pathParams'];
            }

            // Add operation to path
            $paths[$path][$method] = $operation;
        }

        // Create OpenAPI specification
        return [
            'openapi' => '3.0.0',
            'info' => [
                'title' => $formattedExtName . ' API',
                'description' => 'API documentation for ' . $formattedExtName . ' extension',
                'version' => config($this->context, 'app.version_full', '1.0.0')
            ],
            'servers' => config($this->context, 'documentation.servers', [
                [
                    'url' => rtrim(config($this->context, 'app.urls.base', 'http://localhost'), '/'),
                    'description' => 'API Server'
                ]
            ]),
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    'BearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'JWT'
                    ]
                ]
            ],
            'tags' => $tags
        ];
    }
}

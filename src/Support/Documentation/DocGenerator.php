<?php

declare(strict_types=1);

namespace Glueful\Support\Documentation;

/**
 * API Documentation Generator
 *
 * Generates OpenAPI/Swagger documentation from JSON definition files.
 * Handles both table definitions and custom API endpoint documentation.
 * Supports both OpenAPI 3.0.x and 3.1.0 specifications.
 */
class DocGenerator
{
    /** @var array<string, mixed> OpenAPI paths storage */
    private array $paths = [];

    /** @var array<string, mixed> OpenAPI schemas storage */
    private array $schemas = [];

    /** @var array<string, mixed> Extension tags storage */
    private array $extensionTags = [];

    /** @var ResourceRouteExpander|null Resource route expander for {resource} expansion */
    private ?ResourceRouteExpander $resourceExpander = null;

    /** @var string OpenAPI version to use */
    private string $openApiVersion;

    /**
     * Constructor
     *
     * @param string|null $openApiVersion OpenAPI version to use (defaults to config value)
     */
    public function __construct(?string $openApiVersion = null)
    {
        $this->openApiVersion = $openApiVersion ?? config('documentation.openapi_version', '3.1.0');
    }

    /**
     * Check if using OpenAPI 3.1.x
     *
     * @return bool True if using 3.1.x
     */
    private function isOpenApi31(): bool
    {
        return version_compare($this->openApiVersion, '3.1.0', '>=');
    }

    /**
     * Create a nullable type definition based on OpenAPI version
     *
     * In OpenAPI 3.0.x: { "type": "string", "nullable": true }
     * In OpenAPI 3.1.0: { "type": ["string", "null"] }
     *
     * @param string $type The base type
     * @param bool $nullable Whether the type is nullable
     * @return array<string, mixed> Type definition
     */
    private function createTypeDefinition(string $type, bool $nullable = false): array
    {
        if ($nullable && $this->isOpenApi31()) {
            return ['type' => [$type, 'null']];
        }

        $definition = ['type' => $type];
        if ($nullable) {
            $definition['nullable'] = true;
        }

        return $definition;
    }

    /**
     * Create a nullable type definition with format based on OpenAPI version
     *
     * @param string $type The base type
     * @param string $format The format
     * @param bool $nullable Whether the type is nullable
     * @return array<string, mixed> Type definition
     */
    private function createTypeWithFormat(string $type, string $format, bool $nullable = false): array
    {
        $definition = $this->createTypeDefinition($type, $nullable);
        $definition['format'] = $format;
        return $definition;
    }

    /**
     * Generate documentation from table definition
     *
     * Creates API documentation for database table endpoints.
     *
     * @param string $filename JSON definition file path
     */
    public function generateFromJson(string $filename): void
    {
        $jsonContent = file_get_contents($filename);
        if (!$jsonContent) {
            error_log("DocGenerator: Could not read table definition file: $filename");
            return;
        }

        $definition = json_decode($jsonContent, true);
        if ($definition === null || $definition === false) {
            error_log("DocGenerator: Invalid JSON in table definition file: $filename");
            return;
        }

        $tableName = $definition['table']['name'];
        // Use table name directly instead of JSON filename
        $resourcePath = strtolower($tableName);

        $this->addPathsFromJson($resourcePath, $tableName, $definition);
        $this->addSchemaFromJson($tableName, $definition);
    }

    /**
     * Generate documentation from custom API definition
     *
     * Creates API documentation for custom endpoints.
     *
     * @param string $filename Custom API definition file path
     */
    public function generateFromDocJson(string $filename): void
    {
        $jsonContent = file_get_contents($filename);
        if (!$jsonContent) {
            error_log("DocGenerator: Could not read custom API definition file: $filename");
            return;
        }

        $definition = json_decode($jsonContent, true);
        if ($definition === null || $definition === false) {
            error_log("DocGenerator: Invalid JSON in custom API definition file: $filename");
            return;
        }

        if (!isset($definition['doc'])) {
            error_log("DocGenerator: Missing 'doc' key in custom API definition file: $filename");
            return;
        }

        // Process the documentation definition
        $this->addPathsFromDocJson($definition);
        $this->addSchemaFromDocJson($definition);
    }

    /**
     * Generate documentation from extension definitions
     *
     * Finds and processes OpenAPI definition files in extensions directories.
     *
     * @param string $extensionsPath Path to the extensions directory
     */
    public function generateFromExtensions(?string $extensionsPath = null): void
    {
        if ($extensionsPath === null) {
            $extensionsPath = config('documentation.paths.extension_definitions');
        }

        if (!is_dir($extensionsPath)) {
            error_log("Extensions documentation directory not found: $extensionsPath");
            return;
        }

        // Scan extension directories
        $extensionDirs = array_filter(glob($extensionsPath . '/*'), 'is_dir');

        foreach ($extensionDirs as $extDir) {
            $extName = basename($extDir);
            $extFiles = glob($extDir . '/*.json');

            foreach ($extFiles as $extFile) {
                $this->mergeExtensionDefinition($extFile, $extName);
            }
        }
    }

    /**
     * Merge extension OpenAPI definition into main documentation
     *
     * @param string $filePath Path to extension definition file
     * @param string $extName Extension name
     */
    private function mergeExtensionDefinition(string $filePath, string $extName): void
    {
        $this->mergeDefinition($filePath, $extName, 'extension');
    }

    /**
     * Generate documentation from main routes
     *
     * Finds and processes OpenAPI definition files for main routes.
     *
     * @param string $routesPath Path to the routes documentation directory
     */
    public function generateFromRoutes(?string $routesPath = null): void
    {
        if ($routesPath === null) {
            $routesPath = config('documentation.paths.route_definitions');
        }

        if (!is_dir($routesPath)) {
            error_log("Routes documentation directory not found: $routesPath");
            return;
        }

        // Process all route documentation files
        $routeFiles = glob($routesPath . '/*.json');

        foreach ($routeFiles as $routeFile) {
            $routeName = basename($routeFile, '.json');
            $this->mergeRouteDefinition($routeFile, $routeName);
        }
    }

    /**
     * Merge route OpenAPI definition into main documentation
     *
     * @param string $filePath Path to route definition file
     * @param string $routeName Route file name
     */
    private function mergeRouteDefinition(string $filePath, string $routeName): void
    {
        $this->mergeDefinition($filePath, "Route$routeName", 'route');
    }

    /**
     * Generate expanded resource routes documentation
     *
     * Takes routes with {resource} placeholder and expands them to
     * table-specific endpoints with full schema documentation.
     *
     * @param ResourceRouteExpander $expander Resource route expander instance
     */
    public function generateResourceRoutes(ResourceRouteExpander $expander): void
    {
        $this->resourceExpander = $expander;
        $tables = $expander->getTableSchemas();

        foreach ($tables as $tableName => $schema) {
            $this->addResourceEndpoints($tableName, $schema);
            $this->addResourceSchema($tableName, $schema);
        }
    }

    /**
     * Add resource CRUD endpoints for a table
     *
     * @param string $tableName Table name
     * @param array<string, mixed> $schema Table schema
     */
    private function addResourceEndpoints(string $tableName, array $schema): void
    {
        $basePath = "/{$tableName}";
        $isReadOnly = ($schema['x-access-mode'] ?? 'read-write') === 'read-only';
        $tag = "Table - {$tableName}";

        // GET /{table} - List resources
        $this->paths[$basePath]['get'] = [
            'tags' => [$tag],
            'summary' => "List {$tableName}",
            'description' => "Retrieves a paginated list of {$tableName} records",
            'operationId' => "list" . ucfirst($tableName),
            'security' => [['BearerAuth' => []]],
            'parameters' => [
                [
                    'name' => 'page',
                    'in' => 'query',
                    'description' => 'Page number for pagination',
                    'schema' => ['type' => 'integer', 'default' => 1]
                ],
                [
                    'name' => 'limit',
                    'in' => 'query',
                    'description' => 'Number of items per page (max 100)',
                    'schema' => ['type' => 'integer', 'default' => 20, 'maximum' => 100]
                ],
                [
                    'name' => 'sort',
                    'in' => 'query',
                    'description' => 'Field to sort by',
                    'schema' => ['type' => 'string']
                ],
                [
                    'name' => 'order',
                    'in' => 'query',
                    'description' => 'Sort order',
                    'schema' => ['type' => 'string', 'enum' => ['asc', 'desc'], 'default' => 'asc']
                ],
                [
                    'name' => 'fields',
                    'in' => 'query',
                    'description' => 'Comma-separated list of fields to return',
                    'schema' => ['type' => 'string']
                ],
                [
                    'name' => 'expand',
                    'in' => 'query',
                    'description' => 'Related resources to expand',
                    'schema' => ['type' => 'string']
                ]
            ],
            'responses' => [
                '200' => [
                    'description' => "{$tableName} retrieved successfully",
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'success' => ['type' => 'boolean', 'example' => true],
                                    'message' => ['type' => 'string'],
                                    'data' => [
                                        'type' => 'array',
                                        'items' => ['$ref' => "#/components/schemas/{$tableName}"]
                                    ],
                                    'pagination' => ['$ref' => '#/components/schemas/PaginationMeta']
                                ]
                            ]
                        ]
                    ]
                ],
                '403' => ['description' => 'Insufficient permissions'],
                '404' => ['description' => 'Resource not found']
            ]
        ];

        // GET /{table}/{uuid} - Get single resource
        $this->paths[$basePath . '/{uuid}']['get'] = [
            'tags' => [$tag],
            'summary' => "Get {$tableName} by UUID",
            'description' => "Retrieves a single {$tableName} record by its UUID",
            'operationId' => "get" . ucfirst($tableName),
            'security' => [['BearerAuth' => []]],
            'parameters' => [
                [
                    'name' => 'uuid',
                    'in' => 'path',
                    'required' => true,
                    'description' => 'Resource UUID',
                    'schema' => ['type' => 'string', 'format' => 'uuid']
                ],
                [
                    'name' => 'fields',
                    'in' => 'query',
                    'description' => 'Comma-separated list of fields to return',
                    'schema' => ['type' => 'string']
                ],
                [
                    'name' => 'expand',
                    'in' => 'query',
                    'description' => 'Related resources to expand',
                    'schema' => ['type' => 'string']
                ]
            ],
            'responses' => [
                '200' => [
                    'description' => "{$tableName} retrieved successfully",
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'success' => ['type' => 'boolean', 'example' => true],
                                    'message' => ['type' => 'string'],
                                    'data' => ['$ref' => "#/components/schemas/{$tableName}"]
                                ]
                            ]
                        ]
                    ]
                ],
                '403' => ['description' => 'Insufficient permissions'],
                '404' => ['description' => 'Resource not found']
            ]
        ];

        // Skip write operations for read-only tables (views)
        if ($isReadOnly) {
            return;
        }

        // POST /{table} - Create resource
        $this->paths[$basePath]['post'] = [
            'tags' => [$tag],
            'summary' => "Create {$tableName}",
            'description' => "Creates a new {$tableName} record",
            'operationId' => "create" . ucfirst($tableName),
            'security' => [['BearerAuth' => []]],
            'requestBody' => [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => "#/components/schemas/{$tableName}Input"]
                    ]
                ]
            ],
            'responses' => [
                '201' => [
                    'description' => "{$tableName} created successfully",
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'success' => ['type' => 'boolean', 'example' => true],
                                    'message' => ['type' => 'string'],
                                    'data' => ['$ref' => "#/components/schemas/{$tableName}"]
                                ]
                            ]
                        ]
                    ]
                ],
                '400' => ['description' => 'Invalid input data'],
                '403' => ['description' => 'Insufficient permissions']
            ]
        ];

        // PUT /{table}/{uuid} - Update resource
        $this->paths[$basePath . '/{uuid}']['put'] = [
            'tags' => [$tag],
            'summary' => "Update {$tableName}",
            'description' => "Updates an existing {$tableName} record",
            'operationId' => "update" . ucfirst($tableName),
            'security' => [['BearerAuth' => []]],
            'parameters' => [
                [
                    'name' => 'uuid',
                    'in' => 'path',
                    'required' => true,
                    'description' => 'Resource UUID to update',
                    'schema' => ['type' => 'string', 'format' => 'uuid']
                ]
            ],
            'requestBody' => [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => "#/components/schemas/{$tableName}Input"]
                    ]
                ]
            ],
            'responses' => [
                '200' => [
                    'description' => "{$tableName} updated successfully",
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'success' => ['type' => 'boolean', 'example' => true],
                                    'message' => ['type' => 'string'],
                                    'data' => ['$ref' => "#/components/schemas/{$tableName}"]
                                ]
                            ]
                        ]
                    ]
                ],
                '400' => ['description' => 'Invalid input data'],
                '403' => ['description' => 'Insufficient permissions'],
                '404' => ['description' => 'Resource not found']
            ]
        ];

        // DELETE /{table}/{uuid} - Delete resource
        $this->paths[$basePath . '/{uuid}']['delete'] = [
            'tags' => [$tag],
            'summary' => "Delete {$tableName}",
            'description' => "Deletes a {$tableName} record",
            'operationId' => "delete" . ucfirst($tableName),
            'security' => [['BearerAuth' => []]],
            'parameters' => [
                [
                    'name' => 'uuid',
                    'in' => 'path',
                    'required' => true,
                    'description' => 'Resource UUID to delete',
                    'schema' => ['type' => 'string', 'format' => 'uuid']
                ]
            ],
            'responses' => [
                '200' => [
                    'description' => "{$tableName} deleted successfully",
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'success' => ['type' => 'boolean', 'example' => true],
                                    'message' => ['type' => 'string']
                                ]
                            ]
                        ]
                    ]
                ],
                '403' => ['description' => 'Insufficient permissions'],
                '404' => ['description' => 'Resource not found']
            ]
        ];
    }

    /**
     * Add resource schema from table definition
     *
     * Creates both read (full) and input (writable) schemas.
     *
     * @param string $tableName Table name
     * @param array<string, mixed> $schema Table schema from expander
     */
    private function addResourceSchema(string $tableName, array $schema): void
    {
        // Full schema for read operations
        $this->schemas[$tableName] = [
            'type' => 'object',
            'description' => "Schema for {$tableName}",
            'properties' => $schema['properties'] ?? [],
        ];

        if (isset($schema['required']) && count($schema['required']) > 0) {
            $this->schemas[$tableName]['required'] = $schema['required'];
        }

        // Input schema for create/update (excludes auto-generated fields)
        $inputProperties = [];
        $inputRequired = [];
        $autoFields = ['id', 'uuid', 'created_at', 'updated_at', 'deleted_at'];

        foreach ($schema['properties'] ?? [] as $fieldName => $fieldSchema) {
            if (!in_array($fieldName, $autoFields, true)) {
                $inputProperties[$fieldName] = $fieldSchema;

                // Add to required if it was required in original schema
                if (in_array($fieldName, $schema['required'] ?? [], true)) {
                    $inputRequired[] = $fieldName;
                }
            }
        }

        $this->schemas[$tableName . 'Input'] = [
            'type' => 'object',
            'description' => "Input schema for creating/updating {$tableName}",
            'properties' => $inputProperties,
        ];

        if (count($inputRequired) > 0) {
            $this->schemas[$tableName . 'Input']['required'] = $inputRequired;
        }
    }

    /**
     * Merge OpenAPI definition into main documentation
     *
     * Shared method for merging extension and route definitions.
     *
     * @param string $filePath Path to definition file
     * @param string $schemaPrefix Prefix for schema names
     * @param string $type Definition type for error messages ('extension' or 'route')
     */
    private function mergeDefinition(string $filePath, string $schemaPrefix, string $type): void
    {
        $jsonContent = file_get_contents($filePath);
        if (!$jsonContent) {
            error_log("DocGenerator: Could not read $type definition file: $filePath");
            return;
        }

        $definition = json_decode($jsonContent, true);
        if ($definition === null || $definition === false) {
            error_log("DocGenerator: Invalid JSON in $type definition file: $filePath");
            return;
        }

        // Merge paths
        if (isset($definition['paths']) && is_array($definition['paths'])) {
            foreach ($definition['paths'] as $path => $methods) {
                $this->paths[$path] = $methods;
            }
        }

        // Merge schemas
        if (isset($definition['components']['schemas']) && is_array($definition['components']['schemas'])) {
            foreach ($definition['components']['schemas'] as $name => $schema) {
                $this->schemas[$schemaPrefix . $name] = $schema;
            }
        }

        // Merge tags
        if (isset($definition['tags']) && is_array($definition['tags'])) {
            foreach ($definition['tags'] as $tag) {
                $this->extensionTags[] = $tag;
            }
        }
    }

    /**
     * Get complete OpenAPI specification
     *
     * Returns the full OpenAPI/Swagger documentation as JSON string.
     *
     * @return string OpenAPI specification JSON
     */
    public function getSwaggerJson(): string
    {
        $swagger = [
            'openapi' => $this->openApiVersion,
            'info' => $this->buildInfoSection(),
            'servers' => config('documentation.servers', [
                [
                    'url' => rtrim(config('app.urls.api', ''), '/'),
                    'description' => 'API Server'
                ]
            ]),
            'components' => [
                'securitySchemes' => [
                    'BearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'JWT',
                        'description' => 'JWT Authorization header using the Bearer scheme'
                    ]
                ],
                'schemas' => $this->transformSchemas(
                    array_merge($this->getDefaultSchemas(), $this->schemas)
                )
            ],
            'paths' => $this->paths,
            'tags' => $this->generateTags()
        ];

        // Add JSON Schema dialect for OpenAPI 3.1
        if ($this->isOpenApi31()) {
            $swagger['jsonSchemaDialect'] = 'https://json-schema.org/draft/2020-12/schema';
        }

        return json_encode($swagger, JSON_PRETTY_PRINT);
    }

    /**
     * Transform schemas based on OpenAPI version
     *
     * Converts nullable syntax between 3.0.x and 3.1.0 formats.
     *
     * @param array<string, mixed> $schemas Schemas to transform
     * @return array<string, mixed> Transformed schemas
     */
    private function transformSchemas(array $schemas): array
    {
        if (!$this->isOpenApi31()) {
            return $schemas;
        }

        return array_map([$this, 'transformSchema'], $schemas);
    }

    /**
     * Transform a single schema for OpenAPI 3.1 compatibility
     *
     * @param array<string, mixed> $schema Schema to transform
     * @return array<string, mixed> Transformed schema
     */
    private function transformSchema(array $schema): array
    {
        // Handle nullable property - convert to type array for 3.1
        if (isset($schema['nullable']) && $schema['nullable'] === true && isset($schema['type'])) {
            $type = $schema['type'];
            if (is_string($type)) {
                $schema['type'] = [$type, 'null'];
            }
            unset($schema['nullable']);
        }

        // Recursively transform nested properties
        if (isset($schema['properties']) && is_array($schema['properties'])) {
            $schema['properties'] = array_map([$this, 'transformSchema'], $schema['properties']);
        }

        // Transform items for array types
        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = $this->transformSchema($schema['items']);
        }

        // Transform allOf, anyOf, oneOf
        foreach (['allOf', 'anyOf', 'oneOf'] as $keyword) {
            if (isset($schema[$keyword]) && is_array($schema[$keyword])) {
                $schema[$keyword] = array_map([$this, 'transformSchema'], $schema[$keyword]);
            }
        }

        // Transform additionalProperties if it's a schema
        if (isset($schema['additionalProperties']) && is_array($schema['additionalProperties'])) {
            $schema['additionalProperties'] = $this->transformSchema($schema['additionalProperties']);
        }

        return $schema;
    }

    /**
     * Build the info section of the OpenAPI specification
     *
     * @return array<string, mixed> Info section data
     */
    private function buildInfoSection(): array
    {
        $info = [
            'title' => config('documentation.info.title', config('app.name', 'API Documentation')),
            'version' => config('documentation.info.version', config('app.version_full', '1.0.0')),
            'description' => config('documentation.info.description', 'Auto-generated API documentation'),
        ];

        // Add contact info if provided
        $contact = config('documentation.info.contact', []);
        $contactName = $contact['name'] ?? '';
        $contactEmail = $contact['email'] ?? '';
        $contactUrl = $contact['url'] ?? '';
        if ($contactName !== '' || $contactEmail !== '' || $contactUrl !== '') {
            $info['contact'] = array_filter($contact, fn($v) => $v !== '');
        }

        // Add license info if provided
        $license = config('documentation.info.license', []);
        $licenseName = $license['name'] ?? '';
        if ($licenseName !== '') {
            $licenseInfo = array_filter($license, fn($v) => $v !== '');

            // OpenAPI 3.1 supports SPDX identifier for licenses
            if ($this->isOpenApi31()) {
                $identifier = $license['identifier'] ?? '';
                if ($identifier !== '') {
                    $licenseInfo['identifier'] = $identifier;
                }
            }

            $info['license'] = $licenseInfo;
        }

        return $info;
    }

    /**
     * Add paths from table definition
     *
     * Generates endpoint documentation for standard CRUD operations.
     *
     * @param string $resource API resource name
     * @param string $tableName Database table name
     * @param array $definition Table definition data
     */
    /**
     * @param string $resource
     * @param string $tableName
     * @param array<string, mixed> $definition
     */
    private function addPathsFromJson(string $resource, string $tableName, array $definition): void
    {
        $access = $definition['access']['mode'] ?? 'r';
        $basePath = "/{$resource}";
        $this->paths[$basePath] = [];

        // For views (starting with vw_), only add GET method
        if (str_starts_with($tableName, 'vw_')) {
            $this->paths[$basePath]['get'] = [
                // 'tags' => [$resource],
                'tags' => ["Table - {$resource}"],
                'summary' => "List {$tableName}",
                'description' => "View-only endpoint for {$tableName}",
                'parameters' => [
                    ...$this->getCommonParameters(),
                    ...$this->getFilterParameters()
                ],
                'responses' => $this->getCommonResponses($tableName)
            ];
            return;
        }

        // Rest of the CRUD operations remain the same
        if (str_contains($access, 'r')) {
            $this->paths[$basePath]['get'] = [
                // 'tags' => [$resource],
                'tags' => ["Table - {$resource}"],
                'summary' => "List {$tableName}",
                'description' => "Retrieve a list of {$tableName} records",
                'security' => [['BearerAuth' => []]],
                'parameters' => [
                    ...$this->getCommonParameters(),
                    ...$this->getFilterParameters()
                ],
                'responses' => $this->getCommonResponses($tableName)
            ];
        }

        if (str_contains($access, 'w')) {
            // POST uses schema without ID
            $this->paths[$basePath]['post'] = [
                // 'tags' => [$resource],
                'tags' => ["Table - {$resource}"],
                'summary' => "Create new {$tableName}",
                'description' => "Create a new {$tableName} record",
                'security' => [['BearerAuth' => []]],
                'requestBody' => [
                    'required' => true,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => "#/components/schemas/{$tableName}"]
                        ]
                    ]
                ],
                'responses' => [
                    '201' => [
                        'description' => 'Record created successfully',
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => "#/components/schemas/{$tableName}Update"]
                            ]
                        ]
                    ],
                    ...$this->getErrorResponses()
                ]
            ];

            // PUT uses schema with ID
            $this->paths[$basePath . '/{id}']['put'] = [
                // 'tags' => [$resource],
                'tags' => ["Table - {$resource}"],
                'summary' => "Update {$tableName}",
                'description' => "Update an existing {$tableName} record",
                'security' => [['BearerAuth' => []]],
                'parameters' => [
                    [
                        'name' => 'id',
                        'in' => 'path',
                        'required' => true,
                        'schema' => ['type' => 'integer']
                    ],
                    ...$this->getCommonParameters()
                ],
                'requestBody' => [
                    'required' => true,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => "#/components/schemas/{$tableName}Update"]
                        ]
                    ]
                ],
                'responses' => [
                    '200' => [
                        'description' => 'Record updated successfully',
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => "#/components/schemas/{$tableName}"]
                            ]
                        ]
                    ],
                    ...$this->getErrorResponses()
                ]
            ];

            $this->paths[$basePath . '/{id}']['delete'] = [
                // 'tags' => [$resource],
                'tags' => ["Table - {$resource}"],
                'summary' => "Delete {$tableName}",
                'description' => "Delete a {$tableName} record",
                'security' => [['BearerAuth' => []]],
                'parameters' => [
                    [
                        'name' => 'id',
                        'in' => 'path',
                        'required' => true,
                        'schema' => ['type' => 'integer']
                    ],
                    ...$this->getCommonParameters()
                ],
                'responses' => [
                    '200' => [
                        'description' => 'Record deleted successfully'
                    ],
                    ...$this->getErrorResponses()
                ]
            ];
        }
    }

    /**
     * Add paths from custom API definition
     *
     * Generates endpoint documentation for custom API endpoints.
     *
     * @param array<string, mixed> $definition Custom API definition
     */
    private function addPathsFromDocJson(array $definition): void
    {
        $docName = $definition['doc']['name'];
        $method = strtolower($definition['doc']['method']);
        $isPublic = $definition['doc']['is_public'] ?? false;
        $consumes = $definition['doc']['consumes'] ?? ['application/json'];

        $basePath = "/{$docName}";
        $this->paths[$basePath] = [];

        // Build request schema
        $properties = [];
        $required = [];

        foreach ($definition['doc']['fields'] as $field) {
            $fieldName = $field['name'];
            $apiField = $field['api_field'] ?? $fieldName;

            $properties[$apiField] = [
                'type' => $this->inferTypeFromJson($field['type']),
                'description' => $field['description'] ?? $fieldName
            ];

            $nullable = $field['nullable'] ?? true;
            if ($nullable !== true) {
                $required[] = $apiField;
            }
        }

        // Create schema for this endpoint
        $schemaName = str_replace(['/'], '', ucwords($docName, '/'));
        $this->schemas[$schemaName] = [
            'type' => 'object',
            'properties' => $properties
        ];

        if (count($required) > 0) {
            $this->schemas[$schemaName]['required'] = $required;
        }

        // Build content object based on consumes array
        $content = [];
        foreach ($consumes as $mediaType) {
            if ($mediaType === 'multipart/form-data') {
                $content[$mediaType] = [
                    'schema' => [
                        'type' => 'object',
                        'properties' => $properties
                    ]
                ];
            } else {
                $content[$mediaType] = [
                    'schema' => ['$ref' => "#/components/schemas/{$schemaName}"]
                ];
            }
        }

        // Add custom response schema if provided
        if (isset($definition['doc']['response'])) {
            $responseSchemaName = $schemaName . 'Response';
            $this->schemas[$responseSchemaName] = $definition['doc']['response'];
            $responses = [
                '200' => [
                    'description' => 'Successful operation',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => "#/components/schemas/{$responseSchemaName}"]
                        ]
                    ]
                ],
                ...$this->getErrorResponses()
            ];
        } else {
            $responses = $this->getCommonResponses($schemaName);
        }

        // Add path operation
        $this->paths[$basePath][$method] = [
            'tags' => [explode('/', $docName)[0]],
            'summary' => ucwords(str_replace('-', ' ', basename($docName))),
            'description' => "Endpoint for " . str_replace('-', ' ', $docName),
            'security' => $isPublic === true ? [] : [['BearerAuth' => []]],
            'requestBody' => [
                'required' => true,
                'content' => $content
            ],
            'responses' => $responses
        ];
    }

    /**
     * Process table fields
     *
     * Validates and processes field definitions from table configuration.
     *
     * @param array<string, mixed> $definition Table definition
     * @return array<string, mixed> Processed fields
     */
    private function processFields(array $definition): array
    {
        // Log the full definition for debugging
        // error_log("Processing definition: " . json_encode($definition, JSON_PRETTY_PRINT));

        if (!isset($definition['table']) || !isset($definition['fields'])) {
            $tableName = $definition['name'] ?? 'unknown';
            // error_log("Table structure missing for: $tableName");
            return [];
        }

        // Verify fields is an array
        if (!is_array($definition['fields'])) {
            $tableName = $definition['name'] ?? 'unknown';
            error_log("Fields is not an array for table: $tableName");
            return [];
        }

        return $definition['fields'];
    }

    /**
     * Add schema from table definition
     *
     * Creates OpenAPI schemas for table data structures.
     *
     * @param string $tableName Database table name
     * @param array<string, mixed> $definition Table definition data
     */
    private function addSchemaFromJson(string $tableName, array $definition): void
    {
        $properties = [];
        $required = [];

        $fields = $this->processFields($definition['table'] ?? []);
        if (count($fields) === 0) {
            // Handle missing or empty fields gracefully
            return;
        }

        foreach ($fields as $field) {
            $fieldName = $field['name'];
            $apiField = $field['api_field'] ?? $fieldName;

            // Skip ID and UUID fields for POST schema
            if (strtolower($fieldName) === 'id' || strtolower($fieldName) === 'uuid') {
                continue;
            }
            $fieldType = $field['type'] ?? '';
            $properties[$apiField] = [
                'type' => $this->inferTypeFromJson($fieldType),
                'description' => $field['description'] ?? $fieldName
            ];

            $nullable = $field['nullable'] ?? true;
            if ($nullable !== true) {
                $required[] = $apiField;
            }
        }

        $this->schemas[$tableName] = [
            'type' => 'object',
            'properties' => $properties
        ];

        if (count($required) > 0) {
            $this->schemas[$tableName]['required'] = $required;
        }

        // Create separate schema for PUT/PATCH that includes ID
        $this->schemas[$tableName . 'Update'] = [
            'type' => 'object',
            'properties' => array_merge(
                [
                    'id' => ['type' => 'string', 'format' => 'uuid'],
                    'uuid' => ['type' => 'string', 'format' => 'uuid']
                ],
                $properties
            )
        ];
    }

    /**
     * Add schema from custom API definition
     *
     * Creates OpenAPI schemas for custom API endpoints.
     *
     * @param array<string, mixed> $definition Custom API definition
     * @return string Generated schema name
     */
    private function addSchemaFromDocJson(array $definition): string
    {
        $docName = $definition['doc']['name'];
        $schemaName = str_replace(['/'], '', ucwords($docName, '/'));

        $properties = [];
        $required = [];

        foreach ($definition['doc']['fields'] as $field) {
            $fieldName = $field['name'];
            $apiField = $field['api_field'] ?? $fieldName;

            $properties[$apiField] = [
                'type' => $this->inferTypeFromJson($field['type']),
                'description' => $field['description'] ?? $fieldName
            ];

            // Special handling for file type
            if ($field['type'] === 'file') {
                $properties[$apiField]['format'] = 'binary';
            }
            // Special handling for base64
            if ($field['type'] === 'longtext' && str_contains($field['description'], 'base64')) {
                $properties[$apiField]['format'] = 'base64';
            }

            $nullable = $field['nullable'] ?? true;
            if ($nullable !== true) {
                $required[] = $apiField;
            }
        }

        // Create request schema
        $this->schemas[$schemaName] = [
            'type' => 'object',
            'properties' => $properties
        ];

        if (count($required) > 0) {
            $this->schemas[$schemaName]['required'] = $required;
        }

        // Create response schema
        if (isset($definition['doc']['response'])) {
            $this->schemas[$schemaName . 'Response'] = $definition['doc']['response'];
        }

        // Create multipart schema if needed
        $consumes = $definition['doc']['consumes'] ?? [];
        if (in_array('multipart/form-data', $consumes, true)) {
            $this->schemas[$schemaName . 'Multipart'] = [
                'type' => 'object',
                'properties' => array_map(function ($prop) {
                    // Convert file properties for multipart
                    if (isset($prop['format']) && $prop['format'] === 'binary') {
                        return [
                            'type' => 'string',
                            'format' => 'binary',
                            'description' => $prop['description']
                        ];
                    }
                    return $prop;
                }, $properties)
            ];
        }

        return $schemaName;
    }

    /**
     * Infer OpenAPI type from database type
     *
     * Maps database column types to OpenAPI data types.
     *
     * @param string $dbType Database column type
     * @return string OpenAPI data type
     */
    private function inferTypeFromJson(string $dbType): string
    {
        // Handle null or empty type
        if ($dbType === '') {
            return 'string'; // Default to string type
        }
        if (str_contains($dbType, 'int')) {
            return 'integer';
        }
        if (str_contains($dbType, 'decimal') || str_contains($dbType, 'float') || str_contains($dbType, 'double')) {
            return 'number';
        }
        if (str_contains($dbType, 'datetime') || str_contains($dbType, 'timestamp')) {
            return 'string';
        }
        if (str_contains($dbType, 'bool')) {
            return 'boolean';
        }
        return 'string';
    }

    /**
     * Get common API parameters
     *
     * Returns standard parameters used across endpoints.
     *
     * @return array<int, array<string, mixed>> Common parameters definition
     */
    private function getCommonParameters(): array
    {
        return [
            [
                'name' => 'fields',
                'in' => 'query',
                'description' => 'Comma-separated list of fields to return',
                'schema' => ['type' => 'string']
            ]
        ];
    }

    /**
     * Get filter parameters
     *
     * Returns standard filtering and pagination parameters.
     *
     * @return array<int, array<string, mixed>> Filter parameters definition
     */
    private function getFilterParameters(): array
    {
        return [
            [
                'name' => 'filter',
                'in' => 'query',
                'description' => 'Filter criteria',
                'schema' => ['type' => 'string']
            ],
            [
                'name' => 'orderby',
                'in' => 'query',
                'description' => 'Sort order (field:direction)',
                'schema' => ['type' => 'string']
            ],
            [
                'name' => 'limit',
                'in' => 'query',
                'schema' => ['type' => 'integer', 'default' => 20]
            ],
            [
                'name' => 'offset',
                'in' => 'query',
                'schema' => ['type' => 'integer', 'default' => 0]
            ]
        ];
    }

    /**
     * Get common response definitions
     *
     * Returns standard API response structures.
     *
     * @param string $tableName Related table name
     * @return array<int|string, mixed> Response definitions
     */
    private function getCommonResponses(string $tableName): array
    {
        return [
            '200' => [
                'description' => 'Successful operation',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'success' => [
                                    'type' => 'boolean',
                                    'default' => true,
                                    'example' => true
                                ],
                                'message' => [
                                    'type' => 'string'
                                ],
                                'data' => [
                                    'type' => 'array',
                                    'items' => ['$ref' => "#/components/schemas/{$tableName}"]
                                ]
                                // 'meta' => [
                                //     '$ref' => '#/components/schemas/PaginationMeta'
                                // ]
                            ],
                            'required' => ['success', 'message', 'data']
                        ]
                    ]
                ]
            ],
            '401' => [
                'description' => 'Unauthorized',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            '$ref' => '#/components/schemas/Error'
                        ]
                    ]
                ]
            ]
        ];
    }

    /**
     * Get error response definitions
     *
     * Returns standard error response structures.
     *
     * @return array<int|string, mixed> Error response definitions
     */
    private function getErrorResponses(): array
    {
        return [
            '400' => [
                'description' => 'Bad request',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            '$ref' => '#/components/schemas/Error'
                        ]
                    ]
                ]
            ],
            '401' => [
                'description' => 'Unauthorized',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            '$ref' => '#/components/schemas/Error'
                        ]
                    ]
                ]
            ],
            '404' => [
                'description' => 'Record not found',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            '$ref' => '#/components/schemas/Error'
                        ]
                    ]
                ]
            ]
        ];
    }

    /**
     * Get default OpenAPI schemas
     *
     * Returns base schemas used across the API.
     *
     * @return array<string, mixed> Default schemas
     */
    private function getDefaultSchemas(): array
    {
        return [
            // Common Response Schemas
            'SuccessResponse' => [
                'type' => 'object',
                'properties' => [
                    'success' => [
                        'type' => 'boolean',
                        'example' => true
                    ],
                    'message' => [
                        'type' => 'string',
                        'example' => 'Operation completed successfully'
                    ],
                    'data' => [
                        'type' => 'object',
                        'additionalProperties' => true
                    ]
                ],
                'required' => ['success', 'message']
            ],

            'Error' => [
                'type' => 'object',
                'properties' => [
                   'success' => [
                        'type' => 'boolean',
                        'default' => false,
                        'example' => false
                    ],
                    'message' => [
                        'type' => 'string'
                    ],
                    'data' => [
                        'type' => 'object',
                        'additionalProperties' => true
                    ]
                ],
                'required' => ['success', 'message', 'data']
            ],

            'ErrorResponse' => [
                'type' => 'object',
                'properties' => [
                    'success' => [
                        'type' => 'boolean',
                        'example' => false
                    ],
                    'message' => [
                        'type' => 'string',
                        'example' => 'An error occurred'
                    ],
                    'errors' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'string'
                        ]
                    ]
                ],
                'required' => ['success', 'message']
            ],

            'ValidationErrorResponse' => [
                'type' => 'object',
                'properties' => [
                    'success' => [
                        'type' => 'boolean',
                        'example' => false
                    ],
                    'message' => [
                        'type' => 'string',
                        'example' => 'Validation failed'
                    ],
                    'errors' => [
                        'type' => 'object',
                        'additionalProperties' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'string'
                            ]
                        ]
                    ]
                ],
                'required' => ['success', 'message', 'errors']
            ],

            'PaginationMeta' => [
                'type' => 'object',
                'properties' => [
                    'current_page' => [
                        'type' => 'integer',
                        'description' => 'Current page number'
                    ],
                    'per_page' => [
                        'type' => 'integer',
                        'description' => 'Number of items per page'
                    ],
                    'total' => [
                        'type' => 'integer',
                        'description' => 'Total number of items'
                    ],
                    'last_page' => [
                        'type' => 'integer',
                        'description' => 'Last page number'
                    ],
                    'has_more' => [
                        'type' => 'boolean',
                        'description' => 'Whether more pages exist'
                    ],
                    'from' => [
                        'type' => 'integer',
                        'description' => 'Starting item number on current page'
                    ],
                    'to' => [
                        'type' => 'integer',
                        'description' => 'Ending item number on current page'
                    ]
                ]
            ],

            // Authentication Schemas
            'LoginRequest' => [
                'type' => 'object',
                'required' => ['username', 'password'],
                'properties' => [
                    'username' => [
                        'type' => 'string',
                        'description' => 'Username or email'
                    ],
                    'password' => [
                        'type' => 'string',
                        'format' => 'password',
                        'description' => 'User password'
                    ],
                    'remember_me' => [
                        'type' => 'boolean',
                        'description' => 'Keep user logged in',
                        'default' => false
                    ]
                ]
            ],

            'LoginResponse' => [
                'type' => 'object',
                'properties' => [
                    'success' => [
                        'type' => 'boolean'
                    ],
                    'message' => [
                        'type' => 'string'
                    ],
                    'data' => [
                        'type' => 'object',
                        'properties' => [
                            'access_token' => [
                                'type' => 'string',
                                'description' => 'JWT access token'
                            ],
                            'refresh_token' => [
                                'type' => 'string',
                                'description' => 'JWT refresh token'
                            ],
                            'token_type' => [
                                'type' => 'string',
                                'example' => 'Bearer'
                            ],
                            'expires_in' => [
                                'type' => 'integer',
                                'description' => 'Token expiration time in seconds'
                            ],
                            'user' => [
                                'type' => 'object',
                                'properties' => [
                                    'id' => [
                                        'type' => 'string',
                                        'description' => 'User unique identifier'
                                    ],
                                    'email' => [
                                        'type' => 'string',
                                        'format' => 'email',
                                        'description' => 'Email address'
                                    ],
                                    'email_verified' => [
                                        'type' => 'boolean',
                                        'description' => 'Email verification status'
                                    ],
                                    'username' => [
                                        'type' => 'string',
                                        'description' => 'Username'
                                    ],
                                    'name' => [
                                        'type' => 'string',
                                        'description' => 'Full name'
                                    ],
                                    'given_name' => [
                                        'type' => 'string',
                                        'description' => 'First name'
                                    ],
                                    'family_name' => [
                                        'type' => 'string',
                                        'description' => 'Last name'
                                    ],
                                    'picture' => [
                                        'type' => 'string',
                                        'description' => 'Profile image URL'
                                    ],
                                    'locale' => [
                                        'type' => 'string',
                                        'description' => 'User locale (e.g., en-US)'
                                    ],
                                    'updated_at' => [
                                        'type' => 'integer',
                                        'description' => 'Last update timestamp (Unix epoch)'
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ],

            'RefreshTokenRequest' => [
                'type' => 'object',
                'required' => ['refresh_token'],
                'properties' => [
                    'refresh_token' => [
                        'type' => 'string',
                        'description' => 'The refresh token to exchange for new tokens'
                    ]
                ]
            ],

            // User Management Schemas
            'User' => [
                'type' => 'object',
                'properties' => [
                    'uuid' => [
                        'type' => 'string',
                        'format' => 'uuid',
                        'description' => 'Unique user identifier'
                    ],
                    'username' => [
                        'type' => 'string',
                        'description' => 'User username'
                    ],
                    'email' => [
                        'type' => 'string',
                        'format' => 'email',
                        'description' => 'User email address'
                    ],
                    'status' => [
                        'type' => 'string',
                        'enum' => ['active', 'inactive', 'suspended'],
                        'description' => 'User account status'
                    ],
                    'created_at' => [
                        'type' => 'string',
                        'format' => 'date-time',
                        'description' => 'Account creation timestamp'
                    ],
                    'updated_at' => [
                        'type' => 'string',
                        'format' => 'date-time',
                        'description' => 'Last update timestamp'
                    ]
                ]
            ],

            'CreateUserRequest' => [
                'type' => 'object',
                'required' => ['username', 'email', 'password'],
                'properties' => [
                    'username' => [
                        'type' => 'string',
                        'description' => 'Unique username'
                    ],
                    'email' => [
                        'type' => 'string',
                        'format' => 'email',
                        'description' => 'User email address'
                    ],
                    'password' => [
                        'type' => 'string',
                        'format' => 'password',
                        'description' => 'User password'
                    ]
                ]
            ],

            'UpdateUserRequest' => [
                'type' => 'object',
                'properties' => [
                    'email' => [
                        'type' => 'string',
                        'format' => 'email',
                        'description' => 'New email address'
                    ],
                    'status' => [
                        'type' => 'string',
                        'enum' => ['active', 'inactive', 'suspended'],
                        'description' => 'New account status'
                    ]
                ]
            ],

            // Health Check Schemas
            'HealthCheckResponse' => [
                'type' => 'object',
                'properties' => [
                    'status' => [
                        'type' => 'string',
                        'enum' => ['healthy', 'unhealthy'],
                        'description' => 'Overall system health status'
                    ],
                    'timestamp' => [
                        'type' => 'string',
                        'format' => 'date-time',
                        'description' => 'Health check timestamp'
                    ],
                    'services' => [
                        'type' => 'object',
                        'properties' => [
                            'database' => [
                                '$ref' => '#/components/schemas/ServiceHealth'
                            ],
                            'cache' => [
                                '$ref' => '#/components/schemas/ServiceHealth'
                            ],
                            'queue' => [
                                '$ref' => '#/components/schemas/ServiceHealth'
                            ]
                        ]
                    ]
                ]
            ],

            'ServiceHealth' => [
                'type' => 'object',
                'properties' => [
                    'status' => [
                        'type' => 'string',
                        'enum' => ['up', 'down'],
                        'description' => 'Service availability status'
                    ],
                    'latency' => [
                        'type' => 'number',
                        'description' => 'Response time in milliseconds'
                    ],
                    'message' => [
                        'type' => 'string',
                        'description' => 'Additional status information'
                    ]
                ]
            ],

            // Extension Schemas
            'Extension' => [
                'type' => 'object',
                'properties' => [
                    'name' => [
                        'type' => 'string',
                        'description' => 'Extension name'
                    ],
                    'version' => [
                        'type' => 'string',
                        'description' => 'Extension version'
                    ],
                    'status' => [
                        'type' => 'string',
                        'enum' => ['enabled', 'disabled'],
                        'description' => 'Extension status'
                    ],
                    'type' => [
                        'type' => 'string',
                        'enum' => ['core', 'optional'],
                        'description' => 'Extension type'
                    ],
                    'description' => [
                        'type' => 'string',
                        'description' => 'Extension description'
                    ],
                    'dependencies' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'string'
                        ],
                        'description' => 'Required dependencies'
                    ]
                ]
            ],

            'ExtensionListResponse' => [
                'type' => 'object',
                'properties' => [
                    'success' => [
                        'type' => 'boolean'
                    ],
                    'data' => [
                        'type' => 'array',
                        'items' => [
                            '$ref' => '#/components/schemas/Extension'
                        ]
                    ]
                ]
            ],

            // Notification Schemas
            'Notification' => [
                'type' => 'object',
                'properties' => [
                    'id' => [
                        'type' => 'integer',
                        'description' => 'Notification ID'
                    ],
                    'type' => [
                        'type' => 'string',
                        'description' => 'Notification type'
                    ],
                    'notifiable_type' => [
                        'type' => 'string',
                        'description' => 'Type of entity being notified'
                    ],
                    'notifiable_id' => [
                        'type' => 'string',
                        'description' => 'ID of entity being notified'
                    ],
                    'data' => [
                        'type' => 'object',
                        'description' => 'Notification payload'
                    ],
                    'read_at' => array_merge(
                        $this->createTypeWithFormat('string', 'date-time', true),
                        ['description' => 'When notification was read']
                    ),
                    'created_at' => [
                        'type' => 'string',
                        'format' => 'date-time',
                        'description' => 'When notification was created'
                    ]
                ]
            ],

            'NotificationListResponse' => [
                'type' => 'object',
                'properties' => [
                    'success' => [
                        'type' => 'boolean'
                    ],
                    'data' => [
                        'type' => 'array',
                        'items' => [
                            '$ref' => '#/components/schemas/Notification'
                        ]
                    ],
                    'meta' => [
                        '$ref' => '#/components/schemas/PaginationMeta'
                    ]
                ]
            ],

            // File Upload Schemas
            'FileUploadRequest' => [
                'type' => 'object',
                'required' => ['file'],
                'properties' => [
                    'file' => [
                        'type' => 'string',
                        'format' => 'binary',
                        'description' => 'The file to upload'
                    ]
                ]
            ],

            'FileUploadResponse' => [
                'type' => 'object',
                'properties' => [
                    'success' => [
                        'type' => 'boolean'
                    ],
                    'data' => [
                        'type' => 'object',
                        'properties' => [
                            'filename' => [
                                'type' => 'string',
                                'description' => 'Uploaded file name'
                            ],
                            'size' => [
                                'type' => 'integer',
                                'description' => 'File size in bytes'
                            ],
                            'mime_type' => [
                                'type' => 'string',
                                'description' => 'File MIME type'
                            ],
                            'url' => [
                                'type' => 'string',
                                'description' => 'File access URL'
                            ]
                        ]
                    ]
                ]
            ]
        ];
    }

    /**
     * Generate API tags
     *
     * Creates OpenAPI tags for grouping endpoints.
     *
     * @return array<int, array{name: string, description: string}> Generated tags
     */
    private function generateTags(): array
    {
        $tags = [];
        foreach ($this->paths as $methods) {
            foreach ($methods as $operation) {
                if (isset($operation['tags'])) {
                    foreach ($operation['tags'] as $tag) {
                        if (!isset($tags[$tag])) {
                            $tags[$tag] = [
                                'name' => $tag,
                                'description' => "Operations related to {$tag}"
                            ];
                        }
                    }
                }
            }
        }

        // Merge extension tags with auto-generated tags
        $allTags = array_merge($tags, $this->extensionTags);

        // Sort tags alphabetically by name
        usort($allTags, fn($a, $b) => strcasecmp($a['name'], $b['name']));

        return $allTags;
    }
}

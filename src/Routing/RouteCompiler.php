<?php

declare(strict_types=1);

namespace Glueful\Routing;

class RouteCompiler
{
    /**
     * Compile routes to cacheable PHP with normalized handlers
     *
     * This method ensures consistent serialization of all handler types
     * and prevents cache corruption from closures or other non-serializable handlers.
     */
    public function compile(Router $router): string
    {
        $static = $router->getStaticRoutes();
        $dynamic = $router->getDynamicRoutes();
        $named = $router->getNamedRoutes();

        $code = "<?php\n\n";
        $code .= "// Auto-generated route cache - DO NOT EDIT\n";
        $code .= "// Generated: " . date('Y-m-d H:i:s') . "\n\n";
        $code .= "return [\n";

        // Export static routes with normalized handlers
        $code .= "    'static' => [\n";
        foreach ($static as $key => $route) {
            $handlerMeta = $this->normalizeHandler($route->getHandler());
            $middleware = var_export($route->getMiddleware(), true);
            $code .= "        '{$key}' => [\n";
            $code .= "            'handler' => " . var_export($handlerMeta, true) . ",\n";
            $code .= "            'middleware' => {$middleware}\n";
            $code .= "        ],\n";
        }
        $code .= "    ],\n";

        // Export dynamic routes with normalized handlers
        $code .= "    'dynamic' => [\n";
        foreach ($dynamic as $method => $routes) {
            $code .= "        '{$method}' => [\n";
            foreach ($routes as $route) {
                $pattern = $route->getPattern();
                $handlerMeta = $this->normalizeHandler($route->getHandler());
                $params = var_export($route->getParamNames(), true);
                $middleware = var_export($route->getMiddleware(), true);
                $code .= "            [\n";
                $code .= "                'pattern' => '{$pattern}',\n";
                $code .= "                'handler' => " . var_export($handlerMeta, true) . ",\n";
                $code .= "                'params' => {$params},\n";
                $code .= "                'middleware' => {$middleware}\n";
                $code .= "            ],\n";
            }
            $code .= "        ],\n";
        }
        $code .= "    ]\n";
        $code .= "];\n";

        return $code;
    }

    /**
     * Normalize handler into serializable metadata
     *
     * Converts all handler types into a consistent, serializable format
     * that can be safely cached and reconstructed.
     *
     * @return array{type: string, target: mixed, metadata: array<string, mixed>}
     */
    private function normalizeHandler(mixed $handler): array
    {
        return [
            'type' => $this->getHandlerType($handler),
            'target' => $this->serializeHandler($handler),
            'metadata' => $this->getHandlerMetadata($handler)
        ];
    }

    /**
     * Determine handler type for consistent categorization
     */
    private function getHandlerType(mixed $handler): string
    {
        return match (true) {
            is_array($handler) => 'array_callable',
            $handler instanceof \Closure => 'closure',
            is_string($handler) && str_contains($handler, '::') => 'static_method',
            is_string($handler) && class_exists($handler) => 'invokable_class',
            is_callable($handler) => 'callable',
            default => 'unknown'
        };
    }

    /**
     * Serialize handler into reconstructable format
     */
    private function serializeHandler(mixed $handler): mixed
    {
        return match (true) {
            is_array($handler) => [
                'class' => is_object($handler[0]) ? get_class($handler[0]) : $handler[0],
                'method' => $handler[1]
            ],
            $handler instanceof \Closure => [
                'warning' => 'Closure handlers cannot be cached reliably',
                'reflection' => $this->getClosureInfo($handler)
            ],
            is_string($handler) => $handler,
            default => [
                'type' => gettype($handler),
                'class' => is_object($handler) ? get_class($handler) : null
            ]
        };
    }

    /**
     * Extract metadata for handler debugging and validation
     *
     * @return array<string, mixed>
     */
    private function getHandlerMetadata(mixed $handler): array
    {
        $metadata = [
            'created_at' => date('c'),
            'php_version' => PHP_VERSION
        ];

        try {
            if (is_array($handler)) {
                $reflection = new \ReflectionMethod($handler[0], $handler[1]);
                $metadata['parameters'] = array_map(
                    fn($param) => [
                        'name' => $param->getName(),
                        'type' => $param->getType() instanceof \ReflectionNamedType
                            ? $param->getType()->getName()
                            : null,
                        'optional' => $param->isOptional()
                    ],
                    $reflection->getParameters()
                );
                $metadata['filename'] = $reflection->getFileName();
                $metadata['line'] = $reflection->getStartLine();
            } elseif ($handler instanceof \Closure) {
                $reflection = new \ReflectionFunction($handler);
                $metadata['filename'] = $reflection->getFileName();
                $metadata['line'] = $reflection->getStartLine();
            }
        } catch (\ReflectionException $e) {
            $metadata['reflection_error'] = $e->getMessage();
        }

        return $metadata;
    }

    /**
     * Get closure information for debugging (closures can't be cached reliably)
     *
     * @return array<string, mixed>
     */
    private function getClosureInfo(\Closure $closure): array
    {
        $reflection = new \ReflectionFunction($closure);

        return [
            'filename' => $reflection->getFileName() !== false ? $reflection->getFileName() : 'unknown',
            'line_start' => $reflection->getStartLine() !== false ? $reflection->getStartLine() : 0,
            'line_end' => $reflection->getEndLine() !== false ? $reflection->getEndLine() : 0,
            'parameters' => array_map(
                fn($param) => $param->getName(),
                $reflection->getParameters()
            )
        ];
    }

    /**
     * Validate that handlers can be properly serialized
     *
     * Call this before caching to catch problematic handlers early
     *
     * @return array<string>
     */
    public function validateHandlers(Router $router): array
    {
        $issues = [];

        foreach ($router->getStaticRoutes() as $key => $route) {
            $issue = $this->validateHandler($route->getHandler(), "static route: {$key}");
            if ($issue !== null) {
                $issues[] = $issue;
            }
        }

        foreach ($router->getDynamicRoutes() as $method => $routes) {
            foreach ($routes as $route) {
                $path = $route->getPath();
                $issue = $this->validateHandler($route->getHandler(), "dynamic route: {$method} {$path}");
                if ($issue !== null) {
                    $issues[] = $issue;
                }
            }
        }

        return $issues;
    }

    /**
     * Validate individual handler for caching compatibility
     */
    private function validateHandler(mixed $handler, string $context): ?string
    {
        if ($handler instanceof \Closure) {
            return "Warning: {$context} uses closure which cannot be cached reliably. " .
                   "Consider using array callable [Class::class, 'method'] instead.";
        }

        if (is_array($handler)) {
            if (!class_exists($handler[0]) || !method_exists($handler[0], $handler[1])) {
                return "Error: {$context} references non-existent method {$handler[0]}::{$handler[1]}";
            }
        }

        if (is_string($handler) && str_contains($handler, '::')) {
            [$class, $method] = explode('::', $handler, 2);
            if (!class_exists($class) || !method_exists($class, $method)) {
                return "Error: {$context} references non-existent method {$handler}";
            }
        }

        return null;
    }

    // Generate optimized native PHP matcher
    public function compileToNative(Router $router): string
    {
        $static = $router->getStaticRoutes();

        $code = "<?php\n\n";
        $code .= "// Native compiled matcher\n";
        $code .= "return function(\$method, \$path) {\n";
        $code .= "    \$key = \$method . ':' . \$path;\n";
        $code .= "    switch(\$key) {\n";

        foreach ($static as $key => $route) {
            $handler = var_export($route->getHandler(), true);
            $code .= "        case '{$key}': return {$handler};\n";
        }

        $code .= "        default: return null;\n";
        $code .= "    }\n";
        $code .= "};\n";

        return $code;
    }
}

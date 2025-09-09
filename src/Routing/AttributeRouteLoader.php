<?php

declare(strict_types=1);

namespace Glueful\Routing;

use Glueful\Routing\Attributes\{Route, Controller, Middleware, Get, Post, Put, Delete, Fields};

class AttributeRouteLoader
{
    private Router $router;
    /**
     * @var array<string>
     */
    private array $scannedClasses = [];

    public function __construct(Router $router)
    {
        $this->router = $router;
    }

    /**
     * Scan directory for controllers with route attributes
     */
    public function scanDirectory(string $directory): void
    {
        $files = $this->getPhpFiles($directory);

        foreach ($files as $file) {
            $class = $this->getClassFromFile($file);
            if ($class !== null && !in_array($class, $this->scannedClasses, true)) {
                $this->processClass($class);
                $this->scannedClasses[] = $class;
            }
        }
    }

    /**
     * Process a single controller class
     */
    public function processClass(string $className): void
    {
        if (!class_exists($className)) {
            return;
        }

        $reflection = new \ReflectionClass($className);

        // Skip if abstract or not instantiable
        if ($reflection->isAbstract() || !$reflection->isInstantiable()) {
            return;
        }

        // Get class-level attributes
        $classAttributes = $this->getClassAttributes($reflection);
        $prefix = $classAttributes['prefix'] ?? '';
        $classMiddleware = $classAttributes['middleware'] ?? [];

        // Process each public method
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor() || $method->isStatic()) {
                continue;
            }

            $this->processMethod($method, $className, $prefix, $classMiddleware);
        }
    }

    /**
     * Extract class-level routing attributes
     *
     * @param \ReflectionClass<object> $class
     * @return array<string, mixed>
     */
    private function getClassAttributes(\ReflectionClass $class): array
    {
        $attributes = [];

        // Check for Controller attribute
        foreach ($class->getAttributes(Controller::class) as $attribute) {
            $controller = $attribute->newInstance();
            $attributes['prefix'] = $controller->prefix;
            $attributes['middleware'] = $controller->middleware;
        }

        // Check for class-level Route attribute
        foreach ($class->getAttributes(Route::class) as $attribute) {
            $route = $attribute->newInstance();
            $attributes['prefix'] = $route->path;
            if (count($route->middleware) > 0) {
                $attributes['middleware'] = array_merge(
                    $attributes['middleware'] ?? [],
                    $route->middleware
                );
            }
        }

        // Check for class-level Middleware attributes
        foreach ($class->getAttributes(Middleware::class) as $attribute) {
            $middleware = $attribute->newInstance();
            $attributes['middleware'] = array_merge(
                $attributes['middleware'] ?? [],
                $middleware->middleware
            );
        }

        return $attributes;
    }

    /**
     * Process method attributes and register routes
     *
     * @param array<string> $classMiddleware
     */
    private function processMethod(
        \ReflectionMethod $method,
        string $className,
        string $prefix,
        array $classMiddleware
    ): void {
        $methodName = $method->getName();
        $methodMiddleware = [];

        // Get method-level middleware
        foreach ($method->getAttributes(Middleware::class) as $attribute) {
            $middleware = $attribute->newInstance();
            $methodMiddleware = array_merge($methodMiddleware, $middleware->middleware);
        }

        // Combine class and method middleware
        $allMiddleware = array_merge($classMiddleware, $methodMiddleware);

        // Process Route attributes
        foreach ($method->getAttributes(Route::class) as $attribute) {
            $route = $attribute->newInstance();
            $fullPath = $this->combinePaths($prefix, $route->path);

            foreach ($route->methods as $httpMethod) {
                $registered = match (strtoupper($httpMethod)) {
                    'GET' => $this->router->get($fullPath, [$className, $methodName]),
                    'POST' => $this->router->post($fullPath, [$className, $methodName]),
                    'PUT' => $this->router->put($fullPath, [$className, $methodName]),
                    'DELETE' => $this->router->delete($fullPath, [$className, $methodName]),
                    default => throw new \InvalidArgumentException("Unsupported HTTP method: {$httpMethod}")
                };

                // Apply middleware
                if (count($allMiddleware) > 0) {
                    $registered->middleware($allMiddleware);
                }

                // Apply route-specific middleware
                if (count($route->middleware) > 0) {
                    $registered->middleware($route->middleware);
                }

                // Set route name
                if ($route->name !== null) {
                    $registered->name($route->name);
                }

                // Apply constraints
                if (count($route->where) > 0) {
                    $registered->where($route->where);
                }

                // Process Fields attribute for this route
                $this->processFieldsAttribute($method, $registered);
            }
        }

        // Process HTTP method shortcuts (Get, Post, Put, Delete)
        $this->processHttpMethodAttributes($method, $className, $methodName, $prefix, $allMiddleware);
    }

    /**
     * Process shorthand HTTP method attributes
     *
     * @param array<string> $middleware
     */
    private function processHttpMethodAttributes(
        \ReflectionMethod $method,
        string $className,
        string $methodName,
        string $prefix,
        array $middleware
    ): void {
        $httpMethods = [
            Get::class => 'GET',
            Post::class => 'POST',
            Put::class => 'PUT',
            Delete::class => 'DELETE',
        ];

        foreach ($httpMethods as $attributeClass => $httpMethod) {
            foreach ($method->getAttributes($attributeClass) as $attribute) {
                $attr = $attribute->newInstance();
                $fullPath = $this->combinePaths($prefix, $attr->path);

                $route = match ($httpMethod) {
                    'GET' => $this->router->get($fullPath, [$className, $methodName]),
                    'POST' => $this->router->post($fullPath, [$className, $methodName]),
                    'PUT' => $this->router->put($fullPath, [$className, $methodName]),
                    'DELETE' => $this->router->delete($fullPath, [$className, $methodName])
                };

                if (count($middleware) > 0) {
                    $route->middleware($middleware);
                }

                if ($attr->name !== null) {
                    $route->name($attr->name);
                }

                if (count($attr->where) > 0) {
                    $route->where($attr->where);
                }

                // Process Fields attribute for this route
                $this->processFieldsAttribute($method, $route);
            }
        }
    }

    /**
     * Combine prefix and path properly
     */
    private function combinePaths(string $prefix, string $path): string
    {
        $prefix = rtrim($prefix, '/');
        $path = ltrim($path, '/');

        if ($prefix === '') {
            return '/' . $path;
        }

        return $prefix . '/' . $path;
    }

    /**
     * Get all PHP files in directory recursively
     *
     * @return array<string>
     */
    private function getPhpFiles(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Extract class name from file (simple implementation)
     */
    private function getClassFromFile(string $file): ?string
    {
        $contents = file_get_contents($file);

        // Extract namespace
        if (preg_match('/namespace\s+([^;]+);/', $contents, $namespaceMatch)) {
            $namespace = $namespaceMatch[1];
        } else {
            $namespace = '';
        }

        // Extract class name
        if (preg_match('/class\s+(\w+)/', $contents, $classMatch)) {
            $className = $classMatch[1];
            return $namespace !== '' ? $namespace . '\\' . $className : $className;
        }

        return null;
    }

    /**
     * Process Fields attribute and set configuration on route
     */
    private function processFieldsAttribute(\ReflectionMethod $method, \Glueful\Routing\Route $route): void
    {
        $fieldsAttributes = $method->getAttributes(Fields::class);

        if (count($fieldsAttributes) > 0) {
            $fieldsAttr = $fieldsAttributes[0]->newInstance();

            $config = [];

            if ($fieldsAttr->strict !== null) {
                $config['strict'] = $fieldsAttr->strict;
            }

            if ($fieldsAttr->allowed !== null) {
                $config['allowed'] = $fieldsAttr->allowed;
            }

            if ($fieldsAttr->whitelistKey !== null) {
                $config['whitelistKey'] = $fieldsAttr->whitelistKey;
            }

            if ($fieldsAttr->maxDepth !== null) {
                $config['maxDepth'] = $fieldsAttr->maxDepth;
            }

            if ($fieldsAttr->maxFields !== null) {
                $config['maxFields'] = $fieldsAttr->maxFields;
            }

            if ($fieldsAttr->maxItems !== null) {
                $config['maxItems'] = $fieldsAttr->maxItems;
            }

            if (count($config) > 0) {
                $route->setFieldsConfig($config);
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace Glueful\Routing;

use Glueful\Api\RateLimiting\Attributes\RateLimit;
use Glueful\Api\RateLimiting\Attributes\RateLimitCost;
use Glueful\Routing\Attributes\RequireScope;
use Glueful\Routing\Attributes\{Route, Controller, Middleware, Get, Post, Put, Patch, Delete, Options, Fields};
use Glueful\Auth\Attributes\{RequiresPermission, RequiresRole};

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
                    'PATCH' => $this->router->patch($fullPath, [$className, $methodName]),
                    'DELETE' => $this->router->delete($fullPath, [$className, $methodName]),
                    'HEAD' => $this->router->head($fullPath, [$className, $methodName]),
                    'OPTIONS' => $this->router->options($fullPath, [$className, $methodName]),
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

                // Process RateLimit attributes for this route
                $this->processRateLimitAttributes($method, $registered);

                // Process RequireScope attributes for this route
                $this->processRequireScopeAttributes($method, $registered);

                // Process RequiresPermission/RequiresRole — auto-attach the gate
                $this->processGateAttributes($className, $method, $registered);
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
            Patch::class => 'PATCH',
            Delete::class => 'DELETE',
            Options::class => 'OPTIONS',
        ];

        foreach ($httpMethods as $attributeClass => $httpMethod) {
            foreach ($method->getAttributes($attributeClass) as $attribute) {
                $attr = $attribute->newInstance();
                $fullPath = $this->combinePaths($prefix, $attr->path);

                $route = match ($httpMethod) {
                    'GET' => $this->router->get($fullPath, [$className, $methodName]),
                    'POST' => $this->router->post($fullPath, [$className, $methodName]),
                    'PUT' => $this->router->put($fullPath, [$className, $methodName]),
                    'PATCH' => $this->router->patch($fullPath, [$className, $methodName]),
                    'DELETE' => $this->router->delete($fullPath, [$className, $methodName]),
                    'OPTIONS' => $this->router->options($fullPath, [$className, $methodName])
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

                // Process RateLimit attributes for this route
                $this->processRateLimitAttributes($method, $route);

                // Process RequireScope attributes for this route
                $this->processRequireScopeAttributes($method, $route);

                // Process RequiresPermission/RequiresRole — auto-attach the gate
                $this->processGateAttributes($className, $method, $route);
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

    /**
     * Process RateLimit and RateLimitCost attributes and set configuration on route
     */
    private function processRateLimitAttributes(\ReflectionMethod $method, \Glueful\Routing\Route $route): void
    {
        // Process RateLimit attributes (can be multiple for multi-window limiting)
        $rateLimitAttributes = $method->getAttributes(RateLimit::class);

        if (count($rateLimitAttributes) > 0) {
            $rateLimitConfigs = [];

            foreach ($rateLimitAttributes as $attribute) {
                $rateLimitAttr = $attribute->newInstance();
                $rateLimitConfigs[] = $rateLimitAttr->toArray();
            }

            $route->setRateLimitConfig($rateLimitConfigs);
        }

        // Process RateLimitCost attribute (only one per method)
        $costAttributes = $method->getAttributes(RateLimitCost::class);

        if (count($costAttributes) > 0) {
            $costAttr = $costAttributes[0]->newInstance();
            $route->setRateLimitCost($costAttr->cost);
        }
    }

    /**
     * Process #[RequireScope] attributes on a method.
     *
     * Collects all instances (the IS_REPEATABLE flag makes this return >1
     * when the attribute is stacked), stores them on the route as metadata,
     * and auto-attaches the 'require_scope' middleware so the route's
     * pipeline actually enforces them. Without the middleware attach the
     * metadata would be stored but never read.
     */
    private function processRequireScopeAttributes(
        \ReflectionMethod $method,
        \Glueful\Routing\Route $route
    ): void {
        $attributes = $method->getAttributes(RequireScope::class);
        if (count($attributes) === 0) {
            return;
        }

        $configs = [];
        foreach ($attributes as $attribute) {
            $configs[] = $attribute->newInstance()->scopes;
        }

        $route->setRequireScopeConfig($configs);
        $route->middleware('require_scope');
    }

    /**
     * Auto-attach the 'gate_permissions' middleware when a route's handler carries
     * #[RequiresPermission] or #[RequiresRole] — at the method level OR the class
     * level. Without the middleware the attributes are decorative and the route
     * fails open (GateAttributeMiddleware never runs, so PermissionManager::can()
     * is never consulted).
     *
     * Detection is against the route's handler class ($className), mirroring what
     * GateAttributeMiddleware reflects at request time, so a class-level attribute
     * on the concrete controller is honoured even for inherited methods.
     */
    private function processGateAttributes(
        string $className,
        \ReflectionMethod $method,
        \Glueful\Routing\Route $route
    ): void {
        if ($this->hasGateAttributes($className, $method)) {
            $route->middleware('gate_permissions');
        }
    }

    /**
     * True when #[RequiresPermission] or #[RequiresRole] is present on the method
     * or on the handler class.
     */
    private function hasGateAttributes(string $className, \ReflectionMethod $method): bool
    {
        foreach ([RequiresPermission::class, RequiresRole::class] as $attribute) {
            if (count($method->getAttributes($attribute)) > 0) {
                return true;
            }
        }

        try {
            $class = new \ReflectionClass($className);
        } catch (\ReflectionException) {
            return false;
        }

        foreach ([RequiresPermission::class, RequiresRole::class] as $attribute) {
            if (count($class->getAttributes($attribute)) > 0) {
                return true;
            }
        }

        return false;
    }
}

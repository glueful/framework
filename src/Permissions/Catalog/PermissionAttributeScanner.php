<?php

declare(strict_types=1);

namespace Glueful\Permissions\Catalog;

use Glueful\Routing\Router;

/**
 * Computes the set of permissions/roles actually enforced by routes: `#[RequiresPermission]` /
 * `#[RequiresRole]` attributes on handlers, plus the parameters of any route middleware named in
 * `$enforcingMiddleware` (config `permissions.enforcing_middleware`), for apps that enforce
 * permissions as `->middleware('content_permission:content.view,content.edit')`.
 * Used by `permissions:diff` to catch enforce-vs-declare drift. NOT a source of truth.
 *
 * Intentionally not `final`: `DiffCommand` depends on it and tests mock it (PHPUnit cannot
 * mock final classes). There is no behavioral reason to seal it.
 */
class PermissionAttributeScanner
{
    /**
     * @param list<string> $enforcingMiddleware middleware aliases whose parameters are permissions
     */
    public function __construct(
        private readonly Router $router,
        private readonly array $enforcingMiddleware = [],
    ) {
    }

    /** @return array{permissions: string[], roles: string[]} */
    public function scan(): array
    {
        $permissions = [];
        $roles = [];

        foreach ($this->router->getAllRoutes() as $route) {
            foreach ($this->middlewarePermissions((array) ($route['middleware'] ?? [])) as $name) {
                $permissions[$name] = true;
            }
            [$class, $method] = $this->resolveHandler($route['handler'] ?? null);
            if ($class === null || !class_exists($class)) {
                continue;
            }
            foreach ($this->attributeNames($class, $method, 'Glueful\\Auth\\Attributes\\RequiresPermission') as $name) {
                $permissions[$name] = true;
            }
            foreach ($this->attributeNames($class, $method, 'Glueful\\Auth\\Attributes\\RequiresRole') as $name) {
                $roles[$name] = true;
            }
        }

        return ['permissions' => array_keys($permissions), 'roles' => array_keys($roles)];
    }

    /**
     * Permissions named as parameters of an enforcing middleware: `alias:a,b` yields a and b.
     *
     * @param array<mixed> $middleware
     * @return list<string>
     */
    private function middlewarePermissions(array $middleware): array
    {
        $names = [];
        foreach ($middleware as $entry) {
            if (!is_string($entry) || !str_contains($entry, ':')) {
                continue;
            }
            [$alias, $params] = explode(':', $entry, 2);
            if (!in_array(trim($alias), $this->enforcingMiddleware, true)) {
                continue;
            }
            foreach (explode(',', $params) as $param) {
                $param = trim($param);
                if ($param !== '') {
                    $names[] = $param;
                }
            }
        }

        return $names;
    }

    /**
     * @return array{0: ?class-string, 1: ?string} [class, method] or [null, null] for unscannable handlers
     */
    private function resolveHandler(mixed $handler): array
    {
        if (is_array($handler) && isset($handler[0]) && is_string($handler[0])) {
            /** @var class-string $class */
            $class = $handler[0];
            return [$class, is_string($handler[1] ?? null) ? $handler[1] : '__invoke'];
        }
        if (is_string($handler)) {
            foreach (['::', '@'] as $sep) {
                if (str_contains($handler, $sep)) {
                    [$c, $m] = explode($sep, $handler, 2);
                    /** @var class-string $c */
                    return [$c, $m];
                }
            }
            if (class_exists($handler)) {
                /** @var class-string $handler */
                return [$handler, '__invoke'];
            }
        }
        return [null, null]; // closures and unrecognized handlers are not scannable
    }

    /**
     * @param class-string $class
     * @return string[]
     */
    private function attributeNames(string $class, ?string $method, string $attributeFqcn): array
    {
        $names = [];
        try {
            $rc = new \ReflectionClass($class);
            foreach ($rc->getAttributes($attributeFqcn) as $a) {
                $vars = get_object_vars($a->newInstance());
                if (isset($vars['name']) && is_string($vars['name'])) {
                    $names[] = $vars['name'];
                }
            }
            if ($method !== null && $rc->hasMethod($method)) {
                foreach ($rc->getMethod($method)->getAttributes($attributeFqcn) as $a) {
                    $vars = get_object_vars($a->newInstance());
                    if (isset($vars['name']) && is_string($vars['name'])) {
                        $names[] = $vars['name'];
                    }
                }
            }
        } catch (\Throwable) {
            // Unreadable handler — skip.
        }
        return $names;
    }
}

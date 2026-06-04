<?php

declare(strict_types=1);

namespace Glueful\Permissions\Catalog;

use Glueful\Routing\Router;

/**
 * Computes the set of permissions/roles actually enforced by route attributes.
 * Used by `permissions:diff` to catch enforce-vs-declare drift. NOT a source of truth.
 *
 * Intentionally not `final`: `DiffCommand` depends on it and tests mock it (PHPUnit cannot
 * mock final classes). There is no behavioral reason to seal it.
 */
class PermissionAttributeScanner
{
    public function __construct(private readonly Router $router)
    {
    }

    /** @return array{permissions: string[], roles: string[]} */
    public function scan(): array
    {
        $permissions = [];
        $roles = [];

        foreach ($this->router->getAllRoutes() as $route) {
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
                $names[] = $a->newInstance()->name;
            }
            if ($method !== null && $rc->hasMethod($method)) {
                foreach ($rc->getMethod($method)->getAttributes($attributeFqcn) as $a) {
                    $names[] = $a->newInstance()->name;
                }
            }
        } catch (\Throwable) {
            // Unreadable handler — skip.
        }
        return $names;
    }
}

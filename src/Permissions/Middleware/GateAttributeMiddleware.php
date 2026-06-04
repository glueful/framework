<?php

declare(strict_types=1);

namespace Glueful\Permissions\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Glueful\Routing\RouteMiddleware;
use Glueful\Permissions\PermissionManager;
use Glueful\Permissions\Catalog\RoleKey;
use Glueful\Auth\UserIdentity;

/**
 * Thin adapter: collects #[RequiresPermission]/#[RequiresRole] from the handler and
 * routes each check through PermissionManager::can() — the single enforcement entry
 * point. PermissionManager (not this middleware) decides provider-vs-Gate fallback.
 */
final class GateAttributeMiddleware implements RouteMiddleware
{
    public function __construct(private PermissionManager $permissions)
    {
    }

    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        $meta = $request->attributes->get('handler_meta'); // ['class'=>..., 'method'=>...]
        if ($meta === null || !isset($meta['class'])) {
            return $next($request);
        }

        /** @var UserIdentity|null $user */
        $user = $request->attributes->get('auth.user');
        if ($user === null) {
            return $this->forbidden();
        }

        // Context passed to can(); roles come from the request identity (no fabrication).
        $context = [
            'roles' => $user->roles(),
            'scopes' => $user->scopes(),
            'tenant_id' => $request->attributes->get('tenant.id'),
            'route_params' => (array) $request->attributes->get('route.params'),
            'jwt_claims' => (array) $request->attributes->get('jwt.claims'),
        ];

        $required = [];
        // Permission attributes carry an optional resource; default to 'system'.
        $permissionAttr = 'Glueful\\Auth\\Attributes\\RequiresPermission';
        foreach ($this->collectAttributePairs($meta, $permissionAttr) as [$name, $resource]) {
            $required[] = [$name, $resource ?? 'system'];
        }
        // Role attributes: canonicalize via the SHARED RoleKey contract (dotted values pass
        // through unchanged; non-dotted map to "role.{name}"). The same RoleKey is used by the
        // permissions:diff scanner so enforcement and drift detection never diverge.
        foreach ($this->collectAttributeValues($meta, 'Glueful\\Auth\\Attributes\\RequiresRole', 'name') as $roleName) {
            $required[] = [RoleKey::canonical($roleName), 'system'];
        }

        foreach ($required as [$perm, $resource]) {
            if (!$this->permissions->can($user->id(), $perm, $resource, $context)) {
                return $this->forbidden();
            }
        }

        return $next($request);
    }

    /**
     * @param array{class?:string,method?:string} $meta
     * @return array<string>
     */
    private function collectAttributeValues(array $meta, string $attributeFqcn, string $prop): array
    {
        $values = [];
        foreach ($this->attributeInstances($meta, $attributeFqcn) as $inst) {
            $v = $inst->{$prop} ?? null;
            if ($v !== null) {
                $values[] = $v;
            }
        }
        return $values;
    }

    /**
     * @param array{class?:string,method?:string} $meta
     * @return list<array{0:string,1:?string}> [name, resource]
     */
    private function collectAttributePairs(array $meta, string $attributeFqcn): array
    {
        $pairs = [];
        foreach ($this->attributeInstances($meta, $attributeFqcn) as $inst) {
            $name = $inst->name ?? null;
            if ($name !== null) {
                $pairs[] = [$name, $inst->resource ?? null];
            }
        }
        return $pairs;
    }

    /**
     * @param array{class?:string,method?:string} $meta
     * @return list<object>
     */
    private function attributeInstances(array $meta, string $attributeFqcn): array
    {
        $instances = [];
        try {
            $rc = new \ReflectionClass($meta['class']);
            foreach ($rc->getAttributes($attributeFqcn) as $a) {
                $instances[] = $a->newInstance();
            }
            if (isset($meta['method']) && $rc->hasMethod($meta['method'])) {
                foreach ($rc->getMethod($meta['method'])->getAttributes($attributeFqcn) as $a) {
                    $instances[] = $a->newInstance();
                }
            }
        } catch (\Throwable) {
            // Attribute class absent or reflection failure — treat as no requirements.
        }
        return $instances;
    }

    private function forbidden(): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'message' => 'Forbidden',
            'code' => 403,
            'error_code' => 'FORBIDDEN',
        ], 403);
    }
}

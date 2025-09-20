<?php

declare(strict_types=1);

namespace Glueful\Permissions\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Glueful\Routing\RouteMiddleware;
use Glueful\Permissions\{Gate, Context};
use Glueful\Auth\UserIdentity;

final class GateAttributeMiddleware implements RouteMiddleware
{
    public function __construct(private Gate $gate)
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

        $ctx = new Context(
            tenantId: $request->attributes->get('tenant.id'),
            routeParams: (array) $request->attributes->get('route.params'),
            jwtClaims: (array) $request->attributes->get('jwt.claims'),
            extra: []
        );

        $required = array_merge(
            $this->collectAttributeValues($meta, 'Glueful\\Auth\\Attributes\\RequiresPermission', 'name'),
            $this->collectAttributeValues($meta, 'Glueful\\Auth\\Attributes\\RequiresRole', 'name')
        );

        foreach ($required as $permOrRole) {
            // Check "role.*" permission if no dot, else treat as permission
            $perm = str_contains($permOrRole, '.') ? $permOrRole : "role.{$permOrRole}";
            $decision = $this->gate->decide($user, $perm, null, $ctx);
            if ($decision !== 'grant') {
                return $this->forbidden();
            }
        }

        return $next($request);
    }

    /**
     * @param array{class?:string,method?:string} $meta
     * @return array<string>
     * @phpstan-ignore-next-line
     */
    private function collectAttributeValues(array $meta, string $attributeFqcn, string $prop): array
    {
        $values = [];
        try {
            $rc = new \ReflectionClass($meta['class']);
            foreach ($rc->getAttributes($attributeFqcn) as $a) {
                $inst = $a->newInstance();
                // @phpstan-ignore-next-line
                $values[] = $inst->{$prop} ?? null;
            }
            if (isset($meta['method']) && $rc->hasMethod($meta['method'])) {
                $rm = $rc->getMethod($meta['method']);
                foreach ($rm->getAttributes($attributeFqcn) as $a) {
                    $inst = $a->newInstance();
                    // @phpstan-ignore-next-line
                    $values[] = $inst->{$prop} ?? null;
                }
            }
        } catch (\Throwable) {
            // If attributes class not present, ignore
        }
        return array_values(array_filter($values, fn($v) => $v !== null));
    }

    private function forbidden(): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'message' => 'Forbidden',
            'code' => 403,
            'error_code' => 'FORBIDDEN'
        ], 403);
    }
}

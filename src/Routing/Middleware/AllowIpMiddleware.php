<?php

declare(strict_types=1);

namespace Glueful\Routing\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Glueful\Http\Response;
use Glueful\Routing\RouteMiddleware;

class AllowIpMiddleware implements RouteMiddleware
{
    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        $allow = (array) config('security.health_ip_allowlist', []);
        $ip = $request->getClientIp();

        if (count($allow) > 0 && !in_array($ip, $allow, true)) {
            return Response::forbidden('Health endpoint restricted');
        }

        return $next($request);
    }
}

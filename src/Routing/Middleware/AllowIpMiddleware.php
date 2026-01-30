<?php

declare(strict_types=1);

namespace Glueful\Routing\Middleware;

use Glueful\Bootstrap\ApplicationContext;
use Symfony\Component\HttpFoundation\Request;
use Glueful\Http\Response;
use Glueful\Routing\RouteMiddleware;

class AllowIpMiddleware implements RouteMiddleware
{
    private ?ApplicationContext $context;

    public function __construct(?ApplicationContext $context = null)
    {
        $this->context = $context;
    }

    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        $allow = (array) $this->getConfig('security.health_ip_allowlist', []);
        $ip = $request->getClientIp();

        if (count($allow) > 0 && !in_array($ip, $allow, true)) {
            return Response::forbidden('Health endpoint restricted');
        }

        return $next($request);
    }

    private function getConfig(string $key, mixed $default = null): mixed
    {
        if ($this->context === null) {
            return $default;
        }

        return config($this->context, $key, $default);
    }
}

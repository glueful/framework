<?php

declare(strict_types=1);

namespace Glueful\Routing\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Glueful\Http\Response;
use Glueful\Observability\Tracing\TracerInterface;
use Glueful\Routing\RouteMiddleware;
use Glueful\Support\SensitiveParamRedactor;

final class TracingMiddleware implements RouteMiddleware
{
    public function __construct(private TracerInterface $tracer)
    {
    }

    public function handle(Request $request, callable $next, ...$params): mixed
    {
        // The matched route template is already placeholder-shaped; the raw path
        // fallback is not, so it goes through sensitive-path redaction.
        $route = $request->attributes->get('_route')
            ?? SensitiveParamRedactor::sanitizePath($request->getPathInfo());

        $builder = $this->tracer->startSpan('http.request', [
            'http.method' => $request->getMethod(),
            'http.route' => $route,
            'user_agent' => $request->headers->get('User-Agent'),
            'net.peer.ip' => $request->getClientIp(),
        ]);

        if (function_exists('request_id')) {
            $builder->setAttribute('glueful.request_id', request_id());
        }

        $span = $builder->startSpan();

        try {
            $resp = $next($request);

            if (is_object($resp) && method_exists($resp, 'getStatusCode')) {
                $span->setAttribute('http.status_code', $resp->getStatusCode());
            }

            if (isset($_SESSION['user_uuid'])) {
                $span->setAttribute('enduser.id', $_SESSION['user_uuid']);
            }

            return $resp;
        } finally {
            $span->end();
        }
    }
}

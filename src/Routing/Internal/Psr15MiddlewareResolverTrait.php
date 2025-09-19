<?php

declare(strict_types=1);

namespace Glueful\Routing\Internal;

use Glueful\Http\Bridge\Psr15\Psr15AdapterFactory;
use Psr\Http\Server\MiddlewareInterface as Psr15Middleware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mixin/trait to be used by Router or MiddlewareResolver component.
 * Detects PSR-15 middleware services and wraps them.
 *
 * @property \Psr\Container\ContainerInterface $container
 */
trait Psr15MiddlewareResolverTrait
{
    /**
     * @param mixed $service A resolved middleware service from container
     * @param array<string, mixed> $config HTTP configuration array
     * @return callable(Request, callable): Response|mixed
     */
    protected function maybeWrapPsr15Middleware($service, array $config)
    {
        if ($service instanceof Psr15Middleware) {
            if (($config['psr15']['enabled'] ?? true) !== true) {
                throw new \RuntimeException(
                    "PSR-15 middleware support is disabled. Enable via config http.psr15.enabled=true"
                );
            }

            // auto_detect switch lets you opt-in/opt-out discovery in the pipeline
            if (($config['psr15']['auto_detect'] ?? true) !== true) {
                return $service; // leave untouched for manual wrapping scenarios
            }

            // Resolve optional factory provider
            $factoryProvider = $config['psr15']['factory_provider'] ?? null; // callable|string|array|null
            $callableFactory = null;
            if (is_callable($factoryProvider)) {
                $callableFactory = $factoryProvider;
            } elseif (is_string($factoryProvider)) {
                try {
                    // Try to resolve a service id from the container
                    $svc = $this->container->get($factoryProvider);
                    if (is_callable($svc)) {
                        $callableFactory = $svc;
                    }
                } catch (\Throwable) {
                    // ignore, will fallback to auto-detection in factory
                }
            } elseif (is_array($factoryProvider) && is_callable($factoryProvider)) {
                $callableFactory = $factoryProvider; // [obj, method]
            }

            try {
                return Psr15AdapterFactory::wrap($service, $callableFactory);
            } catch (\RuntimeException $e) {
                // Honor throw_on_missing_bridge flag
                $strict = (bool) ($config['psr15']['throw_on_missing_bridge'] ?? true);
                if ($strict) {
                    throw $e;
                }
                // Non-strict: return original service, caller should handle
                return $service;
            }
        }
        return $service;
    }
}

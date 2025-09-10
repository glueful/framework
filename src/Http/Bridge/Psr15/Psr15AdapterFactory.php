<?php

declare(strict_types=1);

namespace Glueful\Http\Bridge\Psr15;

use Psr\Http\Server\MiddlewareInterface as Psr15Middleware;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Request as SfRequest;
use Symfony\Component\HttpFoundation\Response as SfResponse;

/**
 * Wrap a PSR-15 middleware as a Glueful-compatible callable(Request, callable): Response
 * – Uses cached bridges for perf (optional deps are validated at runtime).
 */
final class Psr15AdapterFactory
{
    private static ?HttpFoundationFactory $httpFoundationBridge = null;
    private static ?PsrHttpFactory $psrBridge = null;

    /**
     * @param Psr15Middleware $middleware
     * @param callable|null   $psr7FactoryProvider Returns PSR-17 factories [request, stream, uploadedFile, response]
     *                                            When null, we try to construct default Nyholm factories if present.
     */
    public static function wrap(Psr15Middleware $middleware, ?callable $psr7FactoryProvider = null): callable
    {
        self::ensureBridgeAvailable();

        if (self::$httpFoundationBridge === null || self::$psrBridge === null) {
            [$reqFactory, $streamFactory, $uploadedFileFactory, $respFactory] =
                self::resolvePsr17Factories($psr7FactoryProvider);

            self::$httpFoundationBridge = new HttpFoundationFactory();
            self::$psrBridge = new PsrHttpFactory($reqFactory, $streamFactory, $uploadedFileFactory, $respFactory);
        }

        return function (SfRequest $request, callable $next) use ($middleware): SfResponse {
            $handler = new RequestHandlerAdapter($next, self::$psrBridge, self::$httpFoundationBridge);
            $psrRequest = self::$psrBridge->createRequest($request);
            $psrResponse = $middleware->process($psrRequest, $handler);
            return self::$httpFoundationBridge->createResponse($psrResponse);
        };
    }

    private static function ensureBridgeAvailable(): void
    {
        if (!class_exists(HttpFoundationFactory::class) || !class_exists(PsrHttpFactory::class)) {
            // Clear, actionable message with install command (per plan)
            throw new \RuntimeException(
                "PSR-15 detected but PSR-7 bridge not installed. " .
                "Run: composer require symfony/psr-http-message-bridge nyholm/psr7"
            );
        }
    }

    /**
     * @return array{0:object,1:object,2:object,3:object}
     */
    private static function resolvePsr17Factories(?callable $provider): array
    {
        if ($provider !== null) {
            return $provider();
        }

        // Default to Nyholm if available
        if (class_exists(\Nyholm\Psr7\Factory\Psr17Factory::class)) {
            $f = new \Nyholm\Psr7\Factory\Psr17Factory();
            return [$f, $f, $f, $f];
        }

        throw new \RuntimeException(
            "No PSR-17 factories found. Provide factories or install nyholm/psr7."
        );
    }
}

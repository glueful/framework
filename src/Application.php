<?php

declare(strict_types=1);

namespace Glueful;

use Psr\Container\ContainerInterface;
use Glueful\Routing\Router;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Application class - handles HTTP requests
 * All bootstrap logic has been consolidated into Framework class
 */
class Application
{
    private ContainerInterface $container;
    private LoggerInterface $logger;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->logger = $container->get(LoggerInterface::class);
    }

    /**
     * Handle HTTP requests
     */
    public function handle(Request $request): HttpResponse
    {
        $startTime = microtime(true);
        $requestId = $this->getRequestId();

        // Dispatch via Next-Gen Router
        $router = $this->container->get(Router::class);
        $response = $router->dispatch($request);

        $totalTime = round((microtime(true) - $startTime) * 1000, 2);
        $this->logger->info(
            'Request completed',
            [
                'type' => 'request',
                'request_id' => $requestId,
                'method' => $request->getMethod(),
                'uri' => $request->getPathInfo(),
                'time_ms' => $totalTime,
                'status' => $response->getStatusCode(),
                'timestamp' => date('c')
            ]
        );

        return $response;
    }

    /**
     * Terminate request lifecycle
     */
    public function terminate(Request $request, HttpResponse $response): void
    {
        // Minimal cleanup - background tasks handled by Framework
        $this->logger->debug('Request terminated', [
            'method' => $request->getMethod(),
            'status' => $response->getStatusCode()
        ]);
    }

    /**
     * Get the DI container
     */
    public function getContainer(): ContainerInterface
    {
        return $this->container;
    }

    /**
     * Get or generate request ID
     */
    private function getRequestId(): string
    {
        if (function_exists('request_id')) {
            return request_id();
        }

        // Fallback request ID generation
        return uniqid('req_', true);
    }
}

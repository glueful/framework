<?php

declare(strict_types=1);

namespace Glueful\Http\Bridge\Psr15;

use Psr\Http\Server\MiddlewareInterface as Psr15Middleware;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;

/**
 * Expose a Glueful middleware (handle(Request $r, callable $next): Response) as a PSR-15 middleware.
 */
final class MiddlewareAsPsr15 implements Psr15Middleware
{
    /** @var callable */
    private $gluefulMiddleware;
    private PsrHttpFactory $psrBridge;
    private HttpFoundationFactory $foundationBridge;

    /**
     * @param callable $gluefulMiddleware signature: function(SymfonyRequest, callable): SymfonyResponse
     */
    public function __construct(
        callable $gluefulMiddleware,
        PsrHttpFactory $psrBridge,
        HttpFoundationFactory $foundationBridge
    ) {
        $this->gluefulMiddleware = $gluefulMiddleware;
        $this->psrBridge = $psrBridge;
        $this->foundationBridge = $foundationBridge;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $sfReq = $this->foundationBridge->createRequest($request);
        $sfResp = ($this->gluefulMiddleware)($sfReq, function ($r) use ($handler) {
            $psrReq = $this->psrBridge->createRequest($r);
            $psrResp = $handler->handle($psrReq);
            return $this->foundationBridge->createResponse($psrResp);
        });

        return $this->psrBridge->createResponse($sfResp);
    }
}

<?php

declare(strict_types=1);

namespace Glueful\Http\Bridge\Psr15;

use Psr\Http\Server\RequestHandlerInterface as Psr15RequestHandler;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Request as SfRequest;
use Symfony\Component\HttpFoundation\Response as SfResponse;

/**
 * Wrap a Glueful $next(Request): Response as a PSR-15 RequestHandlerInterface.
 */
final class RequestHandlerAdapter implements Psr15RequestHandler
{
    /** @var callable(SfRequest): SfResponse */
    private $next;
    private PsrHttpFactory $psrBridge;
    private HttpFoundationFactory $foundationBridge;

    /**
     * @param callable(SfRequest): SfResponse $next
     */
    public function __construct(callable $next, PsrHttpFactory $psrBridge, HttpFoundationFactory $foundationBridge)
    {
        $this->next = $next;
        $this->psrBridge = $psrBridge;
        $this->foundationBridge = $foundationBridge;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $sfReq = $this->foundationBridge->createRequest($request);
        $sfResp = ($this->next)($sfReq);
        return $this->psrBridge->createResponse($sfResp);
    }
}

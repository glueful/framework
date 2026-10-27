<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Routing\Middleware;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\Drivers\ArrayCacheDriver;
use Glueful\Container\Container;
use Glueful\Helpers\Utils;
use Glueful\Http\Exceptions\Domain\SecurityException;
use Glueful\Routing\Middleware\CSRFMiddleware;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /csrf-token built a bare `new CSRFMiddleware()` — no context, so no token cache — and the
 * token it returned was stored nowhere: the route's `csrf` middleware rejected it on the next write.
 */
final class CsrfTokenEndpointTest extends TestCase
{
    public function testTheIssuedTokenValidatesOnTheNextWrite(): void
    {
        $csrf = new CSRFMiddleware(cache: new ArrayCacheDriver());
        $context = new ApplicationContext(sys_get_temp_dir() . '/csrf_token_endpoint_' . uniqid());
        $context->setContainer(new Container([CSRFMiddleware::class => $csrf]));

        $issuing = Request::create('/api/v1/csrf-token', 'GET');
        $issuing->server->set('REMOTE_ADDR', '203.0.113.10');
        $token = Utils::csrfTokenData($issuing, $context)['token'];

        $write = Request::create('/api/v1/account', 'POST');
        $write->server->set('REMOTE_ADDR', '203.0.113.10');
        $write->headers->set('X-CSRF-Token', (string) $token);

        try {
            $response = $csrf->handle($write, static fn (): Response => new Response('next'), 3600, false, false);
        } catch (SecurityException $e) {
            self::fail('The /csrf-token token was rejected: ' . $e->getMessage());
        }

        self::assertSame('next', $response->getContent());
    }
}

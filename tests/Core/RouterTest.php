<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;
use Glueful\Framework;
use Glueful\Routing\Router;
use Symfony\Component\HttpFoundation\Request;
use Glueful\Http\Response as GluefulResponse;

final class RouterTest extends TestCase
{
    public function testStaticRoute(): void
    {
        $framework = Framework::create(getcwd());
        $app = $framework->boot();

        /** @var Router $router */
        $router = $app->getContainer()->get(Router::class);

        $router->get('/ping', fn() => new GluefulResponse(['ok' => true]));

        $res = $router->dispatch(Request::create('/ping', 'GET'));
        $this->assertSame(200, $res->getStatusCode());
    }
}

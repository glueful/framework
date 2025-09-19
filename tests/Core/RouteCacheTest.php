<?php

declare(strict_types=1);

namespace Glueful\Tests\Core;

use PHPUnit\Framework\TestCase;
use Glueful\Framework;
use Glueful\Routing\Router;
use Glueful\Routing\RouteCache;
use Symfony\Component\HttpFoundation\Request;
use Glueful\Http\Response as GluefulResponse;

final class RouteCacheTest extends TestCase
{
    public function testRouteCacheSaveAndLoad(): void
    {
        $app = Framework::create(getcwd())->boot();
        /** @var Router $router */
        $router = $app->getContainer()->get(Router::class);

        $router->get('/rc', fn() => new GluefulResponse(['ok' => true]));

        $cache = new RouteCache();
        $this->assertTrue($cache->save($router));

        $loaded = $cache->load();
        $this->assertIsArray($loaded);

        // Dispatch should still succeed after reconstruct
        $res = $router->dispatch(Request::create('/rc', 'GET'));
        $this->assertSame(200, $res->getStatusCode());
    }
}

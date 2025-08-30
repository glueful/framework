<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration;

use Glueful\Framework;
use Glueful\Http\Router;
use Glueful\Http\Response as ApiResponse;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class HttpRoundTripTest extends TestCase
{
    private Framework $framework;

    protected function setUp(): void
    {
        parent::setUp();
        $testPath = sys_get_temp_dir() . '/glueful-http-test-' . uniqid();
        $this->framework = Framework::create($testPath)->withEnvironment('testing');
        $this->framework->boot(allowReboot: true);
    }

    public function testSimpleGetRequest(): void
    {
        Router::get('/test', function (Request $request): ApiResponse {
            return ApiResponse::success(['message' => 'test successful']);
        });

        $request = Request::create('/test', 'GET');
        $response = Router::dispatch($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('test successful', $data['message']);
    }

    public function testPostRequestWithJsonBody(): void
    {
        Router::post('/api/users', function (Request $request): ApiResponse {
            $data = json_decode($request->getContent(), true) ?? [];
            return ApiResponse::success(['created' => $data]);
        });

        $requestData = ['name' => 'John Doe', 'email' => 'john@example.com'];
        $request = Request::create('/api/users', 'POST', [], [], [], [], json_encode($requestData));
        $request->headers->set('Content-Type', 'application/json');

        $response = Router::dispatch($request);
        $this->assertEquals(200, $response->getStatusCode());
        $responseData = json_decode($response->getContent(), true);
        $this->assertSame($requestData, $responseData['created']);
    }

    public function testMiddlewareExecution(): void
    {
        $executionOrder = [];

        Router::addMiddleware(new class ($executionOrder) implements \Glueful\Http\Middleware\MiddlewareInterface {
            private array $order;
            public function __construct(array &$order)
            {
                $this->order =& $order;
            }
            public function process(
                Request $request,
                \Glueful\Http\Middleware\RequestHandlerInterface $handler
            ): \Symfony\Component\HttpFoundation\Response {
                $this->order[] = 'middleware_before';
                $response = $handler->handle($request);
                $this->order[] = 'middleware_after';
                return $response;
            }
        });

        Router::get('/middleware-test', function () use (&$executionOrder) {
            $executionOrder[] = 'controller';
            return ApiResponse::success(['order' => $executionOrder]);
        });

        $request = Request::create('/middleware-test', 'GET');
        $response = Router::dispatch($request);
        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame(['middleware_before', 'controller'], $data['order']);
        $this->assertSame(['middleware_before', 'controller', 'middleware_after'], $executionOrder);
    }
}

<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Routing\Router;
use Glueful\Routing\RouteCache;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * A returned ResponseData/CollectionResponse/PaginatedResponse may opt into
 * supplying its own envelope `message` by also implementing HasResponseMessage.
 * When it does NOT, the existing defaults must be byte-identical
 * ('Success' / 'Created successfully' / 'Data retrieved successfully').
 */
class ResponseMessageTest extends TestCase
{
    private Router $router;
    private ContainerInterface $container;

    protected function setUp(): void
    {
        parent::setUp();

        $context = new ApplicationContext(sys_get_temp_dir() . '/respmsg_test_' . uniqid());

        $cache = new RouteCache($context);
        $cache->clear();

        $this->container = new class implements ContainerInterface {
            /** @var array<string,mixed> */
            private array $services = [];

            public function has(string $id): bool
            {
                return array_key_exists($id, $this->services);
            }

            public function get(string $id): mixed
            {
                if ($this->has($id)) {
                    return $this->services[$id];
                }

                throw new class (
                    "Service '" . $id . "' not found"
                ) extends \RuntimeException implements \Psr\Container\NotFoundExceptionInterface {
                };
            }

            public function set(string $id, mixed $service): void
            {
                $this->services[$id] = $service;
            }
        };
        $this->container->set(ApplicationContext::class, $context);
        $this->router = new Router($this->container);
    }

    public function testPlainResponseDataUsesDefaultSuccessMessage(): void
    {
        // @phpstan-ignore-next-line - test container provides set()
        $this->container->set(RmController::class, new RmController());
        $this->router->get('/plain', [RmController::class, 'plain']);

        $response = $this->router->dispatch(Request::create('/plain', 'GET'));

        $this->assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame('Success', $body['message']);
        $this->assertSame(['id' => 7], $body['data']);
    }

    public function testMessagedResponseDataUsesItsOwnMessage(): void
    {
        // @phpstan-ignore-next-line - test container provides set()
        $this->container->set(RmController::class, new RmController());
        $this->router->get('/messaged', [RmController::class, 'messaged']);

        $response = $this->router->dispatch(Request::create('/messaged', 'GET'));

        $this->assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame('Custom message', $body['message']);
        // Critical: the private promoted $message must NOT leak into the data payload.
        $this->assertSame(['id' => 7], $body['data']);
    }

    public function testMessagedResponseDataOverridesCreatedDefault(): void
    {
        // @phpstan-ignore-next-line - test container provides set()
        $this->container->set(RmController::class, new RmController());
        $this->router->post('/messaged-created', [RmController::class, 'messagedCreated']);

        $response = $this->router->dispatch(Request::create('/messaged-created', 'POST'));

        $this->assertSame(201, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame('Custom message', $body['message']);
    }
}

final class RmPlainData implements \Glueful\Http\Contracts\ResponseData
{
    public function __construct(public int $id)
    {
    }
}

final class RmMessagedData implements
    \Glueful\Http\Contracts\ResponseData,
    \Glueful\Http\Contracts\HasResponseMessage
{
    public function __construct(
        public int $id,
        private string $message = 'Custom message',
    ) {
    }
    public function responseMessage(): string
    {
        return $this->message;
    }
}

final class RmController
{
    public function plain(): RmPlainData
    {
        return new RmPlainData(7);
    }
    public function messaged(): RmMessagedData
    {
        return new RmMessagedData(7);
    }
    #[\Glueful\Routing\Attributes\ResponseStatus(201)]
    public function messagedCreated(): RmMessagedData
    {
        return new RmMessagedData(7);
    }
}

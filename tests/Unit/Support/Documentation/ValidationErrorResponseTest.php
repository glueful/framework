<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support\Documentation;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Routing\Router;
use Glueful\Routing\RouteCache;
use Glueful\Support\Documentation\RouteReflectionDocGenerator;
use Glueful\Support\Documentation\SecuritySchemeRegistry;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class VeCreatePostInput implements RequestData
{
    public function __construct(
        #[Rule('required|string|max:200')] public string $title,
    ) {
    }
}

final class VeController
{
    public function store(VeCreatePostInput $input): void
    {
    }

    #[ApiResponse(422, description: 'Custom validation problem')]
    public function storeWithExplicit422(VeCreatePostInput $input): void
    {
    }

    public function index(): void
    {
    }
}

final class ValidationErrorResponseTest extends TestCase
{
    private function makeRouter(): Router
    {
        $context = new ApplicationContext(sys_get_temp_dir() . '/ve_' . uniqid());
        (new RouteCache($context))->clear();
        $container = new class ($context) implements ContainerInterface {
            /** @var array<string,mixed> */
            private array $services;
            public function __construct(ApplicationContext $context)
            {
                $this->services = [ApplicationContext::class => $context];
            }
            public function has(string $id): bool
            {
                return array_key_exists($id, $this->services);
            }
            public function get(string $id): mixed
            {
                if ($this->has($id)) {
                    return $this->services[$id];
                }
                throw new class ("Service '$id' not found")
                    extends \RuntimeException implements \Psr\Container\NotFoundExceptionInterface {
                };
            }
        };
        return new Router($container);
    }

    private function registry(): SecuritySchemeRegistry
    {
        return new SecuritySchemeRegistry([], []);
    }

    public function testRequestDataParamEmits422(): void
    {
        $router = $this->makeRouter();
        $router->post('/posts', [VeController::class, 'store']);
        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        $resp = $paths['/posts']['post']['responses']['422'];
        self::assertSame('Validation failed', $resp['description']);
        $schema = $resp['content']['application/json']['schema'];
        self::assertSame('object', $schema['type']);
        self::assertFalse($schema['properties']['success']['example']);
        self::assertSame('string', $schema['properties']['message']['type']);
        self::assertSame(
            ['type' => 'array', 'items' => ['type' => 'string']],
            $schema['properties']['errors']['additionalProperties']
        );
        self::assertSame(['success', 'message', 'errors'], $schema['required']);
    }

    public function testExplicitApiResponse422Wins(): void
    {
        $router = $this->makeRouter();
        $router->post('/posts2', [VeController::class, 'storeWithExplicit422']);
        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        self::assertSame('Custom validation problem', $paths['/posts2']['post']['responses']['422']['description']);
    }

    public function testNoRequestDataParamMeansNo422(): void
    {
        $router = $this->makeRouter();
        $router->get('/posts3', [VeController::class, 'index']);
        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        self::assertArrayNotHasKey('422', $paths['/posts3']['get']['responses']);
    }
}

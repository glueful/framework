<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support\Documentation;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Routing\RouteCache;
use Glueful\Routing\Router;
use Glueful\Support\Documentation\RouteReflectionDocGenerator;
use Glueful\Support\Documentation\SecuritySchemeRegistry;
use Glueful\Validation\Attributes\FromQuery;
use Glueful\Validation\Attributes\FromRoute;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * @covers \Glueful\Support\Documentation\RouteReflectionDocGenerator
 * @covers \Glueful\Validation\Attributes\FromQuery
 * @covers \Glueful\Validation\Attributes\FromRoute
 */
final class SourceParamDescriptionTest extends TestCase
{
    private function makeRouter(): Router
    {
        $context = new ApplicationContext(sys_get_temp_dir() . '/srcdesc_' . uniqid());
        (new RouteCache($context))->clear();

        $container = new class ($context) implements ContainerInterface {
            /** @var array<string, mixed> */
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
                    extends \RuntimeException
                    implements \Psr\Container\NotFoundExceptionInterface {
                };
            }
        };

        return new Router($container);
    }

    /**
     * @param  list<array<string, mixed>> $params
     * @return array<string, mixed>
     */
    private function param(array $params, string $name, string $in): array
    {
        foreach ($params as $p) {
            if (($p['name'] ?? null) === $name && ($p['in'] ?? null) === $in) {
                return $p;
            }
        }
        self::fail("No $in param named $name");
    }

    public function testFromQueryDescriptionAndExampleSurface(): void
    {
        $router = $this->makeRouter();
        $router->get('/v1/items/{type}', [SrcController::class, 'index']);

        $paths = (new RouteReflectionDocGenerator(new SecuritySchemeRegistry([], [])))->generate($router);
        $params = $paths['/v1/items/{type}']['get']['parameters'];

        $locale = $this->param($params, 'locale', 'query');
        self::assertSame('Content locale to read.', $locale['description']);
        self::assertSame('en', $locale['schema']['example']);

        $type = $this->param($params, 'type', 'path');
        self::assertSame('Content type slug.', $type['description']);
        self::assertSame('articles', $type['schema']['example']);
    }

    public function testBareFromQueryStillEmitsEmptyDescription(): void
    {
        $router = $this->makeRouter();
        $router->get('/v1/items/{type}', [SrcController::class, 'index']);

        $paths = (new RouteReflectionDocGenerator(new SecuritySchemeRegistry([], [])))->generate($router);
        $cursor = $this->param($paths['/v1/items/{type}']['get']['parameters'], 'cursor', 'query');

        self::assertSame('', $cursor['description']);
    }
}

final class SrcQuery implements RequestData
{
    public function __construct(
        #[FromRoute(description: 'Content type slug.', example: 'articles')]
        #[Rule('string')]
        public readonly string $type = '',

        #[FromQuery(description: 'Content locale to read.', example: 'en')]
        #[Rule('string')]
        public readonly ?string $locale = null,

        #[FromQuery]
        #[Rule('string')]
        public readonly ?string $cursor = null,
    ) {
    }
}

final class SrcController
{
    public function index(SrcQuery $query): void
    {
    }
}

<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support\Documentation;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Routing\Attributes\QueryParam;
use Glueful\Routing\RouteCache;
use Glueful\Routing\Router;
use Glueful\Support\Documentation\RouteReflectionDocGenerator;
use Glueful\Support\Documentation\SecuritySchemeRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * @covers \Glueful\Support\Documentation\RouteReflectionDocGenerator
 * @covers \Glueful\Routing\Attributes\QueryParam
 */
final class QueryParamAttributeTest extends TestCase
{
    private function makeRouter(?ApplicationContext $context = null): Router
    {
        $context ??= new ApplicationContext(sys_get_temp_dir() . '/queryparam_' . uniqid());

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

    private function registry(): SecuritySchemeRegistry
    {
        return new SecuritySchemeRegistry([], []);
    }

    /**
     * @param  list<array<string, mixed>> $params
     * @return list<array<string, mixed>>
     */
    private function paramsWhere(array $params, string $name, string $in): array
    {
        return array_values(array_filter(
            $params,
            static fn (array $p): bool => ($p['name'] ?? null) === $name && ($p['in'] ?? null) === $in,
        ));
    }

    public function testQueryParamAttributesBecomeQueryParameters(): void
    {
        $router = $this->makeRouter();
        $router->get('/v1/reports', [QpController::class, 'listing']);

        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);
        $params = $paths['/v1/reports']['get']['parameters'];

        $days = $this->paramsWhere($params, 'days', 'query');
        self::assertCount(1, $days);
        self::assertSame('integer', $days[0]['schema']['type']);
        self::assertSame('Window in days', $days[0]['description']);
        self::assertFalse($days[0]['required']);

        $status = $this->paramsWhere($params, 'status', 'query');
        self::assertCount(1, $status);
        self::assertSame(['active', 'paused'], $status[0]['schema']['enum']);
    }

    /**
     * Duplicate policy: an explicit #[QueryParam('fields', …)] OVERRIDES the
     * field-selection-generated `fields` query param, leaving exactly one.
     */
    public function testQueryParamOverridesFieldSelectionParam(): void
    {
        $router = $this->makeRouter();
        $route = $router->get('/v1/posts', [QpController::class, 'overrideFields']);
        // Drives buildFieldSelectionParameters() to emit a `fields` query param.
        $route->setFieldsConfig(['allowed' => ['id', 'title'], 'strict' => true]);

        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);
        $params = $paths['/v1/posts']['get']['parameters'];

        $fields = $this->paramsWhere($params, 'fields', 'query');
        self::assertCount(1, $fields, 'Explicit #[QueryParam] must replace, not duplicate, the generated param.');
        self::assertSame('override', $fields[0]['description']);
    }
}

/**
 * App-namespaced controller stub exercising #[QueryParam].
 */
final class QpController
{
    #[QueryParam('days', 'integer', description: 'Window in days', required: false)]
    #[QueryParam('status', enum: ['active', 'paused'])]
    public function listing(): void
    {
    }

    #[QueryParam('fields', description: 'override')]
    public function overrideFields(): void
    {
    }
}

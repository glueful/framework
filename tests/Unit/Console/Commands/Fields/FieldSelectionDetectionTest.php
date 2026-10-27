<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Console\Commands\Fields;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Console\Commands\Fields\AnalyzeCommand;
use Glueful\Console\Commands\Fields\ValidateCommand;
use Glueful\Routing\Route;
use Glueful\Routing\RouteCache;
use Glueful\Routing\Router;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionMethod;

/**
 * The router auto-attaches `field_selection` at pipeline build for any route carrying #[Fields]
 * config, so the middleware never appears in Route::getMiddleware(). fields:analyze and
 * fields:validate must treat a route as field-selection-enabled when it has the middleware OR
 * stored fields config — otherwise every #[Fields] route is reported as lacking field selection.
 */
final class FieldSelectionDetectionTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();

        $context = new ApplicationContext(sys_get_temp_dir() . '/fields_detect_test_' . uniqid('', true));
        (new RouteCache($context))->clear();

        $container = new class ($context) implements ContainerInterface {
            public function __construct(private ApplicationContext $context)
            {
            }

            public function has(string $id): bool
            {
                return $id === ApplicationContext::class;
            }

            public function get(string $id): mixed
            {
                if ($id === ApplicationContext::class) {
                    return $this->context;
                }

                throw new class (
                    "Service '" . $id . "' not found"
                ) extends \RuntimeException implements \Psr\Container\NotFoundExceptionInterface {
                };
            }
        };

        $this->router = new Router($container);
    }

    public function testRouteHasFieldSelectionHelper(): void
    {
        $configured = $this->router->get('/a', fn() => null)->setFieldsConfig(['allowed' => ['id']]);
        $bare = $this->router->get('/b', fn() => null)->setFieldsConfig([]);
        $alias = $this->router->get('/c', fn() => null)->middleware('field_selection');
        $aliasWithParams = $this->router->get('/d', fn() => null)->middleware('field_selection:strict');
        $fqcn = $this->router->get('/e', fn() => null)
            ->middleware(\Glueful\Routing\Middleware\FieldSelectionMiddleware::class);
        $none = $this->router->get('/f', fn() => null)->middleware('auth');

        $this->assertTrue($configured->hasFieldSelection());
        $this->assertTrue($bare->hasFieldSelection());
        $this->assertTrue($alias->hasFieldSelection());
        $this->assertTrue($aliasWithParams->hasFieldSelection());
        $this->assertTrue($fqcn->hasFieldSelection());
        $this->assertFalse($none->hasFieldSelection());
    }

    public function testAnalyzeSeesFieldsConfigWithoutExplicitMiddleware(): void
    {
        $route = $this->router->get('/api/posts', fn() => null)
            ->setFieldsConfig(['allowed' => ['id', 'title'], 'strict' => true]);
        $plain = $this->router->get('/api/plain', fn() => null);

        $method = new ReflectionMethod(AnalyzeCommand::class, 'analyzeRoutes');
        /** @var array<int, array<string, mixed>> $analysis */
        $analysis = $method->invoke(new AnalyzeCommand(), [$route, $plain], []);

        $this->assertTrue($analysis[0]['has_field_selection']);
        $this->assertFalse($analysis[1]['has_field_selection']);
    }

    public function testValidateSeesFieldsConfigWithoutExplicitMiddleware(): void
    {
        $route = $this->router->get('/api/posts', fn() => null)
            ->setFieldsConfig(['allowed' => ['id', 'title'], 'strict' => true]);

        $this->assertTrue($this->validate($route)['has_field_selection']);
    }

    public function testValidateStillHonoursExplicitMiddleware(): void
    {
        $route = $this->router->get('/api/posts', fn() => null)->middleware('field_selection');

        $this->assertTrue($this->validate($route)['has_field_selection']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validate(Route $route): array
    {
        $method = new ReflectionMethod(ValidateCommand::class, 'validateRoute');
        /** @var array<string, mixed> $result */
        $result = $method->invoke(new ValidateCommand(), $route, false, false);

        return $result;
    }
}

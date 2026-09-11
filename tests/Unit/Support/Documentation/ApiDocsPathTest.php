<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support\Documentation;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\ConfigurationLoader;
use Glueful\Routing\RouteCache;
use Glueful\Routing\Router;
use Glueful\Support\Documentation\ApiDocsPath;
use Glueful\Support\Documentation\DocumentationUIGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The API reference used to be hard-mounted at `/docs` — the route group, the spec URL written
 * into every generated UI page and the printed "visit" URL each carried their own literal — so
 * it collided with any application's own documentation. One setting
 * (`documentation.route_prefix`, env `API_DOCS_PATH`, default `/api-docs`) now decides the path
 * and everything derives from it.
 */
final class ApiDocsPathTest extends TestCase
{
    /** @param array<string,mixed> $documentation the `documentation` config file's contents */
    private function context(array $documentation = []): ApplicationContext
    {
        $loader = new class (['documentation' => $documentation]) extends ConfigurationLoader {
            /** @param array<string,mixed> $file */
            public function __construct(private readonly array $file)
            {
            }

            public function loadConfig(string $name): array
            {
                return $this->file[$name] ?? [];
            }
        };
        $context = new ApplicationContext(sys_get_temp_dir() . '/apidocs_' . uniqid(), 'testing');
        $context->setConfigLoader($loader);

        return $context;
    }

    public function testDefaultsToApiDocsAndNormalisesSlashes(): void
    {
        self::assertSame('/api-docs', ApiDocsPath::resolve(null));
        self::assertSame('/api-docs', ApiDocsPath::resolve($this->context()));
        self::assertSame('/reference', ApiDocsPath::resolve($this->context(['route_prefix' => 'reference/'])));
        self::assertSame('/api/v2/docs', ApiDocsPath::resolve($this->context(['route_prefix' => '/api/v2/docs/'])));
        // Empty or root means "no opinion": the default, never the site root.
        self::assertSame('/api-docs', ApiDocsPath::resolve($this->context(['route_prefix' => ''])));
        self::assertSame('/api-docs', ApiDocsPath::resolve($this->context(['route_prefix' => '/'])));
    }

    /** @return list<array{string}> */
    public static function uis(): array
    {
        return [['scalar'], ['swagger-ui'], ['redoc']];
    }

    /** @dataProvider uis */
    public function testEveryGeneratedUiLoadsTheSpecFromTheConfiguredPath(string $ui): void
    {
        $default = $this->render($ui, $this->context());
        self::assertStringContainsString('/api-docs/openapi.json', $default);
        self::assertStringNotContainsString('/docs/openapi.json', $default);

        $custom = $this->render($ui, $this->context(['route_prefix' => '/reference']));
        self::assertStringContainsString('/reference/openapi.json', $custom);
        self::assertStringNotContainsString('/api-docs/openapi.json', $custom);
    }

    public function testTheRouteFileMountsAtTheConfiguredPath(): void
    {
        $router = $this->routerFor($this->context());
        self::assertNotNull($router->match(Request::create('/api-docs/openapi.json')));
        self::assertNotNull($router->match(Request::create('/api-docs')));
        self::assertNull($router->match(Request::create('/docs/openapi.json')), '/docs is the application\'s');

        $router = $this->routerFor($this->context(['route_prefix' => '/docs']));
        self::assertNotNull($router->match(Request::create('/docs/openapi.json')), 'the old address is one setting away');
    }

    private function render(string $ui, ApplicationContext $context): string
    {
        $out = sys_get_temp_dir() . '/apidocs_ui_' . uniqid() . '.html';
        (new DocumentationUIGenerator($context))->generate($ui, $out);
        $html = (string) file_get_contents($out);
        @unlink($out);

        return $html;
    }

    private function routerFor(ApplicationContext $context): Router
    {
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
                throw new class ("Service '$id' not found") extends \RuntimeException implements
                    \Psr\Container\NotFoundExceptionInterface {
                };
            }
        };
        $router = new Router($container);
        // The real route file, loaded the way RouteManifest loads it ($router in scope).
        (static function (Router $router): void {
            require dirname(__DIR__, 4) . '/routes/docs.php';
        })($router);

        return $router;
    }
}

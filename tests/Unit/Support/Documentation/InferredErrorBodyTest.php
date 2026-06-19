<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support\Documentation;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Routing\RouteCache;
use Glueful\Routing\Router;
use Glueful\Support\Documentation\RouteReflectionDocGenerator;
use Glueful\Support\Documentation\SecuritySchemeRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * @covers \Glueful\Support\Documentation\RouteReflectionDocGenerator
 */
final class InferredErrorBodyTest extends TestCase
{
    private function makeRouter(?ApplicationContext $context = null): Router
    {
        $context ??= new ApplicationContext(sys_get_temp_dir() . '/inferr_' . uniqid());
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

    private function securedRegistry(): SecuritySchemeRegistry
    {
        return new SecuritySchemeRegistry(
            ['BearerAuth' => ['type' => 'http', 'scheme' => 'bearer']],
            middlewareMap: ['auth' => ['BearerAuth']],
        );
    }

    public function testSecuredRouteErrorResponsesGainAJsonBody(): void
    {
        $router = $this->makeRouter();
        $router->get('/v1/secure', [ErrController::class, 'plain'])->middleware('auth');

        $paths = (new RouteReflectionDocGenerator($this->securedRegistry()))->generate($router);
        $responses = $paths['/v1/secure']['get']['responses'];

        foreach (['401', '403'] as $status) {
            self::assertArrayHasKey($status, $responses);
            $schema = $responses[$status]['content']['application/json']['schema'] ?? null;
            self::assertIsArray($schema, "$status must carry a JSON body schema");
            self::assertSame(['success', 'message'], $schema['required']);
            self::assertFalse($schema['properties']['success']['example']);
        }
    }

    public function testRateLimitedRouteKeepsHeadersAndGainsBody(): void
    {
        $router = $this->makeRouter();
        $router->get('/v1/limited', [ErrController::class, 'plain'])->rateLimit(60, 1);

        $paths = (new RouteReflectionDocGenerator(new SecuritySchemeRegistry([], [])))->generate($router);
        $r429 = $paths['/v1/limited']['get']['responses']['429'];

        self::assertArrayHasKey('Retry-After', $r429['headers']);
        $content = $r429['content'] ?? null;
        self::assertIsArray($content);
        self::assertArrayHasKey('application/json', $content);
    }

    public function testAlwaysConfigSeedsAFiveHundred(): void
    {
        $context = new ApplicationContext(sys_get_temp_dir() . '/inferr_500_' . uniqid());
        $context->mergeConfigDefaults('documentation', ['errors' => ['always' => [500]]]);
        $router = $this->makeRouter($context);
        $router->get('/v1/anything', [ErrController::class, 'plain']);

        $paths = (new RouteReflectionDocGenerator(new SecuritySchemeRegistry([], []), $context))->generate($router);
        $responses = $paths['/v1/anything']['get']['responses'];

        self::assertArrayHasKey('500', $responses);
        self::assertArrayHasKey('application/json', $responses['500']['content']);
    }

    public function testAlwaysConfigIgnoresNonErrorStatuses(): void
    {
        $context = new ApplicationContext(sys_get_temp_dir() . '/inferr_200_' . uniqid());
        $context->mergeConfigDefaults('documentation', ['errors' => ['always' => [200, 500]]]);
        $router = $this->makeRouter($context);
        $router->get('/v1/anything', [ErrController::class, 'plain']);

        $paths = (new RouteReflectionDocGenerator(new SecuritySchemeRegistry([], []), $context))->generate($router);
        $responses = $paths['/v1/anything']['get']['responses'];

        self::assertSame('Successful response', $responses['200']['description']);
        self::assertArrayNotHasKey('content', $responses['200']);
        self::assertArrayHasKey('500', $responses);
    }

    public function testConfiguredSchemaIsReflectedIntoErrorBody(): void
    {
        $context = new ApplicationContext(sys_get_temp_dir() . '/inferr_schema_' . uniqid());
        $context->mergeConfigDefaults('documentation', ['errors' => ['schema' => FixtureError::class]]);
        $router = $this->makeRouter($context);
        $router->get('/v1/schema-error', [ErrController::class, 'plain'])->middleware('auth');

        $paths = (new RouteReflectionDocGenerator($this->securedRegistry(), $context))->generate($router);
        $schema = $paths['/v1/schema-error']['get']['responses']['401']['content']['application/json']['schema'] ?? null;

        self::assertIsArray($schema);
        self::assertArrayHasKey('ok', $schema['properties']);
        self::assertArrayHasKey('reason', $schema['properties']);
        self::assertArrayNotHasKey('message', $schema['properties']);
    }

    public function testExplicitApiResponseStillOverridesTheDefault(): void
    {
        $router = $this->makeRouter();
        $router->get('/v1/custom', [ErrController::class, 'overrides'])->middleware('auth');

        $paths = (new RouteReflectionDocGenerator($this->securedRegistry()))->generate($router);
        $r403 = $paths['/v1/custom']['get']['responses']['403'];

        self::assertSame('Custom forbidden wording.', $r403['description']);
    }
}

final class FixtureError
{
    public bool $ok;
    public string $reason;
}

final class ErrController
{
    public function plain(): void
    {
    }

    #[ApiResponse(403, description: 'Custom forbidden wording.')]
    public function overrides(): void
    {
    }
}

<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support\Documentation;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Controllers\DocsController;
use Glueful\Routing\RouteCache;
use Glueful\Routing\Router;
use Glueful\Support\Documentation\RouteReflectionDocGenerator;
use Glueful\Support\Documentation\SecuritySchemeRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Stage 2.5 characterization: the reflect generator, fed the real
 * {@see DocsController} handlers + their new doc attributes, must emit an
 * OpenAPI operation for every docs route that is >= the legacy comment-generator
 * spec (routes/docs.php docblocks).
 *
 * This pins "reflect >= comment" by construction: each docblock's
 * @summary/@description/@tag/@response was transcribed into
 * #[ApiOperation]/#[ApiResponse], and this test asserts the reflect output
 * carries that migrated information.
 *
 * The routes serve binary/text file responses (BinaryFileResponse) — no DTO
 * needed. The #[ApiResponse] body: escape hatch is used:
 *  - index (GET /docs):          body: 'text' in text/html  → {type: string}
 *  - openapi (GET /docs/openapi.json): body: 'object' in application/json → {type: object}
 *
 * The attributes are DOC-ONLY (read by the generator, never by the
 * router/dispatch), so registering the routes here does not exercise — and
 * cannot change — docs runtime.
 *
 * @covers \Glueful\Support\Documentation\RouteReflectionDocGenerator
 */
final class DocsReflectDocsTest extends TestCase
{
    private function makeRouter(): Router
    {
        $context = new ApplicationContext(sys_get_temp_dir() . '/docsreflect_' . uniqid());

        // Ensure no stale compiled route cache leaks across tests.
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
     * Register the docs routes exactly as routes/docs.php does (prefix + paths),
     * pointing at the real DocsController handlers.
     */
    private function registerDocsRoutes(Router $router): void
    {
        $router->group(['prefix' => '/docs'], function (Router $router): void {
            $router->get('/', [DocsController::class, 'index']);
            $router->get('/openapi.json', [DocsController::class, 'openapi']);
        });
    }

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function generate(): array
    {
        $router = $this->makeRouter();
        $this->registerDocsRoutes($router);

        $registry = new SecuritySchemeRegistry(
            ['BearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT']],
            ['auth' => ['BearerAuth']],
        );

        return (new RouteReflectionDocGenerator($registry))->generate($router);
    }

    // -------------------------------------------------------------------------
    // Index (GET /docs)
    // -------------------------------------------------------------------------

    public function testIndexOperationCarriesMigratedDocs(): void
    {
        $op = $this->generate()['/docs']['get'];

        self::assertSame('API Documentation UI', $op['summary']);
        self::assertStringContainsString('Interactive API documentation interface', $op['description']);
        self::assertSame(['Documentation'], $op['tags']);
    }

    public function testIndexHasTextHtmlSuccessResponse(): void
    {
        $op = $this->generate()['/docs']['get'];

        // 200 text/html body documented via body: 'text' → {type: string}
        self::assertArrayHasKey('200', $op['responses']);
        self::assertArrayHasKey('content', $op['responses']['200']);

        $schema = $op['responses']['200']['content']['text/html']['schema'];
        self::assertEquals(['type' => 'string'], $schema);

        // Must NOT have a JSON envelope for a text/html response
        self::assertArrayNotHasKey('application/json', $op['responses']['200']['content']);
    }

    public function testIndexHas404Response(): void
    {
        $op = $this->generate()['/docs']['get'];

        // 404 path documented (disabled or not generated)
        self::assertArrayHasKey('404', $op['responses']);
        self::assertStringContainsString(
            'Documentation disabled or not generated',
            $op['responses']['404']['description'],
        );
    }

    // -------------------------------------------------------------------------
    // OpenAPI specification (GET /docs/openapi.json)
    // -------------------------------------------------------------------------

    public function testOpenapiOperationCarriesMigratedDocs(): void
    {
        $op = $this->generate()['/docs/openapi.json']['get'];

        self::assertSame('OpenAPI Specification', $op['summary']);
        self::assertStringContainsString('OpenAPI/Swagger specification', $op['description']);
        self::assertSame(['Documentation'], $op['tags']);
    }

    public function testOpenapiHasJsonObjectSuccessResponse(): void
    {
        $op = $this->generate()['/docs/openapi.json']['get'];

        // 200 application/json body documented via body: 'object' → {type: object}
        self::assertArrayHasKey('200', $op['responses']);
        self::assertArrayHasKey('content', $op['responses']['200']);

        $schema = $op['responses']['200']['content']['application/json']['schema'];
        self::assertEquals(['type' => 'object'], $schema);
    }

    public function testOpenapiHas404Response(): void
    {
        $op = $this->generate()['/docs/openapi.json']['get'];

        // 404 path documented (not generated or disabled)
        self::assertArrayHasKey('404', $op['responses']);
        self::assertStringContainsString(
            'Specification not generated or disabled',
            $op['responses']['404']['description'],
        );
    }
}

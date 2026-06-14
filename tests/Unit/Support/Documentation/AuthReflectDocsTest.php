<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support\Documentation;

use Glueful\Auth\DTOs\CsrfTokenData;
use Glueful\Auth\DTOs\LoginInputData;
use Glueful\Auth\DTOs\LoginResultData;
use Glueful\Auth\DTOs\RefreshedPermissionsData;
use Glueful\Auth\DTOs\ValidatedTokenData;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Controllers\AuthController;
use Glueful\DTOs\RefreshedTokenData;
use Glueful\Routing\RouteCache;
use Glueful\Routing\Router;
use Glueful\Support\Documentation\ClassSchemaReflector;
use Glueful\Support\Documentation\RouteReflectionDocGenerator;
use Glueful\Support\Documentation\SecuritySchemeRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Stage 2.1 characterization: the reflect generator, fed the real {@see AuthController}
 * handlers + their new doc attributes/DTOs, must emit an OpenAPI operation for every
 * auth route that is >= the legacy comment-generator spec (routes/auth.php docblocks).
 *
 * This pins "reflect >= comment" by construction: each docblock's
 * @summary/@description/@tag/@requestBody/@response was transcribed into
 * #[ApiOperation]/#[ApiRequestBody]/#[ApiResponse] (+ doc-only DTOs), and this test
 * asserts the reflect output carries that migrated information.
 *
 * The attributes are DOC-ONLY (read by the generator, never by the router/dispatch),
 * so registering the routes here does not exercise — and cannot change — auth runtime.
 *
 * @covers \Glueful\Support\Documentation\RouteReflectionDocGenerator
 */
final class AuthReflectDocsTest extends TestCase
{
    private function makeRouter(): Router
    {
        $context = new ApplicationContext(sys_get_temp_dir() . '/authreflect_' . uniqid());

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
     * Register the auth routes exactly as routes/auth.php does (paths + middleware),
     * pointing at the real AuthController handlers.
     */
    private function registerAuthRoutes(Router $router): void
    {
        $router->group(['prefix' => '/auth'], function (Router $router): void {
            $router->post('/login', [AuthController::class, 'login'])
                ->middleware('rate_limit:5,60');
            $router->post('/validate-token', [AuthController::class, 'validateToken'])
                ->middleware(['auth']);
            $router->post('/refresh-token', [AuthController::class, 'refreshToken']);
            $router->post('/logout', [AuthController::class, 'logout'])
                ->middleware(['auth']);
            $router->post('/refresh-permissions', [AuthController::class, 'refreshPermissions'])
                ->middleware(['auth']);
        });

        $router->get('/csrf-token', [AuthController::class, 'csrfToken']);
    }

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function generate(): array
    {
        $router = $this->makeRouter();
        $this->registerAuthRoutes($router);

        // Framework-namespaced routes (Glueful\Controllers\AuthController) are included
        // because documentation.sources.include_framework_routes defaults to true.
        $registry = new SecuritySchemeRegistry(
            ['BearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT']],
            ['auth' => ['BearerAuth']],
        );

        return (new RouteReflectionDocGenerator($registry))->generate($router);
    }

    public function testLoginOperationCarriesMigratedDocs(): void
    {
        $op = $this->generate()['/auth/login']['post'];

        self::assertSame('User Login', $op['summary']);
        self::assertStringContainsString('Authenticates a user with username/email and password', $op['description']);
        self::assertSame(['Authentication'], $op['tags']);

        // Request body: doc-only LoginInputData schema (login stays manual at runtime).
        $jsonSchema = $op['requestBody']['content']['application/json']['schema'];
        self::assertEquals(ClassSchemaReflector::toSchema(LoginInputData::class), $jsonSchema);
        // Structural pins so the test fails if the reflector ever falls back to a
        // bare {type:object} (which would still equal itself above).
        self::assertSame('object', $jsonSchema['type']);
        self::assertSame('string', $jsonSchema['properties']['username']['type']);
        self::assertSame('string', $jsonSchema['properties']['password']['type']);

        // 200 success enveloped around LoginResultData.
        self::assertSame('Login successful', $op['responses']['200']['description']);
        $data = $op['responses']['200']['content']['application/json']['schema']['properties']['data'];
        self::assertEquals(ClassSchemaReflector::toSchema(LoginResultData::class), $data);
        self::assertSame('string', $data['properties']['access_token']['type']);

        // Documented error statuses.
        self::assertSame('Invalid credentials', $op['responses']['401']['description']);
        self::assertSame('Missing required fields', $op['responses']['400']['description']);
        self::assertArrayNotHasKey('content', $op['responses']['401']);
    }

    public function testValidateTokenOperationCarriesMigratedDocs(): void
    {
        $op = $this->generate()['/auth/validate-token']['post'];

        self::assertSame('Validate Token', $op['summary']);
        self::assertStringContainsString('Validates the current authentication token', $op['description']);
        self::assertSame(['Authentication'], $op['tags']);

        self::assertSame('Token is valid', $op['responses']['200']['description']);
        $data = $op['responses']['200']['content']['application/json']['schema']['properties']['data'];
        self::assertEquals(ClassSchemaReflector::toSchema(ValidatedTokenData::class), $data);

        self::assertSame('Invalid or expired token', $op['responses']['401']['description']);
    }

    public function testRefreshTokenOperationCarriesMigratedDocs(): void
    {
        $op = $this->generate()['/auth/refresh-token']['post'];

        self::assertSame('Refresh Token', $op['summary']);
        self::assertStringContainsString('Generates new access token using a valid refresh token', $op['description']);
        self::assertSame(['Authentication'], $op['tags']);

        // Request body auto-derived from the hydrating RefreshTokenData param. The
        // auto-derived schema is a SUPERSET of the plain reflected DTO (it also reads
        // the #[Rule] to mark `refresh_token` required), so assert its substance.
        $jsonSchema = $op['requestBody']['content']['application/json']['schema'];
        self::assertSame('object', $jsonSchema['type']);
        self::assertArrayHasKey('refresh_token', $jsonSchema['properties']);
        self::assertSame('string', $jsonSchema['properties']['refresh_token']['type']);
        self::assertContains('refresh_token', $jsonSchema['required']);

        // 200 auto-derived from the RefreshedTokenData return type (enveloped).
        $data = $op['responses']['200']['content']['application/json']['schema']['properties']['data'];
        self::assertEquals(ClassSchemaReflector::toSchema(RefreshedTokenData::class), $data);

        self::assertSame('Invalid refresh token', $op['responses']['401']['description']);
        self::assertSame('Missing refresh token', $op['responses']['400']['description']);
    }

    public function testLogoutOperationCarriesMigratedDocs(): void
    {
        $op = $this->generate()['/auth/logout']['post'];

        self::assertSame('User Logout', $op['summary']);
        self::assertStringContainsString('Invalidates the current authentication token', $op['description']);
        self::assertSame(['Authentication'], $op['tags']);

        self::assertSame('Logout successful', $op['responses']['200']['description']);
        self::assertSame('Unauthorized - not logged in', $op['responses']['401']['description']);
    }

    public function testRefreshPermissionsOperationCarriesMigratedDocs(): void
    {
        $op = $this->generate()['/auth/refresh-permissions']['post'];

        self::assertSame('Refresh User Permissions', $op['summary']);
        self::assertStringContainsString(
            'Updates the session with fresh user permissions and returns a new token',
            $op['description'],
        );
        self::assertSame(['Authentication'], $op['tags']);

        self::assertSame('Permissions refreshed successfully', $op['responses']['200']['description']);
        $data = $op['responses']['200']['content']['application/json']['schema']['properties']['data'];
        self::assertEquals(ClassSchemaReflector::toSchema(RefreshedPermissionsData::class), $data);

        self::assertSame('Unauthorized - invalid token', $op['responses']['401']['description']);
        self::assertSame('Missing or invalid token', $op['responses']['400']['description']);
    }

    public function testCsrfTokenOperationCarriesMigratedDocs(): void
    {
        $op = $this->generate()['/csrf-token']['get'];

        self::assertSame('Get CSRF Token', $op['summary']);
        self::assertStringContainsString(
            'Retrieves a CSRF token for form and AJAX request protection',
            $op['description'],
        );
        self::assertSame(['Security'], $op['tags']);

        self::assertSame('CSRF token retrieved successfully', $op['responses']['200']['description']);
        $data = $op['responses']['200']['content']['application/json']['schema']['properties']['data'];
        self::assertEquals(ClassSchemaReflector::toSchema(CsrfTokenData::class), $data);

        self::assertSame('Failed to generate CSRF token', $op['responses']['500']['description']);
    }
}

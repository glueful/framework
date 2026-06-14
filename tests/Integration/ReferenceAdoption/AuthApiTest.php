<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\ReferenceAdoption;

use Glueful\Application;
use Glueful\Auth\AuthenticationService;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Container;
use Glueful\Controllers\AuthController;
use Glueful\Framework;
use Glueful\Routing\RouteManifest;
use Glueful\Routing\Router;
use Glueful\Validation\ValidationException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Characterization + reference-adoption guard for {@see AuthController}'s typed-DTO migration
 * (phased "adopt typed DTOs as a reference example" work).
 *
 * What this pins:
 *  - refresh-token REQUEST is migrated to {@see \Glueful\DTOs\RefreshTokenData}
 *    and RESPONSE to {@see \Glueful\DTOs\RefreshedTokenData} — the enveloped body
 *    (success flag, message, and every `data` key) must stay byte-identical to the
 *    pre-migration `Response::success($result, 'Token refreshed successfully')`.
 *  - A missing `refresh_token` already produced a 422 (the controller threw
 *    ValidationException::forField BEFORE the migration), so the #[Rule]-driven
 *    422 is NOT a status change — it preserves the contract.
 *
 * login() is NOT migrated: its body is POLYMORPHIC — besides
 * {username,password,provider?,remember?} it also accepts a token/api_key
 * shortcut body (AuthController::login Route 1) that bypasses the 2FA gate. A
 * single fixed-shape RequestData DTO cannot express that union, so login's
 * request stays manual (and its response always stays manual — 2FA branch /
 * LoginResponseShaper). It is therefore intentionally not exercised here.
 *
 * The auth-service is swapped for a deterministic double so the test exercises the
 * controller + router envelope path (the part being migrated) without seeding a
 * full refresh-token/session chain end-to-end (covered by glueful/users' suite).
 */
final class AuthApiTest extends TestCase
{
    private string $appPath;
    private Application $app;
    private ApplicationContext $context;
    private Router $router;

    /** The fixed result the auth-service double returns from refreshTokens(). */
    public const REFRESH_RESULT = [
        'access_token'  => 'new-access-token-abc',
        'refresh_token' => 'new-refresh-token-def',
        'expires_in'    => 3600,
        'token_type'    => 'Bearer',
        'user'          => [
            'id'         => 'u-amy00000001',
            'email'      => 'amy@x.test',
            'username'   => 'amy',
            'updated_at' => 1700000000,
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        RouteManifest::reset();
        $this->bootFramework();
        $this->overrideAuthService();
        $this->router = $this->app->getContainer()->get(Router::class);
        $this->router->post('/test/refresh-token', [AuthController::class, 'refreshToken']);
    }

    protected function tearDown(): void
    {
        if (isset($this->appPath) && is_dir($this->appPath)) {
            $this->recursiveRemoveDirectory($this->appPath);
        }
        parent::tearDown();
    }

    public function test_refresh_token_returns_byte_identical_success_envelope(): void
    {
        $response = $this->router->dispatch($this->jsonRequest('/test/refresh-token', [
            'refresh_token' => 'valid-refresh-token',
        ]));

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true);

        // The exact pre-migration envelope: Response::success($result, 'Token refreshed successfully').
        self::assertSame([
            'success' => true,
            'message' => 'Token refreshed successfully',
            'data'    => self::REFRESH_RESULT,
        ], $body);
    }

    public function test_refresh_token_missing_field_is_422(): void
    {
        // Pre-migration the controller threw ValidationException::forField('refresh_token', ...)
        // which is already an HTTP 422 — the #[Rule] guard preserves that, no status change.
        // Assert the 422 STATUS (not just the class) so this stays meaningful even if the
        // router later converts the exception into a Response rather than propagating it.
        try {
            $this->router->dispatch($this->jsonRequest('/test/refresh-token', []));
            self::fail('Expected a ValidationException (HTTP 422) for a missing refresh_token.');
        } catch (ValidationException $e) {
            self::assertSame(422, $e->getStatusCode());
        }
    }

    /**
     * Swap AuthenticationService for a deterministic double BEFORE the controller
     * resolves it. The controller calls container($context)->get(AuthenticationService::class)
     * in its constructor; overriding the definition makes get() return the double
     * (the binding is not yet singleton-cached at this point).
     */
    private function overrideAuthService(): void
    {
        $container = $this->app->getContainer();
        self::assertInstanceOf(Container::class, $container);

        $double = new class (context: $this->context) extends AuthenticationService {
            /** @return array<string, mixed>|null */
            public function refreshTokens(string $refreshToken): ?array
            {
                return AuthApiTest::REFRESH_RESULT;
            }
        };

        $container->load([AuthenticationService::class => $double]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(string $uri, array $body): Request
    {
        return Request::create(
            $uri,
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($body),
        );
    }

    private function bootFramework(): void
    {
        $this->appPath = sys_get_temp_dir() . '/glueful-refadopt-' . uniqid();
        $cfg = $this->appPath . '/config';
        mkdir($cfg, 0755, true);
        file_put_contents($cfg . '/app.php', "<?php\nreturn ['name'=>'T','env'=>'testing','debug'=>true];");
        file_put_contents(
            $cfg . '/database.php',
            "<?php\nreturn ['engine'=>'sqlite','sqlite'=>['primary'=>':memory:'],'pooling'=>['enabled'=>false]];"
        );
        file_put_contents(
            $cfg . '/cache.php',
            "<?php\nreturn ['enabled'=>true,'default'=>'array','stores'=>['array'=>['driver'=>'array']]];"
        );
        file_put_contents($cfg . '/security.php', "<?php\nreturn ['csrf'=>['enabled'=>false]];");
        file_put_contents($cfg . '/session.php', "<?php\nreturn ['jwt_key'=>'test'];");

        $this->app = Framework::create($this->appPath)->boot(allowReboot: true);
        $this->context = $this->app->getContainer()->get(ApplicationContext::class);
    }

    private function recursiveRemoveDirectory(string $dir): void
    {
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->recursiveRemoveDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}

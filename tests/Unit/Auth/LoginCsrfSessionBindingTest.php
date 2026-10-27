<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Auth;

use Glueful\Auth\AuthenticationManager;
use Glueful\Auth\Interfaces\AuthenticationProviderInterface;
use Glueful\Auth\Interfaces\SessionStoreInterface;
use Glueful\Auth\LoginResponseShaper;
use Glueful\Auth\RefreshTokenStore;
use Glueful\Auth\SessionCacheManager;
use Glueful\Auth\TokenManager;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\Drivers\ArrayCacheDriver;
use Glueful\Container\Container;
use Glueful\Container\Definition\ValueDefinition;
use Glueful\Database\Connection;
use Glueful\Events\EventDispatcher;
use Glueful\Events\EventService;
use Glueful\Events\ListenerProvider;
use Glueful\Http\Exceptions\Domain\SecurityException;
use Glueful\Routing\Middleware\CSRFMiddleware;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The login-issued CSRF token must bind to the session the login created, so it validates on
 * the first session-authenticated write. createUserSession() returned no session id, so the
 * shaper fell back to the anonymous request fingerprint (and, built with no container, stored
 * the token nowhere).
 */
final class LoginCsrfSessionBindingTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('CSRF_PROTECTION_ENABLED');
        unset($_ENV['CSRF_PROTECTION_ENABLED'], $_SERVER['CSRF_PROTECTION_ENABLED']);
    }

    public function testCreateUserSessionExposesTheCreatedSessionsId(): void
    {
        $storedSessionId = null;
        $store = $this->createMock(SessionStoreInterface::class);
        $store->method('getAccessTtl')->willReturn(900);
        $store->method('getRefreshTtl')->willReturn(86400);
        $store->method('create')->willReturnCallback(
            static function (array $user) use (&$storedSessionId): bool {
                $storedSessionId = $user['session_id'];
                return true;
            }
        );

        $refreshStore = $this->createMock(RefreshTokenStore::class);
        $refreshStore->method('issue')->willReturn(true);

        $provider = $this->createMock(AuthenticationProviderInterface::class);
        $provider->method('generateTokens')->willReturn([
            'access_token' => 'a.b.c',
            'refresh_token' => 'refresh-1',
            'expires_in' => 900,
        ]);
        $authManager = $this->createMock(AuthenticationManager::class);
        $authManager->method('getProvider')->willReturn($provider);

        $context = new ApplicationContext(__DIR__);
        $container = new Container();
        $container->load([
            SessionStoreInterface::class => new ValueDefinition(SessionStoreInterface::class, $store),
            RefreshTokenStore::class => new ValueDefinition(RefreshTokenStore::class, $refreshStore),
            SessionCacheManager::class => new ValueDefinition(
                SessionCacheManager::class,
                $this->createMock(SessionCacheManager::class)
            ),
        ]);
        $context->setContainer($container);

        $manager = new TokenManager($context, $this->createMock(Connection::class), $authManager);
        $session = $manager->createUserSession(['uuid' => 'user-1', 'email' => 'u@example.test'], 'jwt');

        self::assertIsString($storedSessionId);
        self::assertSame($storedSessionId, $session['session_uuid'] ?? null);
    }

    public function testTheLoginCsrfTokenValidatesOnALaterSessionAuthenticatedWrite(): void
    {
        $csrf = new CSRFMiddleware(cache: new ArrayCacheDriver());
        $shaper = new LoginResponseShaper($this->context($csrf));

        $login = Request::create('/api/v1/auth/login', 'POST');
        $login->server->set('REMOTE_ADDR', '203.0.113.10');

        $response = $shaper->shape($login, [
            'access_token' => 'a.b.c',
            'refresh_token' => 'refresh-1',
            'expires_in' => 900,
            'token_type' => 'Bearer',
            'session_uuid' => 'session-XYZ',
            'user' => ['id' => 'user-1'],
        ]);
        /** @var array{data: array{csrf_token: array{token: string}}} $body */
        $body = json_decode((string) $response->getContent(), true);
        $token = $body['data']['csrf_token']['token'];

        // A later write from a different network/browser fingerprint, same session.
        $write = Request::create('/api/v1/posts', 'POST');
        $write->server->set('REMOTE_ADDR', '198.51.100.7');
        $write->headers->set('X-CSRF-Token', $token);
        $write->attributes->set('user', ['uuid' => 'user-1', 'sid' => 'session-XYZ']);

        try {
            $result = $csrf->handle($write, static fn (): Response => new Response('next'), 3600, false, false);
        } catch (SecurityException $e) {
            self::fail('login CSRF token was rejected: ' . $e->getMessage());
        }

        self::assertSame('next', $result->getContent());
    }

    private function context(CSRFMiddleware $csrf): ApplicationContext
    {
        $provider = new ListenerProvider();
        $events = new EventService(new EventDispatcher($provider), $provider);

        $context = new ApplicationContext(__DIR__);
        $container = new Container();
        $container->load([
            EventService::class => new ValueDefinition(EventService::class, $events),
            CSRFMiddleware::class => new ValueDefinition(CSRFMiddleware::class, $csrf),
        ]);
        $context->setContainer($container);

        return $context;
    }
}

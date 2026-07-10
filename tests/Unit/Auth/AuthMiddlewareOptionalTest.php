<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Auth;

use Glueful\Auth\AuthenticationManager;
use Glueful\Auth\TokenManager;
use Glueful\Auth\UserIdentity;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Container;
use Glueful\Container\Definition\ValueDefinition;
use Glueful\Routing\Middleware\AuthMiddleware;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthMiddlewareOptionalTest extends TestCase
{
    public function testOptionalAuthPassesWhenCredentialsAreAbsent(): void
    {
        $manager = $this->createMock(AuthenticationManager::class);
        $manager->expects(self::never())->method('authenticateWithProviders');
        $middleware = $this->middleware($manager);

        $response = $middleware->handle(
            Request::create('/blob'),
            static fn (): Response => new Response('next', 204),
            'optional',
        );

        self::assertSame(204, $response->getStatusCode());
    }

    public function testOptionalAuthRejectsInvalidCredentials(): void
    {
        $manager = $this->createMock(AuthenticationManager::class);
        $manager->expects(self::once())->method('authenticateWithProviders')->willReturn(null);
        $middleware = $this->middleware($manager, 'invalid-token');
        $request = Request::create('/blob');
        $request->headers->set('Authorization', 'Bearer invalid-token');

        $response = $middleware->handle($request, static fn (): Response => new Response('next'), 'optional');

        self::assertSame(401, $response->getStatusCode());
    }

    public function testOptionalAuthPopulatesIdentityWhenCredentialsAreValid(): void
    {
        $manager = $this->createMock(AuthenticationManager::class);
        $manager->expects(self::once())->method('authenticateWithProviders')->willReturn([
            'uuid' => 'user00000001',
            'email' => 'user@example.test',
            'auth_provider' => 'jwt',
        ]);
        $middleware = $this->middleware($manager, 'valid-token');
        $request = Request::create('/blob');
        $request->headers->set('Authorization', 'Bearer valid-token');

        $response = $middleware->handle(
            $request,
            static function (Request $request): Response {
                $identity = $request->attributes->get('auth.user');
                return new Response($identity instanceof UserIdentity ? $identity->uuid() : '', 200);
            },
            'optional',
        );

        self::assertSame('user00000001', $response->getContent());
    }

    private function middleware(AuthenticationManager $manager, ?string $token = null): AuthMiddleware
    {
        $context = ApplicationContext::forTesting(dirname(__DIR__, 3));
        $tokenManager = $this->createMock(TokenManager::class);
        $tokenManager->method('extractTokenFromRequest')->willReturn($token);
        $container = new Container();
        $container->load([
            ApplicationContext::class => new ValueDefinition(ApplicationContext::class, $context),
            TokenManager::class => new ValueDefinition(TokenManager::class, $tokenManager),
        ]);
        $context->setContainer($container);

        return new AuthMiddleware(
            authManager: $manager,
            container: $container,
            providerNames: ['jwt'],
            options: ['validate_expiration' => false, 'enable_events' => false, 'enable_logging' => false],
            context: $context,
        );
    }
}

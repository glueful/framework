<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit;

use Glueful\Application;
use Glueful\Auth\AuthenticationGuard;
use Glueful\Auth\AuthenticationService;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\RequestLifecycle;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ApplicationLifecycleTest extends TestCase
{
    public function testRequestLifecycleHooksAreCalled(): void
    {
        $context = new ApplicationContext(sys_get_temp_dir() . '/app_lifecycle_' . uniqid());
        $lifecycle = new RequestLifecycle($context);

        $events = ['begin' => 0, 'end' => 0];
        $lifecycle->onBeginRequest(function () use (&$events): void {
            $events['begin']++;
        });
        $lifecycle->onEndRequest(function () use (&$events): void {
            $events['end']++;
        });

        $router = new class {
            public function dispatch(Request $request): Response
            {
                return new Response('ok', 200);
            }
        };

        $container = new class ($context, $lifecycle, $router) implements ContainerInterface {
            /** @var array<string, mixed> */
            private array $services = [];

            public function __construct(
                ApplicationContext $context,
                RequestLifecycle $lifecycle,
                object $router
            ) {
                $this->services[ApplicationContext::class] = $context;
                $this->services[RequestLifecycle::class] = $lifecycle;
                $this->services[LoggerInterface::class] = new NullLogger();
                $this->services[\Glueful\Routing\Router::class] = $router;
            }

            public function get(string $id): mixed
            {
                return $this->services[$id];
            }

            public function has(string $id): bool
            {
                return array_key_exists($id, $this->services);
            }
        };

        $context->setContainer($container);

        $app = new Application($context);
        $request = Request::create('/health', 'GET');

        $response = $app->handle($request);
        $app->terminate($request, $response);

        $this->assertSame(1, $events['begin']);
        $this->assertSame(1, $events['end']);
    }

    public function testRequestLifecycleResetsSharedAuthenticationGuard(): void
    {
        $context = new ApplicationContext(sys_get_temp_dir() . '/app_lifecycle_' . uniqid());
        $lifecycle = new RequestLifecycle($context);
        $authService = new SequentialAuthenticationService();
        $guard = new AuthenticationGuard($authService);

        $container = new class ($context, $lifecycle, $guard) implements ContainerInterface {
            /** @var array<string, mixed> */
            private array $services = [];

            public function __construct(
                ApplicationContext $context,
                RequestLifecycle $lifecycle,
                AuthenticationGuard $guard
            ) {
                $this->services[ApplicationContext::class] = $context;
                $this->services[RequestLifecycle::class] = $lifecycle;
                $this->services[AuthenticationGuard::class] = $guard;
                $this->services[LoggerInterface::class] = new NullLogger();
            }

            public function get(string $id): mixed
            {
                return $this->services[$id];
            }

            public function has(string $id): bool
            {
                return array_key_exists($id, $this->services);
            }
        };

        $context->setContainer($container);
        $lifecycle->onEndRequest(function (ApplicationContext $ctx): void {
            if (!$ctx->hasContainer()) {
                return;
            }

            $container = $ctx->getContainer();
            if ($container->has(AuthenticationGuard::class)) {
                $guard = $container->get(AuthenticationGuard::class);
                if (method_exists($guard, 'reset')) {
                    $guard->reset();
                }
            }
        });

        $this->assertSame('user-1', $guard->user()?->uuid);

        $lifecycle->endRequest();

        $this->assertSame('user-2', $guard->user()?->uuid);
        $this->assertSame(2, $authService->calls);
    }
}

final class SequentialAuthenticationService extends AuthenticationService
{
    public int $calls = 0;

    public function __construct()
    {
    }

    public function getCurrentUser(): ?object
    {
        $this->calls++;

        return (object) ['uuid' => 'user-' . $this->calls];
    }
}

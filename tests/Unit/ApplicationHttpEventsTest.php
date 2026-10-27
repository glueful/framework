<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit;

use Glueful\Application;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Events\EventDispatcher;
use Glueful\Events\EventService;
use Glueful\Events\Http\RequestEvent;
use Glueful\Events\Http\ResponseEvent;
use Glueful\Events\ListenerProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** The HTTP kernel dispatches RequestEvent / ResponseEvent to registered listeners. */
final class ApplicationHttpEventsTest extends TestCase
{
    private function app(?EventService $events): Application
    {
        $context = new ApplicationContext(sys_get_temp_dir() . '/app_http_events_' . uniqid());
        $router = new class {
            public function dispatch(Request $request): Response
            {
                return new Response('ok', 201);
            }
        };

        $services = [
            ApplicationContext::class => $context,
            LoggerInterface::class => new NullLogger(),
            \Glueful\Routing\Router::class => $router,
        ];
        if ($events !== null) {
            $services[EventService::class] = $events;
        }

        $container = new class ($services) implements ContainerInterface {
            /** @param array<string, mixed> $services */
            public function __construct(private array $services)
            {
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

        return new Application($context);
    }

    private function events(): EventService
    {
        $provider = new ListenerProvider();
        return new EventService(new EventDispatcher($provider), $provider);
    }

    public function testRequestAndResponseEventsReachListeners(): void
    {
        $events = $this->events();
        $seen = [];
        $events->addListener(RequestEvent::class, function (RequestEvent $e) use (&$seen): void {
            $seen[] = 'request:' . $e->getRequest()->getPathInfo();
        });
        $events->addListener(ResponseEvent::class, function (ResponseEvent $e) use (&$seen): void {
            $seen[] = 'response:' . $e->getResponse()->getStatusCode() . ':' . $e->getRequest()->getPathInfo();
        });

        $response = $this->app($events)->handle(Request::create('/things', 'GET'));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(['request:/things', 'response:201:/things'], $seen);
    }

    public function testAFailingListenerDoesNotBreakTheRequest(): void
    {
        $events = $this->events();
        $events->addListener(RequestEvent::class, static function (): void {
            throw new \RuntimeException('listener boom');
        });
        $events->addListener(ResponseEvent::class, static function (): void {
            throw new \RuntimeException('listener boom');
        });

        $previous = ini_set('error_log', '/dev/null');
        try {
            $response = $this->app($events)->handle(Request::create('/things', 'GET'));
        } finally {
            ini_set('error_log', (string) $previous);
        }

        self::assertSame(201, $response->getStatusCode());
    }

    public function testRequestsWorkWithoutAnEventService(): void
    {
        self::assertSame(201, $this->app(null)->handle(Request::create('/things', 'GET'))->getStatusCode());
    }
}

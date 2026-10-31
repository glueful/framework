<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Auth;

use Glueful\Auth\LoginResponseShaper;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Events\Auth\LoginResponseBuildingEvent;
use Glueful\Events\EventDispatcher;
use Glueful\Events\EventService;
use Glueful\Events\ListenerProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Pins the contract that a LoginResponseBuildingEvent listener can add/merge
 * fields into the login response's `user` object (the shaper must read the
 * mutated response map back, not discard it).
 */
final class LoginResponseShaperTest extends TestCase
{
    protected function setUp(): void
    {
        // Keep the CSRF branch out of the shaper path so the test is deterministic.
        putenv('CSRF_PROTECTION_ENABLED=false');
        $_ENV['CSRF_PROTECTION_ENABLED'] = 'false';
    }

    protected function tearDown(): void
    {
        putenv('CSRF_PROTECTION_ENABLED');
        unset($_ENV['CSRF_PROTECTION_ENABLED']);
    }

    public function test_listener_can_add_fields_to_login_response_user_object(): void
    {
        $provider = new ListenerProvider();
        $events = new EventService(new EventDispatcher($provider), $provider);
        $events->addListener(
            LoginResponseBuildingEvent::class,
            static function (LoginResponseBuildingEvent $e): void {
                $e->mergeResponse(['user' => ['department' => 'engineering']]);
            }
        );

        $shaper = new LoginResponseShaper($this->contextWithEvents($events));

        $session = [
            'access_token' => 'a',
            'refresh_token' => 'r',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
            'user' => ['id' => 'u-1', 'email' => 'u@example.com'],
        ];

        $response = $shaper->shape(new Request(), $session);
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true);

        // The listener's merged field surfaces in the response...
        self::assertSame('engineering', $body['data']['user']['department']);
        // ...without clobbering the existing user fields.
        self::assertSame('u-1', $body['data']['user']['id']);
        self::assertSame('u@example.com', $body['data']['user']['email']);
    }

    public function test_response_is_unchanged_when_no_listener_registered(): void
    {
        $provider = new ListenerProvider();
        $events = new EventService(new EventDispatcher($provider), $provider);

        $shaper = new LoginResponseShaper($this->contextWithEvents($events));

        $session = ['user' => ['id' => 'u-2'], 'access_token' => 'a'];
        $response = $shaper->shape(new Request(), $session);
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true);

        self::assertSame(['id' => 'u-2'], $body['data']['user']);
    }

    public function test_flat_provider_result_still_gives_listeners_the_user_id(): void
    {
        $provider = new ListenerProvider();
        $events = new EventService(new EventDispatcher($provider), $provider);
        $seen = null;
        $events->addListener(
            LoginResponseBuildingEvent::class,
            static function (LoginResponseBuildingEvent $e) use (&$seen): void {
                $seen = $e->getUser();
            }
        );

        $shaper = new LoginResponseShaper($this->contextWithEvents($events));

        // What the JWT and API-key providers return: the identity flat, no nested `user`.
        $session = ['uuid' => 'u-3', 'sub' => 'u-3', 'email' => 'u3@example.com', 'provider' => 'api_key'];
        $response = $shaper->shape(new Request(), $session);
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true);

        self::assertSame('u-3', $seen['id'] ?? null);
        self::assertSame('u-3', $seen['uuid'] ?? null);
        self::assertSame('u3@example.com', $seen['email'] ?? null);
        // The body is untouched: no `user` block is invented on the wire.
        self::assertArrayNotHasKey('user', $body['data']);
        self::assertSame('u-3', $body['data']['uuid']);
    }

    public function test_listener_sees_an_empty_user_when_no_principal_is_known(): void
    {
        $provider = new ListenerProvider();
        $events = new EventService(new EventDispatcher($provider), $provider);
        $seen = null;
        $events->addListener(
            LoginResponseBuildingEvent::class,
            static function (LoginResponseBuildingEvent $e) use (&$seen): void {
                $seen = $e->getUser();
            }
        );

        $shaper = new LoginResponseShaper($this->contextWithEvents($events));
        $shaper->shape(new Request(), ['access_token' => 'a']);

        self::assertSame([], $seen);
    }

    private function contextWithEvents(EventService $events): ApplicationContext
    {
        $context = ApplicationContext::forTesting(sys_get_temp_dir());
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturnCallback(static function (string $id) use ($events) {
            if ($id === EventService::class) {
                return $events;
            }
            throw new \RuntimeException("unexpected get($id)");
        });
        $context->setContainer($container);

        return $context;
    }
}

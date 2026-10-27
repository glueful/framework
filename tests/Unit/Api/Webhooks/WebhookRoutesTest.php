<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Api\Webhooks;

use Glueful\Api\Webhooks\Http\Controllers\WebhookController;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Framework;
use Glueful\Routing\RouteManifest;
use Glueful\Routing\Router;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * WebhookController was registered in the container but no route reached it. Core now ships
 * routes/webhooks.php under the API prefix, every route behind `auth`, and every action checks
 * the same system.config tier the in-admin extensions manager uses (view to read, edit to change).
 */
final class WebhookRoutesTest extends TestCase
{
    private string $appPath;
    private ApplicationContext $context;

    protected function setUp(): void
    {
        RouteManifest::reset();
        $this->appPath = sys_get_temp_dir() . '/glueful-webhooks-routes-' . uniqid('', true);
        $cfg = $this->appPath . '/config';
        mkdir($cfg, 0755, true);
        $files = [
            'app' => "['name' => 'T', 'version_full' => '1.0.0', 'env' => 'testing', 'debug' => true, "
                . "'key' => 'test-key']",
            'database' => "['engine' => 'sqlite', 'sqlite' => ['primary' => '" . $this->appPath . "/w.sqlite'], "
                . "'pooling' => ['enabled' => false]]",
            'cache' => "['enabled' => true, 'default' => 'array', 'stores' => ['array' => ['driver' => 'array']]]",
            'security' => "['csrf' => ['enabled' => false]]",
            'session' => "['jwt_key' => 'test']",
        ];
        foreach ($files as $name => $body) {
            file_put_contents("{$cfg}/{$name}.php", "<?php\nreturn {$body};\n");
        }
        $this->context = Framework::create($this->appPath)->boot(allowReboot: true)->getContext();
    }

    protected function tearDown(): void
    {
        RouteManifest::reset();
        exec('rm -rf ' . escapeshellarg($this->appPath));
    }

    /** @return list<array{0: string, 1: string, 2: string}> method, path, action */
    public static function routes(): array
    {
        return [
            ['GET', '/webhooks/subscriptions', 'listSubscriptions'],
            ['POST', '/webhooks/subscriptions', 'createSubscription'],
            ['GET', '/webhooks/subscriptions/wh_sub_1', 'getSubscription'],
            ['PATCH', '/webhooks/subscriptions/wh_sub_1', 'updateSubscription'],
            ['DELETE', '/webhooks/subscriptions/wh_sub_1', 'deleteSubscription'],
            ['POST', '/webhooks/subscriptions/wh_sub_1/rotate-secret', 'rotateSecret'],
            ['POST', '/webhooks/subscriptions/wh_sub_1/test', 'testSubscription'],
            ['GET', '/webhooks/subscriptions/wh_sub_1/stats', 'getSubscriptionStats'],
            ['GET', '/webhooks/deliveries', 'listDeliveries'],
            ['GET', '/webhooks/deliveries/wh_del_1', 'getDelivery'],
            ['POST', '/webhooks/deliveries/wh_del_1/retry', 'retryDelivery'],
        ];
    }

    public function testEveryControllerActionIsRoutedUnderTheApiPrefixBehindAuth(): void
    {
        $router = container($this->context)->get(Router::class);
        $prefix = api_prefix($this->context);

        foreach (self::routes() as [$method, $path, $action]) {
            $match = $router->match(Request::create($prefix . $path, $method));
            $route = $match['route'] ?? null;
            self::assertNotNull($route, "{$method} {$prefix}{$path} must be routed");
            self::assertSame([WebhookController::class, $action], $route->getHandler(), "{$method} {$path}");
            self::assertContains('auth', array_map(
                static fn($m) => is_string($m) ? explode(':', $m, 2)[0] : '',
                $route->getMiddleware()
            ), "{$method} {$path} must require auth");
        }
    }

    public function testAnUnauthenticatedRequestIsRefused(): void
    {
        $router = container($this->context)->get(Router::class);

        $response = $router->dispatch(Request::create(api_prefix($this->context) . '/webhooks/subscriptions', 'GET'));

        self::assertSame(401, $response->getStatusCode());
    }

    public function testEveryActionChecksTheSystemConfigPermissionTierFirst(): void
    {
        $controller = new class extends WebhookController {
            /** @var list<string> */
            public array $perms = [];

            // phpcs:ignore
            public function __construct()
            {
            }

            protected function requirePermission(
                string $permission,
                string $resource = 'system',
                array $context = []
            ): void {
                $this->perms[] = $permission;
                throw new \DomainException('stop');
            }
        };
        $request = Request::create('/');
        $expected = [
            'listSubscriptions' => ['system.config.view', [$request]],
            'createSubscription' => ['system.config.edit', [$request]],
            'getSubscription' => ['system.config.view', ['x']],
            'updateSubscription' => ['system.config.edit', [$request, 'x']],
            'deleteSubscription' => ['system.config.edit', ['x']],
            'rotateSecret' => ['system.config.edit', ['x']],
            'testSubscription' => ['system.config.edit', ['x']],
            'getSubscriptionStats' => ['system.config.view', [$request, 'x']],
            'listDeliveries' => ['system.config.view', [$request]],
            'getDelivery' => ['system.config.view', ['x']],
            'retryDelivery' => ['system.config.edit', ['x']],
        ];

        foreach ($expected as $action => [$permission, $args]) {
            $controller->perms = [];
            try {
                $controller->{$action}(...$args);
                self::fail("{$action} ran without a permission check");
            } catch (\DomainException) {
                self::assertSame([$permission], $controller->perms, $action);
            }
        }
    }
}

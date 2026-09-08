<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Http;

use Glueful\Application;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\RequestLifecycle;
use Glueful\Routing\Router;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `CSP_HEADER` shipped in every .env.example and was nagged about at production boot, yet nothing
 * ever read it. When set, the application sends it verbatim as `Content-Security-Policy` on every
 * response that does not already carry a policy of its own (a mounted SPA's document policy, a
 * controller's explicit header). `CSP_REPORT_ONLY=true` switches to the report-only header so an
 * operator can audit a policy before enforcing it. No other header is touched.
 */
final class ContentSecurityPolicyEnvTest extends TestCase
{
    /** @var array<string, string|null> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['CSP_HEADER', 'CSP_REPORT_ONLY'] as $k) {
            $this->saved[$k] = $_ENV[$k] ?? null;
            unset($_ENV[$k]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            if ($v === null) {
                unset($_ENV[$k]);
            } else {
                $_ENV[$k] = $v;
            }
        }
    }

    public function testConfiguredPolicyIsSentVerbatimOnResponsesWithoutOne(): void
    {
        $_ENV['CSP_HEADER'] = "default-src 'self'; img-src 'self' data:";

        $response = $this->handle(new Response('{"ok":true}', 200, ['Content-Type' => 'application/json']));

        self::assertSame("default-src 'self'; img-src 'self' data:", $response->headers->get('Content-Security-Policy'));
        self::assertFalse($response->headers->has('Content-Security-Policy-Report-Only'));
    }

    public function testUnsetOrEmptyVariableSendsNoPolicy(): void
    {
        self::assertFalse($this->handle(new Response('ok'))->headers->has('Content-Security-Policy'));

        $_ENV['CSP_HEADER'] = '';
        self::assertFalse($this->handle(new Response('ok'))->headers->has('Content-Security-Policy'));
    }

    public function testResponseThatAlreadyCarriesAPolicyIsLeftAlone(): void
    {
        $_ENV['CSP_HEADER'] = "default-src 'none'";

        $spaDocument = new Response('<!doctype html>', 200, [
            'Content-Security-Policy' => "default-src 'self'; style-src 'self' 'unsafe-inline'",
        ]);

        $response = $this->handle($spaDocument);

        self::assertSame("default-src 'self'; style-src 'self' 'unsafe-inline'", $response->headers->get('Content-Security-Policy'));
    }

    public function testReportOnlyModeSendsTheReportOnlyHeaderInstead(): void
    {
        $_ENV['CSP_HEADER'] = "default-src 'self'";
        $_ENV['CSP_REPORT_ONLY'] = 'true';

        $response = $this->handle(new Response('ok'));

        self::assertSame("default-src 'self'", $response->headers->get('Content-Security-Policy-Report-Only'));
        self::assertFalse($response->headers->has('Content-Security-Policy'));
    }

    public function testReportOnlyModeDoesNotStackOntoAResponseWithItsOwnPolicy(): void
    {
        $_ENV['CSP_HEADER'] = "default-src 'self'";
        $_ENV['CSP_REPORT_ONLY'] = 'true';

        $response = $this->handle(new Response('ok', 200, ['Content-Security-Policy' => "default-src 'none'"]));

        self::assertFalse($response->headers->has('Content-Security-Policy-Report-Only'));
        self::assertSame("default-src 'none'", $response->headers->get('Content-Security-Policy'));
    }

    public function testPolicyIsAppliedToExceptionResponsesToo(): void
    {
        $_ENV['CSP_HEADER'] = "default-src 'self'";

        $response = $this->handle(new \RuntimeException('boom'));

        self::assertSame("default-src 'self'", $response->headers->get('Content-Security-Policy'));
    }

    private function handle(Response|\Throwable $routerResult): Response
    {
        $context = new ApplicationContext(sys_get_temp_dir() . '/csp_env_' . uniqid());
        $lifecycle = new RequestLifecycle($context);

        $router = new class ($routerResult) {
            public function __construct(private Response|\Throwable $result)
            {
            }

            public function dispatch(Request $request): Response
            {
                if ($this->result instanceof \Throwable) {
                    throw $this->result;
                }
                return $this->result;
            }
        };

        $handler = new class implements \Glueful\Http\Exceptions\Contracts\ExceptionHandlerInterface {
            public function handle(\Throwable $e, ?Request $request = null): \Glueful\Http\Response
            {
                return new \Glueful\Http\Response(['error' => $e->getMessage()], 500);
            }

            public function shouldReport(\Throwable $e): bool
            {
                return false;
            }

            public function report(\Throwable $e, ?Request $request = null): void
            {
            }

            public function render(\Throwable $e, ?Request $request = null): \Glueful\Http\Response
            {
                return $this->handle($e, $request);
            }
        };

        $container = new class ($context, $lifecycle, $router, $handler) implements ContainerInterface {
            /** @var array<string, mixed> */
            private array $services = [];

            public function __construct(ApplicationContext $context, RequestLifecycle $lifecycle, object $router, object $handler)
            {
                $this->services[ApplicationContext::class] = $context;
                $this->services[RequestLifecycle::class] = $lifecycle;
                $this->services[LoggerInterface::class] = new NullLogger();
                $this->services[Router::class] = $router;
                $this->services[\Glueful\Http\Exceptions\Contracts\ExceptionHandlerInterface::class] = $handler;
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

        return (new Application($context))->handle(Request::create('/api/ping', 'GET'));
    }
}

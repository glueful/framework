# Testing

Quick patterns for testing Glueful apps.

## PHPUnit Setup

- Ensure `vendor/bin/phpunit` is installed and `tests/bootstrap.php` exists.
- CI example: see `.github/workflows/test.yml`.

## Router Integration Test (Example)

```php
<?php
use PHPUnit\\Framework\\TestCase;
use Glueful\\Framework;
use Glueful\\Routing\\Router;
use Symfony\\Component\\HttpFoundation\\Request;

final class PingTest extends TestCase
{
    public function test_ping_endpoint_returns_ok(): void
    {
        $app = Framework::create(getcwd())->boot();
        $router = $app->getContainer()->get(Router::class);

        $router->get('/ping', fn() => new Glueful\\Http\\Response(['ok' => true]));
        $response = $router->dispatch(Request::create('/ping', 'GET'));

        $this->assertSame(200, $response->getStatusCode());
    }
}
```

## Rate Limiter Behavior (Sketch)

See `docs/RATELIMITER.md` for advanced testing strategies.

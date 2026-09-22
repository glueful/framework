<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Http;

use Glueful\Framework;
use Glueful\Http\BaselineSecurityHeaders;
use Glueful\Routing\RouteManifest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * JSON API responses and the API reference went out with no security headers at all: the
 * `security_headers` middleware is opt-in per route, and only SPA documents set their own. The
 * response chokepoint that already applies CORS and the CSP now adds the two headers that are safe
 * on every response of every app, only when the response has not set them.
 */
final class BaselineSecurityHeadersTest extends TestCase
{
    public function testTheBaselineIsAddedOnlyWhereAbsent(): void
    {
        $bare = new Response('{}');
        (new BaselineSecurityHeaders())->applyToResponse($bare);
        self::assertSame('nosniff', $bare->headers->get('X-Content-Type-Options'));
        self::assertSame('strict-origin-when-cross-origin', $bare->headers->get('Referrer-Policy'));

        $own = new Response('{}', 200, ['Referrer-Policy' => 'no-referrer']);
        (new BaselineSecurityHeaders())->applyToResponse($own);
        self::assertSame('no-referrer', $own->headers->get('Referrer-Policy'), 'a response\'s own policy wins');
    }

    public function testEveryResponseThroughTheAppCarriesIt(): void
    {
        RouteManifest::reset();
        $path = sys_get_temp_dir() . '/glueful-baseline-' . uniqid('', true);
        mkdir($path . '/config', 0755, true);
        file_put_contents($path . '/config/app.php', "<?php return ['name' => 'T', 'env' => 'testing', 'debug' => false, 'key' => str_repeat('k', 32)];");
        file_put_contents($path . '/config/database.php', "<?php return ['engine' => 'sqlite', 'sqlite' => ['primary' => ':memory:'], 'pooling' => ['enabled' => false]];");
        file_put_contents($path . '/config/cache.php', "<?php return ['enabled' => true, 'default' => 'array', 'stores' => ['array' => ['driver' => 'array']]];");
        try {
            $app = Framework::create($path)->boot(allowReboot: true);

            $response = $app->handle(Request::create('/definitely-not-a-route'));

            self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
            self::assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
        } finally {
            exec('rm -rf ' . escapeshellarg($path));
        }
    }
}

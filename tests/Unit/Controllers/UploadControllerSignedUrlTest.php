<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Controllers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Controllers\UploadController;
use Glueful\Framework;
use Glueful\Routing\RouteManifest;
use Glueful\Routing\Router;
use Glueful\Support\SignedUrl;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The blob routes are mounted under the API prefix (`/api/v1/blobs/{uuid}` by default), but the
 * signed URL was built as `{origin}/blobs/{uuid}`: a path no route serves, so every signed link
 * 404'd. It is now built under api_prefix(), and the signature still validates at the route.
 */
final class UploadControllerSignedUrlTest extends TestCase
{
    private string $appPath;

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->appPath));
        RouteManifest::reset();
    }

    private function boot(string $versioning): ApplicationContext
    {
        RouteManifest::reset();
        $this->appPath = sys_get_temp_dir() . '/glueful-signedurl-' . uniqid('', true);
        $cfg = $this->appPath . '/config';
        mkdir($cfg, 0755, true);
        $files = [
            'app' => "['name' => 'T', 'version_full' => '1.0.0', 'env' => 'testing', 'debug' => true, "
                . "'key' => 'test-signing-key']",
            'database' => "['engine' => 'sqlite', 'sqlite' => ['primary' => '" . $this->appPath . "/d.sqlite'], "
                . "'pooling' => ['enabled' => false]]",
            'cache' => "['enabled' => true, 'default' => 'array', 'stores' => ['array' => ['driver' => 'array']]]",
            'security' => "['csrf' => ['enabled' => false]]",
            'session' => "['jwt_key' => 'test']",
            'api' => "['versioning' => {$versioning}]",
        ];
        foreach ($files as $name => $body) {
            file_put_contents("{$cfg}/{$name}.php", "<?php\nreturn {$body};\n");
        }

        return Framework::create($this->appPath)->boot(allowReboot: true)->getContext();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function prefixes(): iterable
    {
        yield 'default /api/v1' => ["['prefix' => '/api', 'default' => '1']", '/api/v1/blobs/abc123'];
        yield 'no prefix' => [
            "['apply_prefix_to_routes' => false, 'version_in_path' => false]",
            '/blobs/abc123',
        ];
    }

    /**
     * @dataProvider prefixes
     */
    public function testTheSignedUrlPointsAtTheMountedBlobRoute(string $versioning, string $expectedPath): void
    {
        $context = $this->boot($versioning);

        $url = UploadController::blobUrl($context, 'https://files.example', 'abc123');
        self::assertSame('https://files.example' . $expectedPath, $url);

        $signed = SignedUrl::make($context)->generate($url, 600);
        $request = Request::create($signed, 'GET');

        $router = $context->getContainer()->get(Router::class);
        self::assertInstanceOf(Router::class, $router);
        $match = $router->match($request);
        self::assertNotNull($match['route'] ?? null, "no route serves {$expectedPath}");
        self::assertSame([UploadController::class, 'show'], $match['route']->getHandler());

        self::assertTrue(
            SignedUrl::make($context)->validateParams($request->getPathInfo(), $request->query->all()),
            'the signature validates at the path the route sees'
        );
    }
}

<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support\Documentation;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Container;
use Glueful\Container\Definition\ValueDefinition;
use Glueful\Extensions\ExtensionManager;
use Glueful\Routing\RouteCache;
use Glueful\Routing\Router;
use Glueful\Support\Documentation\CommentsDocGenerator;
use Glueful\Support\Documentation\RouteReflectionDocGenerator;
use Glueful\Support\Documentation\SecuritySchemeRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Stage 3-A safety gate (TEMPORARY — removed with the comment-path tests when the
 * comment generator is deleted in the next task).
 *
 * Proves the code-first `reflect` generator is NOT poorer than the legacy
 * `comments` generator over the framework's REAL route table (the 5 route-wired
 * framework route files: auth/blobs/resource/health/docs). For every
 * (path, verb) operation the COMMENT generator documents, the REFLECT generator
 * must produce an operation with AT LEAST equivalent coverage:
 *
 *   - the (path, verb) exists in reflect;
 *   - reflect has a non-empty `summary`;
 *   - reflect's `responses` is non-empty and includes the comment operation's
 *     documented success status (or an equivalent 2xx);
 *   - if comment has a `requestBody`, reflect has one too — with ONE known
 *     exception: a DELETE-verb request body (reflect cannot emit one;
 *     ResourceController::destroyBulk documents a JSON body on DELETE).
 *
 * COARSE by design: this is a coverage floor, NOT a field-exact diff (the
 * migration deliberately improved/corrected some docs). Paths are compared
 * prefix-normalized (leading /api and /v{n} stripped) so the comparison does not
 * hinge on the version-prefix wiring, only on the operation surface.
 *
 * Both specs are generated over the SAME real route files: the comment spec via
 * CommentsDocGenerator::generateForRouteFile() (docblock parsing, route_prefixes
 * applied), the reflect spec via RouteReflectionDocGenerator over a Router loaded
 * with those same files (mirroring RouteManifest's prefix model).
 *
 * @covers \Glueful\Support\Documentation\RouteReflectionDocGenerator
 */
final class ReflectGteCommentGateTest extends TestCase
{
    private const SCHEMES = [
        'BearerAuth' => [
            'type' => 'http',
            'scheme' => 'bearer',
            'bearerFormat' => 'JWT',
        ],
        'ApiKeyAuth' => [
            'type' => 'apiKey',
            'in' => 'header',
            'name' => 'X-API-Key',
        ],
    ];

    private const MIDDLEWARE_MAP = [
        'auth' => ['BearerAuth'],
        'api_key' => ['ApiKeyAuth'],
    ];

    /**
     * Framework route files that have been wired to the reflect generator, with
     * the URL prefix RouteManifest applies (api_routes get /v1; public get '').
     *
     * @var array<string, string>
     */
    private const ROUTE_FILES = [
        'auth' => '/v1',
        'blobs' => '/v1',
        'resource' => '/v1',
        'health' => '',
        'docs' => '',
    ];

    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/reflect_gte_gate_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
    }

    public function testReflectIsNotPoorerThanCommentOverTheRealRouteTable(): void
    {
        $frameworkRoot = dirname(__DIR__, 4);

        $commentSpec = $this->buildCommentSpec($frameworkRoot);
        $reflectSpec = $this->buildReflectSpec($frameworkRoot);

        // Index reflect operations by normalized (path, verb).
        $reflectByKey = [];
        foreach ($reflectSpec as $path => $verbs) {
            foreach ($verbs as $verb => $operation) {
                $reflectByKey[$this->key($path, $verb)] = $operation;
            }
        }

        $compared = 0;
        $shortfalls = [];

        foreach ($commentSpec as $path => $verbs) {
            foreach ($verbs as $verb => $commentOp) {
                $compared++;
                $key = $this->key($path, $verb);
                $where = strtoupper($verb) . ' ' . $path;

                $reflectOp = $reflectByKey[$key] ?? null;
                if ($reflectOp === null) {
                    $shortfalls[] = "$where: missing from reflect spec";
                    continue;
                }

                // 1) Non-empty summary.
                $summary = $reflectOp['summary'] ?? '';
                if (!is_string($summary) || trim($summary) === '') {
                    $shortfalls[] = "$where: reflect summary is empty";
                }

                // 2) Non-empty responses including the comment's success status
                //    (or an equivalent 2xx).
                $reflectResponses = $reflectOp['responses'] ?? [];
                if (!is_array($reflectResponses) || $reflectResponses === []) {
                    $shortfalls[] = "$where: reflect responses are empty";
                } else {
                    $successStatus = $this->commentSuccessStatus($commentOp);
                    if (
                        $successStatus !== null
                        && !isset($reflectResponses[$successStatus])
                        && !$this->hasAny2xx($reflectResponses)
                    ) {
                        $shortfalls[] = "$where: reflect lacks success status "
                            . "$successStatus (and has no 2xx)";
                    }
                }

                // 3) requestBody parity — with the DELETE-body exception.
                if (isset($commentOp['requestBody']) && !isset($reflectOp['requestBody'])) {
                    if (strtolower($verb) === 'delete') {
                        // KNOWN EXCEPTION: reflect cannot emit a DELETE request body
                        // (ResourceController::destroyBulk). Skip explicitly.
                        continue;
                    }
                    $shortfalls[] = "$where: comment has requestBody, reflect does not";
                }
            }
        }

        self::assertGreaterThan(
            0,
            $compared,
            'The comment spec produced no operations — the gate would be vacuous.',
        );

        self::assertSame(
            [],
            $shortfalls,
            sprintf(
                "Reflect generator is poorer than comment for %d operation(s) "
                . "(compared %d):\n%s",
                count($shortfalls),
                $compared,
                implode("\n", $shortfalls),
            ),
        );
    }

    /**
     * Build the COMMENT-mode paths map over the real framework route files.
     *
     * Drives CommentsDocGenerator::generateForRouteFile() per file (exactly as
     * OpenApiGenerator does in comments mode) with the real route_prefixes, and
     * collects the emitted `paths` fragments.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function buildCommentSpec(string $frameworkRoot): array
    {
        $context = $this->makeCommentContext($frameworkRoot);

        $routesOut = $this->tmpDir . '/routes-out';
        mkdir($routesOut, 0755, true);

        $generator = new CommentsDocGenerator(
            context: $context,
            localExtensionsPath: $this->tmpDir,
            outputPath: $this->tmpDir . '/ext-out',
            routesPath: $frameworkRoot . '/routes',
            routesOutputPath: $routesOut,
            extensionsManager: new ExtensionManager($context->getContainer()),
        );

        $paths = [];
        foreach (array_keys(self::ROUTE_FILES) as $name) {
            $routeFile = $frameworkRoot . '/routes/' . $name . '.php';
            self::assertFileExists($routeFile, "Framework route file missing: $name.php");

            $fragmentFile = $generator->generateForRouteFile($name, $routeFile, true);
            if ($fragmentFile === null) {
                continue;
            }

            $fragment = json_decode((string) file_get_contents($fragmentFile), true);
            foreach (($fragment['paths'] ?? []) as $path => $verbs) {
                foreach ($verbs as $verb => $operation) {
                    $paths[$path][strtolower((string) $verb)] = $operation;
                }
            }
        }

        return $paths;
    }

    /**
     * Build the REFLECT-mode paths map over the SAME real framework route files.
     *
     * Loads each route file into a Router behind the same prefix RouteManifest
     * applies (api_routes -> /v1 group; public routes ungrouped), then runs the
     * code-first generator with the real security registry.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function buildReflectSpec(string $frameworkRoot): array
    {
        $context = $this->makeReflectContext();
        (new RouteCache($context))->clear();

        // The comment generator parses docblocks statically and emits the bulk
        // operations (DELETE/PUT /data/{table}/bulk) unconditionally. At runtime
        // those routes are config-gated (resource.security.bulk_operations), so
        // enable them here for a faithful apples-to-apples comparison.
        $context->mergeConfigDefaults('resource', [
            'security' => ['bulk_operations' => true],
        ]);

        $router = new Router($context->getContainer());

        foreach (self::ROUTE_FILES as $name => $prefix) {
            $routeFile = $frameworkRoot . '/routes/' . $name . '.php';
            // The route files reference $router as a free variable, so bind it.
            $loader = static function (Router $router) use ($routeFile): void {
                require $routeFile;
            };

            if ($prefix !== '') {
                $router->group(['prefix' => $prefix], $loader);
            } else {
                $loader($router);
            }
        }

        $registry = new SecuritySchemeRegistry(self::SCHEMES, self::MIDDLEWARE_MAP);
        $reflect = new RouteReflectionDocGenerator($registry, $context);

        return $reflect->generate($router);
    }

    /**
     * Determine the comment operation's documented success status, if any.
     * Prefers an explicit 2xx (lowest), else returns null.
     */
    private function commentSuccessStatus(mixed $commentOp): ?string
    {
        if (!is_array($commentOp) || !is_array($commentOp['responses'] ?? null)) {
            return null;
        }

        $twoXx = [];
        foreach (array_keys($commentOp['responses']) as $status) {
            $code = (int) $status;
            if ($code >= 200 && $code < 300) {
                $twoXx[] = (string) $status;
            }
        }

        if ($twoXx === []) {
            return null;
        }

        sort($twoXx);
        return $twoXx[0];
    }

    /**
     * @param array<int|string, mixed> $responses
     */
    private function hasAny2xx(array $responses): bool
    {
        foreach (array_keys($responses) as $status) {
            $code = (int) $status;
            if ($code >= 200 && $code < 300) {
                return true;
            }
        }
        return false;
    }

    /**
     * Normalize a (path, verb) into a comparison key, stripping a leading /api
     * and/or /v{n} prefix so prefix-wiring differences do not mask coverage.
     */
    private function key(string $path, string $verb): string
    {
        $segments = array_values(array_filter(explode('/', $path), static fn ($s) => $s !== ''));

        while ($segments !== []) {
            $head = $segments[0];
            if ($head === 'api' || preg_match('/^v\d+$/', $head) === 1) {
                array_shift($segments);
                continue;
            }
            break;
        }

        return strtolower($verb) . ' /' . implode('/', $segments);
    }

    private function makeCommentContext(string $frameworkRoot): ApplicationContext
    {
        $context = $this->makeBaseContext();

        $context->mergeConfigDefaults('documentation', [
            'security_schemes' => self::SCHEMES,
            'middleware_map' => self::MIDDLEWARE_MAP,
            'sources' => [
                'routes' => $frameworkRoot . '/routes',
                // Mirrors config/documentation.php route_prefixes for the 5 files.
                'route_prefixes' => [
                    'auth.php' => '/v1',
                    'blobs.php' => '/v1',
                    'resource.php' => '/v1',
                    'health.php' => '',
                    'docs.php' => '',
                ],
            ],
        ]);

        return $context;
    }

    private function makeReflectContext(): ApplicationContext
    {
        $context = $this->makeBaseContext();

        $context->mergeConfigDefaults('documentation', [
            'security_schemes' => self::SCHEMES,
            'middleware_map' => self::MIDDLEWARE_MAP,
            'sources' => ['include_framework_routes' => true],
            'options' => ['include_extensions' => true],
        ]);

        return $context;
    }

    private function makeBaseContext(): ApplicationContext
    {
        $context = new ApplicationContext($this->tmpDir);
        $container = new Container();
        $container->load([
            ApplicationContext::class => new ValueDefinition(ApplicationContext::class, $context),
        ]);
        $context->setContainer($container);
        $container->load([
            ExtensionManager::class => new ValueDefinition(
                ExtensionManager::class,
                new ExtensionManager($container),
            ),
        ]);

        return $context;
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}

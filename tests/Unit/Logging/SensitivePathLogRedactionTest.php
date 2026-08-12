<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Logging;

use Glueful\Application;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Exceptions\Handler;
use Glueful\Support\SensitiveParamRedactor;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A bearer credential carried in the URL PATH must never reach a log sink.
 *
 * Two framework call sites emit the request path verbatim:
 *  - Application::handle() logs 'Request completed' at info (dev/staging levels)
 *  - Handler::report() logs the throwable with the request URI at error (every profile)
 *
 * Both must route through the registered sensitive-path patterns.
 */
final class SensitivePathLogRedactionTest extends TestCase
{
    /** Reserved characters, so the encoded row of the data provider is a genuinely
     *  different input string from the decoded one. */
    private const TOKEN = 'sk_live+9f=2a';

    protected function setUp(): void
    {
        parent::setUp();
        SensitiveParamRedactor::configureSensitivePaths([]);
    }

    protected function tearDown(): void
    {
        SensitiveParamRedactor::configureSensitivePaths([]);
        parent::tearDown();
    }

    /**
     * Flatten a captured record (message + structured context) into one string so
     * the assertion cannot be satisfied by moving the leak between the two.
     *
     * @param array{level: mixed, message: string, context: array<string, mixed>} $record
     */
    private function flatten(array $record): string
    {
        return $record['message'] . ' ' . (string) json_encode($record['context']);
    }

    /**
     * Build a request straight from server variables. Request::create() parses its
     * URI argument with parse_url(), which reads '//checkout/...' as a HOST — the
     * very mis-parse this suite exists to pin — so the raw request line is set here
     * the way a web server would set it.
     */
    private function requestFor(string $requestUri, string $method = 'GET', string $baseUrl = ''): Request
    {
        $script = $baseUrl . '/index.php';
        $mark = strpos($requestUri, '?');

        return new Request([], [], [], [], [], [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $requestUri,
            'QUERY_STRING' => $mark === false ? '' : substr($requestUri, $mark + 1),
            'SCRIPT_NAME' => $script,
            'SCRIPT_FILENAME' => '/var/www' . $script,
            'HTTP_HOST' => 'shop.test',
            'REMOTE_ADDR' => '127.0.0.1',
        ]);
    }

    private function capturingLogger(): LoggerInterface
    {
        return new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
            public array $records = [];

            /**
             * @param mixed $level
             * @param array<string, mixed> $context
             */
            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };
    }

    private function application(LoggerInterface $logger): Application
    {
        $context = new ApplicationContext(sys_get_temp_dir() . '/path_redaction_' . uniqid());

        $router = new class {
            public function dispatch(Request $request): Response
            {
                return new Response('ok', 200);
            }
        };

        $container = new class ($context, $logger, $router) implements ContainerInterface {
            /** @var array<string, mixed> */
            private array $services = [];

            public function __construct(ApplicationContext $context, LoggerInterface $logger, object $router)
            {
                $this->services[ApplicationContext::class] = $context;
                $this->services[LoggerInterface::class] = $logger;
                $this->services[\Glueful\Routing\Router::class] = $router;
            }

            public function get(string $id): mixed
            {
                return $this->services[$id] ?? null;
            }

            public function has(string $id): bool
            {
                return array_key_exists($id, $this->services);
            }
        };

        $context->setContainer($container);

        return new Application($context);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function pathRepresentations(): array
    {
        return [
            'decoded' => ['/checkout/pay/' . self::TOKEN],
            'encoded' => ['/checkout/pay/' . rawurlencode(self::TOKEN)],
            // Forms that reach the same live route through Router::match()'s
            // normalization but defeat a naive raw-path splitter.
            'collapsed slashes' => ['//checkout/pay/' . self::TOKEN],
            'encoded separator' => ['/checkout%2Fpay/' . self::TOKEN],
        ];
    }

    /**
     * @dataProvider pathRepresentations
     */
    public function testRequestLoggerNeverEmitsTheSensitivePathSegment(string $path): void
    {
        SensitiveParamRedactor::configureSensitivePaths(['/checkout/pay/{token}']);

        $logger = $this->capturingLogger();
        $app = $this->application($logger);

        $app->handle($this->requestFor($path));

        /** @var array<int, array{level: mixed, message: string, context: array<string, mixed>}> $records */
        $records = $logger->records; // @phpstan-ignore-line anonymous class property
        self::assertNotSame([], $records, 'the request logger must have emitted a record');

        foreach ($records as $record) {
            $flat = $this->flatten($record);
            self::assertStringNotContainsString(self::TOKEN, $flat);
            self::assertStringNotContainsString(rawurlencode(self::TOKEN), $flat);
        }

        self::assertSame(
            '/checkout/pay/' . SensitiveParamRedactor::REDACTED,
            $records[0]['context']['uri']
        );
    }

    public function testRequestLoggerLeavesNonMatchingPathsUnchanged(): void
    {
        SensitiveParamRedactor::configureSensitivePaths(['/checkout/pay/{token}']);

        $logger = $this->capturingLogger();
        $app = $this->application($logger);

        $app->handle($this->requestFor('/orders/42'));

        /** @var array<int, array{level: mixed, message: string, context: array<string, mixed>}> $records */
        $records = $logger->records; // @phpstan-ignore-line anonymous class property
        self::assertSame('/orders/42', $records[0]['context']['uri']);
    }

    public function testRequestLoggerIsByteIdenticalWithNoPatternsRegistered(): void
    {
        $logger = $this->capturingLogger();
        $app = $this->application($logger);

        $path = '/checkout/pay/' . self::TOKEN;
        $app->handle($this->requestFor($path));

        /** @var array<int, array{level: mixed, message: string, context: array<string, mixed>}> $records */
        $records = $logger->records; // @phpstan-ignore-line anonymous class property
        self::assertSame($path, $records[0]['context']['uri']);
    }

    /**
     * @dataProvider pathRepresentations
     */
    public function testExceptionReportNeverEmitsTheSensitivePathSegment(string $path): void
    {
        SensitiveParamRedactor::configureSensitivePaths(['/checkout/pay/{token}']);

        $logger = $this->capturingLogger();
        // debug: false — the production profile, where this call site logs at error level.
        $handler = new Handler($logger, debug: false);

        $handler->report(
            new \RuntimeException('payment gateway unreachable'),
            $this->requestFor($path . '?ref=1')
        );

        /** @var array<int, array{level: mixed, message: string, context: array<string, mixed>}> $records */
        $records = $logger->records; // @phpstan-ignore-line anonymous class property
        self::assertNotSame([], $records, 'the exception handler must have emitted a record');

        foreach ($records as $record) {
            self::assertStringNotContainsString(self::TOKEN, $this->flatten($record));
        }

        $request = $records[0]['context']['request'];
        self::assertIsArray($request);
        self::assertStringStartsWith(
            '/checkout/pay/' . SensitiveParamRedactor::REDACTED,
            (string) $request['uri']
        );
    }

    public function testExceptionReportLightweightContextIsAlsoRedacted(): void
    {
        SensitiveParamRedactor::configureSensitivePaths(['/checkout/pay/{token}']);

        $logger = $this->capturingLogger();
        $handler = new Handler($logger, debug: false);

        // NotFoundException takes the lightweight-context branch; report() directly
        // bypasses the shouldReport() suppression so the branch is exercised.
        $handler->report(
            new \Glueful\Http\Exceptions\Client\NotFoundException('no such link'),
            $this->requestFor('/checkout/pay/' . self::TOKEN)
        );

        /** @var array<int, array{level: mixed, message: string, context: array<string, mixed>}> $records */
        $records = $logger->records; // @phpstan-ignore-line anonymous class property
        self::assertNotSame([], $records);

        foreach ($records as $record) {
            self::assertStringNotContainsString(self::TOKEN, $this->flatten($record));
        }
    }

    public function testExceptionReportIsByteIdenticalWithNoPatternsRegistered(): void
    {
        $logger = $this->capturingLogger();
        $handler = new Handler($logger, debug: false);

        $handler->report(new \RuntimeException('boom'), $this->requestFor('/checkout/pay/' . self::TOKEN));

        /** @var array<int, array{level: mixed, message: string, context: array<string, mixed>}> $records */
        $records = $logger->records; // @phpstan-ignore-line anonymous class property
        $request = $records[0]['context']['request'];
        self::assertIsArray($request);
        self::assertSame('/checkout/pay/' . self::TOKEN, $request['uri']);
    }

    public function testShippedLoggingConfigExposesAnEmptySensitivePathsDefault(): void
    {
        $config = require dirname(__DIR__, 3) . '/config/logging.php';

        self::assertIsArray($config);
        self::assertArrayHasKey('sensitive_paths', $config);
        self::assertSame([], $config['sensitive_paths']);
    }

    /**
     * A base-URL-mounted app registers ONE template. Application::handle() feeds
     * getPathInfo() (base URL already stripped) while Handler::report() feeds
     * getRequestUri() (base URL still attached) — the error-level sink that fires
     * in every profile. Both must be covered by that single registration.
     */
    public function testExceptionReportRedactsUnderABaseUrl(): void
    {
        SensitiveParamRedactor::configureSensitivePaths(['/checkout/pay/{token}']);

        $request = $this->requestFor('/api/checkout/pay/' . self::TOKEN, 'GET', '/api');
        self::assertSame('/api', $request->getBaseUrl(), 'precondition: the request is base-URL mounted');
        self::assertSame('/checkout/pay/' . self::TOKEN, $request->getPathInfo());

        $logger = $this->capturingLogger();
        (new Handler($logger, debug: false))->report(new \RuntimeException('boom'), $request);

        /** @var array<int, array{level: mixed, message: string, context: array<string, mixed>}> $records */
        $records = $logger->records; // @phpstan-ignore-line anonymous class property
        foreach ($records as $record) {
            self::assertStringNotContainsString(self::TOKEN, $this->flatten($record));
        }

        $context = $records[0]['context']['request'];
        self::assertIsArray($context);
        self::assertSame('/api/checkout/pay/' . SensitiveParamRedactor::REDACTED, $context['uri']);
    }

    // --- Residual sinks -----------------------------------------------------------------
    //
    // One representative per middleware family that logs (or persists) a raw request
    // path. The architecture guard below covers the whole set, including future sites.

    public function testCsrfMiddlewareErrorLogIsRedacted(): void
    {
        SensitiveParamRedactor::configureSensitivePaths(['/checkout/pay/{token}']);

        $logger = $this->capturingLogger();
        $middleware = new \Glueful\Routing\Middleware\CSRFMiddleware(
            validateOrigin: false,
            logger: $logger
        );

        try {
            $middleware->handle(
                $this->requestFor('/checkout/pay/' . self::TOKEN, 'POST'),
                static fn(): Response => new Response('ok', 200)
            );
        } catch (\Throwable) {
            // The rejection itself is not under test; the log record it emits is.
        }

        /** @var array<int, array{level: mixed, message: string, context: array<string, mixed>}> $records */
        $records = $logger->records; // @phpstan-ignore-line anonymous class property
        self::assertNotSame([], $records, 'a failing POST must have been logged');

        foreach ($records as $record) {
            self::assertStringNotContainsString(self::TOKEN, $this->flatten($record));
        }

        $paths = array_column(array_column($records, 'context'), 'path');
        self::assertContains(
            '/checkout/pay/' . SensitiveParamRedactor::REDACTED,
            $paths,
            'the error-level CSRF rejection must log the redacted path'
        );
    }

    public function testMetricsMiddlewarePersistsARedactedEndpoint(): void
    {
        SensitiveParamRedactor::configureSensitivePaths(['/checkout/pay/{token}']);

        $recorded = [];
        $metrics = new class ($recorded) extends \Glueful\Services\ApiMetricsService {
            /** @param array<int, array<string, mixed>> $recorded */
            public function __construct(private array &$recorded)
            {
            }

            /** @param array<string, mixed> $metric */
            public function recordMetricAsync(array $metric): void
            {
                $this->recorded[] = $metric;
            }
        };

        (new \Glueful\Routing\Middleware\MetricsMiddleware($metrics))->handle(
            $this->requestFor('/checkout/pay/' . self::TOKEN),
            static fn(): Response => new Response('ok', 200)
        );

        self::assertCount(1, $recorded);
        self::assertSame('/checkout/pay/' . SensitiveParamRedactor::REDACTED, $recorded[0]['endpoint']);
    }

    public function testTracingMiddlewareSpanAttributesAreRedacted(): void
    {
        SensitiveParamRedactor::configureSensitivePaths(['/checkout/pay/{token}']);

        $attributes = [];
        $tracer = new class ($attributes) implements \Glueful\Observability\Tracing\TracerInterface {
            /** @param array<string, mixed> $attributes */
            public function __construct(private array &$attributes)
            {
            }

            /** @param array<string, mixed> $attrs */
            public function startSpan(
                string $name,
                array $attrs = []
            ): \Glueful\Observability\Tracing\SpanBuilderInterface {
                $this->attributes = $attrs;

                return new class implements \Glueful\Observability\Tracing\SpanBuilderInterface {
                    public function setAttribute(string $key, mixed $value): self
                    {
                        return $this;
                    }

                    public function setParent(?\Glueful\Observability\Tracing\SpanInterface $parent): self
                    {
                        return $this;
                    }

                    public function startSpan(): \Glueful\Observability\Tracing\SpanInterface
                    {
                        return new class implements \Glueful\Observability\Tracing\SpanInterface {
                            public function setAttribute(string $key, mixed $value): void
                            {
                            }

                            public function end(): void
                            {
                            }
                        };
                    }
                };
            }
        };

        (new \Glueful\Routing\Middleware\TracingMiddleware($tracer))->handle(
            $this->requestFor('/checkout/pay/' . self::TOKEN),
            static fn(): Response => new Response('ok', 200)
        );

        self::assertSame('/checkout/pay/' . SensitiveParamRedactor::REDACTED, $attributes['http.route']);
    }

    public function testVersionManagerDebugLogIsRedacted(): void
    {
        SensitiveParamRedactor::configureSensitivePaths(['/checkout/pay/{token}']);

        $logger = $this->capturingLogger();
        $manager = new \Glueful\Api\Versioning\VersionManager(
            \Glueful\Api\Versioning\ApiVersion::default(),
            false,
            $logger
        );

        $manager->negotiate($this->requestFor('/checkout/pay/' . self::TOKEN));

        /** @var array<int, array{level: mixed, message: string, context: array<string, mixed>}> $records */
        $records = $logger->records; // @phpstan-ignore-line anonymous class property
        self::assertNotSame([], $records);

        foreach ($records as $record) {
            self::assertStringNotContainsString(self::TOKEN, $this->flatten($record));
        }
    }

    /**
     * Guard for the whole sweep: anywhere in src/ that puts a request path into an
     * array literal — a log context, a span attribute, a persisted metric — must
     * route it through the redactor. Catches future call sites, not just today's.
     */
    public function testNoSourceFilePutsARawRequestPathIntoAnArrayPayload(): void
    {
        $src = dirname(__DIR__, 3) . '/src';
        $allowed = [
            // Rate-limit bucket keys, not a log sink: redacting here would collapse
            // every credentialed path onto one bucket.
            'src/Api/RateLimiting/RateLimitManager.php' => ["'{path}' => \$request->getPathInfo(),"],
        ];

        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src));

        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = 'src' . substr($file->getPathname(), strlen($src));

            foreach (file($file->getPathname()) ?: [] as $number => $line) {
                if (!str_contains($line, '=>')) {
                    continue;
                }
                if (!str_contains($line, 'getPathInfo()') && !str_contains($line, 'getRequestUri()')) {
                    continue;
                }
                if (str_contains($line, 'SensitiveParamRedactor') || str_contains($line, 'sanitize')) {
                    continue;
                }
                if (in_array(trim($line), $allowed[$relative] ?? [], true)) {
                    continue;
                }

                $offenders[] = $relative . ':' . ($number + 1) . ' ' . trim($line);
            }
        }

        self::assertSame([], $offenders, "Raw request path in an array payload:\n" . implode("\n", $offenders));
    }
}

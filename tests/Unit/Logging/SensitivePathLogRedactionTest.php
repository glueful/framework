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
    private const TOKEN = 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b2';

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

        $app->handle(Request::create($path, 'GET'));

        /** @var array<int, array{level: mixed, message: string, context: array<string, mixed>}> $records */
        $records = $logger->records; // @phpstan-ignore-line anonymous class property
        self::assertNotSame([], $records, 'the request logger must have emitted a record');

        foreach ($records as $record) {
            self::assertStringNotContainsString(self::TOKEN, $this->flatten($record));
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

        $app->handle(Request::create('/orders/42', 'GET'));

        /** @var array<int, array{level: mixed, message: string, context: array<string, mixed>}> $records */
        $records = $logger->records; // @phpstan-ignore-line anonymous class property
        self::assertSame('/orders/42', $records[0]['context']['uri']);
    }

    public function testRequestLoggerIsByteIdenticalWithNoPatternsRegistered(): void
    {
        $logger = $this->capturingLogger();
        $app = $this->application($logger);

        $path = '/checkout/pay/' . self::TOKEN;
        $app->handle(Request::create($path, 'GET'));

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

        $handler->report(new \RuntimeException('payment gateway unreachable'), Request::create($path . '?ref=1'));

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
            Request::create('/checkout/pay/' . self::TOKEN)
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

        $handler->report(new \RuntimeException('boom'), Request::create('/checkout/pay/' . self::TOKEN));

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
}

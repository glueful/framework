<?php

declare(strict_types=1);

namespace Glueful\Tests\Core;

use PHPUnit\Framework\TestCase;
use Glueful\Exceptions\ExceptionHandler;
use Glueful\Http\Exceptions\Handler;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

final class ExceptionHandlerTest extends TestCase
{
    private function fakeLogger(): LoggerInterface
    {
        return new class implements LoggerInterface {
            public function emergency(\Stringable|string $message, array $context = []): void
            {
            }

            public function alert(\Stringable|string $message, array $context = []): void
            {
            }

            public function critical(\Stringable|string $message, array $context = []): void
            {
            }

            public function error(\Stringable|string $message, array $context = []): void
            {
            }

            public function warning(\Stringable|string $message, array $context = []): void
            {
            }

            public function notice(\Stringable|string $message, array $context = []): void
            {
            }

            public function info(\Stringable|string $message, array $context = []): void
            {
            }

            public function debug(\Stringable|string $message, array $context = []): void
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
            }
        };
    }

    private function capturingLogger(): LoggerInterface
    {
        return new class implements LoggerInterface {
            /** @var list<array{message:string,context:array<string,mixed>}> */
            public array $errors = [];

            public function emergency(\Stringable|string $message, array $context = []): void
            {
            }

            public function alert(\Stringable|string $message, array $context = []): void
            {
            }

            public function critical(\Stringable|string $message, array $context = []): void
            {
            }

            public function error(\Stringable|string $message, array $context = []): void
            {
                $this->errors[] = [
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }

            public function warning(\Stringable|string $message, array $context = []): void
            {
            }

            public function notice(\Stringable|string $message, array $context = []): void
            {
            }

            public function info(\Stringable|string $message, array $context = []): void
            {
            }

            public function debug(\Stringable|string $message, array $context = []): void
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
            }
        };
    }

    public function testJsonErrorResponseCapturedInTestMode(): void
    {
        $handler = new Handler($this->fakeLogger(), debug: false);
        ExceptionHandler::setHandler($handler);
        ExceptionHandler::setTestMode(true);

        ExceptionHandler::handleException(new \RuntimeException('boom'));
        $resp = ExceptionHandler::getTestResponse();

        $this->assertIsArray($resp);
        $this->assertFalse($resp['success']);
        $this->assertSame(500, $resp['code']);
    }

    public function testReportRedactsSensitiveRequestUriAndQueryString(): void
    {
        $logger = $this->capturingLogger();
        $handler = new Handler($logger, debug: false);
        $request = Request::create(
            '/callback?token=query-token&safe=ok&signature=sig&code=oauth-code&api_key=query-key',
            'GET'
        );

        $handler->report(new \RuntimeException('boom'), $request);

        $this->assertCount(1, $logger->errors);
        $requestContext = $logger->errors[0]['context']['request'];
        $this->assertIsArray($requestContext);
        $this->assertSame(
            '/callback?token=%5BREDACTED%5D&safe=ok&signature=%5BREDACTED%5D&code=%5BREDACTED%5D&api_key=%5BREDACTED%5D',
            $requestContext['uri']
        );
        $this->assertSame(
            'api_key=%5BREDACTED%5D&code=%5BREDACTED%5D&safe=ok&signature=%5BREDACTED%5D&token=%5BREDACTED%5D',
            $requestContext['query_string']
        );

        $serialized = json_encode($logger->errors[0]['context'], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('query-token', $serialized);
        $this->assertStringNotContainsString('oauth-code', $serialized);
        $this->assertStringNotContainsString('query-key', $serialized);
    }
}

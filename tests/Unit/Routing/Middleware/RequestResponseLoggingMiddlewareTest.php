<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Routing\Middleware;

use Glueful\Routing\Middleware\RequestResponseLoggingMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequestResponseLoggingMiddlewareTest extends TestCase
{
    public function testRequestLoggingRedactsSensitiveQueryRefererAndFormBodyValues(): void
    {
        $logger = new CapturingLogger();
        $middleware = new RequestResponseLoggingMiddleware(
            logMode: 'request',
            logHeaders: false,
            logBodies: true,
            logger: $logger
        );

        $request = Request::create(
            '/download?token=query-token&safe=ok&signature=sig&code=oauth-code&api_key=query-key',
            'POST',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
                'HTTP_REFERER' => 'https://idp.example/callback?code=referer-code&state=ok&token=referer-token',
            ],
            'password=secret-pass&name=alice&nested%5Bapi_key%5D=form-key'
        );

        $middleware->handle($request, static fn(): Response => new Response('ok'));

        self::assertCount(1, $logger->records);
        $context = $logger->records[0]['context'];

        self::assertSame('/download?token=%5BREDACTED%5D&safe=ok&signature=%5BREDACTED%5D&code=%5BREDACTED%5D&api_key=%5BREDACTED%5D', $context['uri']);
        self::assertSame('api_key=%5BREDACTED%5D&code=%5BREDACTED%5D&safe=ok&signature=%5BREDACTED%5D&token=%5BREDACTED%5D', $context['query_string']);
        self::assertSame('https://idp.example/callback?code=%5BREDACTED%5D&state=ok&token=%5BREDACTED%5D', $context['referer']);
        self::assertSame('password=%5BREDACTED%5D&name=alice&nested%5Bapi_key%5D=%5BREDACTED%5D', $context['body']);

        $serialized = json_encode($context, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('query-token', $serialized);
        self::assertStringNotContainsString('oauth-code', $serialized);
        self::assertStringNotContainsString('query-key', $serialized);
        self::assertStringNotContainsString('referer-code', $serialized);
        self::assertStringNotContainsString('referer-token', $serialized);
        self::assertStringNotContainsString('secret-pass', $serialized);
        self::assertStringNotContainsString('form-key', $serialized);
    }
}

final class CapturingLogger implements LoggerInterface
{
    /** @var list<array{level:mixed,message:string,context:array<string,mixed>}> */
    public array $records = [];

    public function emergency($message, array $context = []): void
    {
        $this->log('emergency', (string) $message, $context);
    }

    public function alert($message, array $context = []): void
    {
        $this->log('alert', (string) $message, $context);
    }

    public function critical($message, array $context = []): void
    {
        $this->log('critical', (string) $message, $context);
    }

    public function error($message, array $context = []): void
    {
        $this->log('error', (string) $message, $context);
    }

    public function warning($message, array $context = []): void
    {
        $this->log('warning', (string) $message, $context);
    }

    public function notice($message, array $context = []): void
    {
        $this->log('notice', (string) $message, $context);
    }

    public function info($message, array $context = []): void
    {
        $this->log('info', (string) $message, $context);
    }

    public function debug($message, array $context = []): void
    {
        $this->log('debug', (string) $message, $context);
    }

    public function log($level, $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}

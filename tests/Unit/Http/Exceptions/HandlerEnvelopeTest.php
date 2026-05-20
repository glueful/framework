<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Http\Exceptions;

use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Http\Exceptions\Handler;
use Glueful\Permissions\Exceptions\UnauthorizedException as PermissionUnauthorizedException;
use PHPUnit\Framework\TestCase;

final class HandlerEnvelopeTest extends TestCase
{
    public function testNotFoundReturnsUnifiedEnvelope(): void
    {
        $handler = new Handler(debug: false);
        $response = $handler->render(new NotFoundException('user not found'));
        $body = json_decode((string) $response->getContent(), true);

        self::assertSame(404, $response->getStatusCode());
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame('user not found', $body['message']);
        self::assertIsArray($body['error']);
        self::assertSame(404, $body['error']['code']);
        self::assertSame('NOT_FOUND', $body['error']['error_code']);
        self::assertArrayHasKey('timestamp', $body['error']);
        self::assertArrayHasKey('request_id', $body['error']);
    }

    public function testPermissionDeniedReturnsSameEnvelope(): void
    {
        $handler = new Handler(debug: false);
        $response = $handler->render(new PermissionUnauthorizedException(
            'user-uuid',
            'permission.required',
            'resource',
            'nope'
        ));
        $body = json_decode((string) $response->getContent(), true);

        self::assertSame(403, $response->getStatusCode());
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame('nope', $body['message']);
        self::assertIsArray($body['error']);
        self::assertSame(403, $body['error']['code']);
        self::assertSame('FORBIDDEN', $body['error']['error_code']);
        self::assertArrayHasKey('timestamp', $body['error']);
        self::assertArrayHasKey('request_id', $body['error']);
    }

    public function testUnknownStatusCodeReturnsStringifiedCodeAsErrorCode(): void
    {
        $handler = new Handler(debug: false);
        $exception = new \RuntimeException('teapot', 418);
        $response = $handler->render($exception);
        $body = json_decode((string) $response->getContent(), true);

        self::assertSame(418, $response->getStatusCode());
        self::assertIsArray($body);
        self::assertSame('418', $body['error']['error_code']);
    }
}

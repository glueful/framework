<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Routing\Middleware;

use Glueful\Http\Exceptions\Handler;
use Glueful\Routing\Middleware\ValidationMiddleware;
use Glueful\Validation\ValidationException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** A ValidationException's own message reaches the response; field errors stay unchanged. */
final class ValidationMiddlewareMessageTest extends TestCase
{
    /** @return array<string, mixed> */
    private function renderThroughMiddleware(ValidationException $e): array
    {
        $response = (new ValidationMiddleware())->handle(
            Request::create('/x', 'POST'),
            static fn(): Response => throw $e
        );
        self::assertInstanceOf(Response::class, $response);
        self::assertSame(422, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertIsArray($body);
        return $body;
    }

    public function testMiddlewareRendersAnExplicitlyGivenMessage(): void
    {
        $body = $this->renderThroughMiddleware(
            new ValidationException(['email' => ['Taken.']], [], 'That email is already registered.')
        );

        self::assertSame('That email is already registered.', $body['message']);
        self::assertSame(['email' => ['Taken.']], $body['errors']);
    }

    public function testMiddlewareKeepsTheGenericMessageWhenNoneWasGiven(): void
    {
        $body = $this->renderThroughMiddleware(ValidationException::forField('email', 'Required.'));

        self::assertSame('Validation failed', $body['message']);
        self::assertSame(['email' => ['Required.']], $body['errors']);
    }

    public function testHandlerRendersAnExplicitlyGivenMessage(): void
    {
        $response = (new Handler(debug: false))->render(
            new ValidationException(['email' => ['Taken.']], [], 'That email is already registered.')
        );
        $body = json_decode((string) $response->getContent(), true);

        self::assertSame(422, $response->getStatusCode());
        self::assertIsArray($body);
        self::assertSame('That email is already registered.', $body['message']);
        self::assertSame(['email' => ['Taken.']], $body['errors']);
    }
}

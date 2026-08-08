<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Http\Exceptions;

use Glueful\Database\Exceptions\DatabaseException as TypedDatabaseException;
use Glueful\Database\Exceptions\UniqueConstraintViolationException;
use Glueful\Http\Exceptions\Handler;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HandlerDatabaseExceptionsTest extends TestCase
{
    private function uniqueViolation(): UniqueConstraintViolationException
    {
        $raw = new \PDOException(
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'a@b.c' for key 'users.email'"
        );
        $raw->errorInfo = ['23000', 1062, "Duplicate entry 'a@b.c' for key 'users.email'"];

        return UniqueConstraintViolationException::fromPdo($raw, 'mysql');
    }

    private function genericTyped(): TypedDatabaseException
    {
        $raw = new \PDOException('SQLSTATE[HY000]: General error: 9999 exotic driver failure');
        $raw->errorInfo = ['HY000', 9999, 'exotic driver failure'];

        return TypedDatabaseException::fromPdo($raw, 'mysql');
    }

    #[Test]
    public function uniqueViolationRendersFixed409InNonDebugMode(): void
    {
        $handler = new Handler(debug: false);
        $response = $handler->render($this->uniqueViolation());
        $body = json_decode((string) $response->getContent(), true);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertIsArray($body);
        $this->assertFalse($body['success']);
        $this->assertSame('A conflicting record already exists.', $body['message']);
        $this->assertSame(409, $body['error']['code']);
    }

    #[Test]
    public function uniqueViolationMessageStaysFixedInDebugMode(): void
    {
        $handler = new Handler(debug: true);
        $response = $handler->render($this->uniqueViolation());
        $body = json_decode((string) $response->getContent(), true);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertIsArray($body);
        $this->assertSame('A conflicting record already exists.', $body['message']);
        $this->assertStringNotContainsString('Duplicate entry', (string) $response->getContent());
    }

    #[Test]
    public function uniqueViolationIsNotReported(): void
    {
        $handler = new Handler(debug: false);

        $this->assertFalse($handler->shouldReport($this->uniqueViolation()));
    }

    #[Test]
    public function genericTypedDatabaseExceptionKeepsSanitized500(): void
    {
        $handler = new Handler(debug: false);
        $response = $handler->render($this->genericTyped());
        $body = json_decode((string) $response->getContent(), true);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertIsArray($body);
        $this->assertStringNotContainsString('exotic driver failure', (string) $response->getContent());
        $this->assertTrue($handler->shouldReport($this->genericTyped()));
    }

    #[Test]
    public function defaultSqliteConstraintViolationStaysSanitized500(): void
    {
        // Default SQLite config cannot distinguish constraint kinds (code 19),
        // so the classifier yields the generic parent — which must NOT get the
        // 409 treatment reserved for specifically-classified unique violations.
        $raw = new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed');
        $raw->errorInfo = ['23000', 19, 'UNIQUE constraint failed: users.email'];
        $ambiguous = \Glueful\Database\Exceptions\ConstraintViolationException::fromPdo($raw, 'sqlite');

        $handler = new Handler(debug: false);
        $response = $handler->render($ambiguous);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringNotContainsString('users.email', (string) $response->getContent());
    }

    #[Test]
    public function typedDatabaseExceptionsRouteToDatabaseChannelDespiteFrameworkOrigin(): void
    {
        // fromPdo() instantiates inside framework src/, so isFrameworkException()
        // is true for these — the channel check must run FIRST or they would
        // all route to 'framework'.
        $handler = new class (debug: false) extends Handler {
            public function channelFor(\Throwable $e): string
            {
                return $this->resolveLogChannel($e);
            }
        };

        $this->assertSame('database', $handler->channelFor($this->genericTyped()));
        $this->assertSame('database', $handler->channelFor($this->uniqueViolation()));
    }
}

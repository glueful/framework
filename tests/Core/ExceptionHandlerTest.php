<?php

declare(strict_types=1);

namespace Glueful\Tests\Core;

use PHPUnit\Framework\TestCase;
use Glueful\Exceptions\ExceptionHandler;
use Psr\Log\LoggerInterface;

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

    public function testJsonErrorResponseCapturedInTestMode(): void
    {
        ExceptionHandler::setTestMode(true);
        ExceptionHandler::setLogger($this->fakeLogger());

        ExceptionHandler::handleException(new \RuntimeException('boom'));
        $resp = ExceptionHandler::getTestResponse();

        $this->assertIsArray($resp);
        $this->assertFalse($resp['success']);
        $this->assertSame(500, $resp['code']);
    }
}

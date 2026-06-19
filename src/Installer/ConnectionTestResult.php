<?php

declare(strict_types=1);

namespace Glueful\Installer;

final class ConnectionTestResult
{
    public function __construct(
        public readonly string $engine,
        public readonly bool $ok,
        public readonly string $message,
        public readonly ?string $exceptionClass = null,
        public readonly ?string $sqlState = null,
    ) {
    }
}

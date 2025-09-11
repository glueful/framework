<?php

declare(strict_types=1);

namespace Glueful\Observability\Tracing;

interface SpanInterface
{
    public function setAttribute(string $key, mixed $value): void;
    public function end(): void;
}

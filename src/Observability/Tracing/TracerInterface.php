<?php

declare(strict_types=1);

namespace Glueful\Observability\Tracing;

interface TracerInterface
{
    /**
     * @param array<string, mixed> $attrs
     */
    public function startSpan(string $name, array $attrs = []): SpanBuilderInterface;
}

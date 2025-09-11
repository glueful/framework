<?php

declare(strict_types=1);

namespace Glueful\Observability\Tracing;

class NoopTracer implements TracerInterface
{
    /**
     * @param array<string, mixed> $attrs
     */
    public function startSpan(string $name, array $attrs = []): SpanBuilderInterface
    {
        return new NoopSpanBuilder($attrs);
    }
}

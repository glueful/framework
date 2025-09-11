<?php

declare(strict_types=1);

namespace Glueful\Observability\Tracing;

class NoopSpanBuilder implements SpanBuilderInterface
{
    /**
     * @param array<string, mixed> $attrs
     */
    public function __construct(private array $attrs = [])
    {
    }

    public function setAttribute(string $key, mixed $value): self
    {
        return $this;
    }

    public function setParent(?SpanInterface $parent): self
    {
        return $this;
    }

    public function startSpan(): SpanInterface
    {
        return new NoopSpan();
    }
}

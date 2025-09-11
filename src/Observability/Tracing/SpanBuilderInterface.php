<?php

declare(strict_types=1);

namespace Glueful\Observability\Tracing;

interface SpanBuilderInterface
{
    public function setAttribute(string $key, mixed $value): self;
    public function setParent(?SpanInterface $parent): self;
    public function startSpan(): SpanInterface;
}

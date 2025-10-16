<?php

declare(strict_types=1);

namespace Glueful\Async\Contracts;

interface CancellationToken
{
    public function isCancelled(): bool;
    public function throwIfCancelled(): void;
}

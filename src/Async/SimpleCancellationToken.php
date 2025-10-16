<?php

declare(strict_types=1);

namespace Glueful\Async;

use Glueful\Async\Contracts\CancellationToken;

final class SimpleCancellationToken implements CancellationToken
{
    private bool $cancelled = false;

    public function cancel(): void
    {
        $this->cancelled = true;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    public function throwIfCancelled(): void
    {
        if ($this->cancelled) {
            throw new \RuntimeException('Operation cancelled');
        }
    }
}

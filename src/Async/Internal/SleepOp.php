<?php

declare(strict_types=1);

namespace Glueful\Async\Internal;

use Glueful\Async\Contracts\CancellationToken;

final class SleepOp
{
    public float $wakeAt;
    public ?CancellationToken $token;

    public function __construct(float $wakeAt, ?CancellationToken $token = null)
    {
        $this->wakeAt = $wakeAt;
        $this->token = $token;
    }
}


<?php

declare(strict_types=1);

namespace Glueful\Async\Internal;

use Glueful\Async\Contracts\CancellationToken;

final class WriteOp
{
    /** @var resource */
    public $stream;
    public ?float $deadline;
    public ?CancellationToken $token;

    /**
     * @param resource $stream
     */
    public function __construct($stream, ?float $deadline = null, ?CancellationToken $token = null)
    {
        $this->stream = $stream;
        $this->deadline = $deadline;
        $this->token = $token;
    }
}


<?php

declare(strict_types=1);

namespace Glueful\Extensions\Install;

/** The host cannot perform the write-heavy install (read-only FS / no composer). Maps to HTTP 409. */
final class HostNotWritableException extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly string $detail)
    {
        parent::__construct("Host not writable ({$reason}): {$detail}");
    }
}

<?php

declare(strict_types=1);

namespace Glueful\Routing\Attributes;

/**
 * Declares the HTTP status code used when enveloping a controller method's
 * {@see \Glueful\Http\Contracts\ResponseData} return value.
 *
 * Fails loud for a non-2xx status: this is developer-authored metadata, so a
 * broken `#[ResponseStatus(404)]` / `#[ResponseStatus(999)]` must surface at
 * load time rather than be silently accepted.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class ResponseStatus
{
    public function __construct(public readonly int $status)
    {
        if ($status < 200 || $status > 299) {
            throw new \InvalidArgumentException(
                "#[ResponseStatus] must be a 2xx success status; got {$status}."
            );
        }
    }
}

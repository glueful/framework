<?php

declare(strict_types=1);

namespace Glueful\Http\Exceptions;

/**
 * HTTP Client Exception
 *
 * Exception thrown by the HTTP client when a request fails.
 * Extends the framework's HttpException for compatibility with the exception handler.
 */
class HttpClientException extends HttpException
{
    public function __construct(string $message = '', int $code = 0)
    {
        parent::__construct($code, $message);
    }
}

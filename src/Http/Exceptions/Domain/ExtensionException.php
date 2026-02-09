<?php

declare(strict_types=1);

namespace Glueful\Http\Exceptions\Domain;

use Glueful\Http\Exceptions\HttpException;

/**
 * Extension Exception
 *
 * Thrown for extension-related errors including loading failures,
 * compatibility issues, installation problems, and configuration errors.
 *
 * @example
 * throw new ExtensionException('Extension "my-ext" failed to load', 500);
 */
class ExtensionException extends HttpException
{
    /**
     * Create a new extension exception
     *
     * @param string $message Error message
     * @param int $statusCode HTTP status code
     * @param array<string, mixed>|null $context Additional error context
     */
    public function __construct(string $message, int $statusCode = 400, ?array $context = null)
    {
        parent::__construct($statusCode, $message);
        $this->context = $context;
    }
}

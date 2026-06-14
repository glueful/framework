<?php

declare(strict_types=1);

namespace Glueful\Routing\Attributes;

use Attribute;
use InvalidArgumentException;

/**
 * Document a response body for a route, by status code, using a typed DTO class.
 *
 * Repeatable so a single handler can declare several statuses (200, 404, …). The
 * code-first {@see \Glueful\Support\Documentation\RouteReflectionDocGenerator}
 * reflects each instance into an OpenAPI response object, deriving the body
 * schema from the typed `$schema` class via {@see \Glueful\Support\Documentation\ClassSchemaReflector}.
 *
 * When a response cannot be modelled by a DTO — a file download, an HTML page, an
 * opaque blob — leave `$schema` null and set the constrained `$body` escape hatch
 * to `'binary'` (`{type: string, format: binary}`), `'text'` (`{type: string}`)
 * or `'object'` (`{type: object}`). `$body` is honoured ONLY when `$schema` is
 * null; if both are set the DTO class schema wins and `$body` is ignored.
 *
 * @example
 * #[ApiResponse(200, UserData::class)]                       // envelope-wrapped single object
 * #[ApiResponse(200, UserData::class, collection: true)]     // envelope-wrapped list
 * #[ApiResponse(201, UserData::class, envelope: false)]      // raw object, no envelope
 * #[ApiResponse(404, description: 'User not found')]         // description-only, no body
 * #[ApiResponse(200, contentType: 'application/octet-stream', body: 'binary')] // file download
 * #[ApiResponse(200, contentType: 'text/html', body: 'text')]                  // HTML page
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class ApiResponse
{
    /**
     * @param int               $status      HTTP status code (e.g. 200, 201, 404).
     * @param class-string|null $schema      Typed DTO class describing the body — or, when
     *                                       $envelope is true, the `data` payload. Null = description only.
     * @param string            $description Human-readable response description.
     * @param bool              $collection  Wrap $schema as an array (a list of items).
     * @param bool              $envelope    Wrap in Glueful's success envelope {success, message, data}.
     * @param string            $contentType Response media type.
     * @param 'binary'|'text'|'object'|null $body Constrained non-JSON body kind for responses a DTO
     *                                       cannot model. Used ONLY when $schema is null. One of
     *                                       'binary', 'text', 'object'.
     */
    public function __construct(
        public readonly int $status,
        public readonly ?string $schema = null,
        public readonly string $description = '',
        public readonly bool $collection = false,
        public readonly bool $envelope = true,
        public readonly string $contentType = 'application/json',
        public readonly ?string $body = null,
    ) {
        if ($body !== null && !in_array($body, ['binary', 'text', 'object'], true)) {
            throw new InvalidArgumentException(
                "Invalid #[ApiResponse] body '{$body}': expected one of 'binary', 'text', 'object'."
            );
        }
    }
}

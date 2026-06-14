<?php

declare(strict_types=1);

namespace Glueful\Routing\Attributes;

use Attribute;

/**
 * Document a response body for a route, by status code, using a typed DTO class.
 *
 * Repeatable so a single handler can declare several statuses (200, 404, …). The
 * code-first {@see \Glueful\Support\Documentation\RouteReflectionDocGenerator}
 * reflects each instance into an OpenAPI response object, deriving the body
 * schema from the typed `$schema` class via {@see \Glueful\Support\Documentation\ClassSchemaReflector}.
 *
 * @example
 * #[ApiResponse(200, UserData::class)]                       // envelope-wrapped single object
 * #[ApiResponse(200, UserData::class, collection: true)]     // envelope-wrapped list
 * #[ApiResponse(201, UserData::class, envelope: false)]      // raw object, no envelope
 * #[ApiResponse(404, description: 'User not found')]         // description-only, no body
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
     */
    public function __construct(
        public readonly int $status,
        public readonly ?string $schema = null,
        public readonly string $description = '',
        public readonly bool $collection = false,
        public readonly bool $envelope = true,
        public readonly string $contentType = 'application/json',
    ) {
    }
}

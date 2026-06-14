<?php

declare(strict_types=1);

namespace Glueful\Http\Contracts;

/**
 * Opt-in companion to {@see ResponseData}: a returned response object that also
 * implements this interface supplies its own envelope `message`, replacing the
 * default ('Success' / 'Created successfully' / 'Data retrieved successfully').
 *
 * Only takes effect when the returned value is enveloped by the router — i.e. a
 * {@see ResponseData}, {@see \Glueful\Http\Responses\CollectionResponse}, or
 * {@see \Glueful\Http\Responses\PaginatedResponse}. Implementing it on a plain
 * object/array (returned as a raw JsonResponse) or on a Resource (which owns its
 * own envelope) has NO effect — the message is ignored.
 *
 * Implementing classes typically store the message in a PRIVATE property so it is
 * not serialized into the `data` payload by {@see \Glueful\Serialization\ResponseDataSerializer}
 * (which reflects public properties only). NOTE: a DTO that uses the serializer's
 * `toArray()` escape hatch bypasses that visibility guard — its `toArray()` must
 * not include the message field, or it will leak into `data`.
 */
interface HasResponseMessage
{
    public function responseMessage(): string;
}

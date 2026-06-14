<?php

declare(strict_types=1);

namespace Glueful\Http\Responses;

use Glueful\Http\Contracts\ResponseData;

/**
 * A typed list of response items. Returning one from a controller renders the
 * standard success envelope with `data` set to the serialized list:
 * `{success, message, data: [ ... ]}`.
 *
 * Items are typically {@see ResponseData} DTOs — each serialized via
 * {@see \Glueful\Serialization\ResponseDataSerializer}; plain arrays/scalars
 * pass through unchanged. For precise OpenAPI docs, annotate the handler return
 * with the item type: `@return CollectionResponse<PostData>`.
 */
final class CollectionResponse
{
    /** @param list<mixed> $items */
    public function __construct(public readonly array $items)
    {
    }
}

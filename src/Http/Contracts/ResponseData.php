<?php

declare(strict_types=1);

namespace Glueful\Http\Contracts;

/**
 * Marker: a controller method returning a ResponseData is enveloped into the
 * standard response.
 *
 * Parallels {@see \Glueful\Validation\Contracts\RequestData} for input — where
 * RequestData is hydrated/validated from the request body before injection,
 * ResponseData is serialized into the response payload after the method runs.
 */
interface ResponseData
{
}

<?php

declare(strict_types=1);

namespace Glueful\Uploader\Contracts;

/** Generic registration-time seam for adding middleware to framework blob routes. */
interface BlobRouteMiddlewareProvider
{
    /** @return list<string> Middleware aliases in Glueful's `name:params` syntax. */
    public function middlewareFor(BlobRouteAction $action): array;
}

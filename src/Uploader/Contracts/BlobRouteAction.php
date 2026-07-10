<?php

declare(strict_types=1);

namespace Glueful\Uploader\Contracts;

/** Blob endpoints that may receive application-contributed route middleware. */
enum BlobRouteAction: string
{
    case UPLOAD = 'upload';
    case VIEW = 'view';
    case INFO = 'info';
    case DELETE = 'delete';
    case SIGN = 'sign';
}

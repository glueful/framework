<?php

declare(strict_types=1);

namespace Glueful\Uploader\Contracts;

enum BlobAction: string
{
    case VIEW = 'view';
    case INFO = 'info';
    case DELETE = 'delete';
    case SIGN = 'sign';
}

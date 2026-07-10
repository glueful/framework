<?php

declare(strict_types=1);

namespace Glueful\Uploader\Contracts;

final class NullBlobAccessPolicy implements BlobAccessPolicy
{
    public function authorizeAccess(array $blob, BlobAccessContext $context): bool
    {
        return true;
    }
}

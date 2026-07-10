<?php

declare(strict_types=1);

namespace Glueful\Uploader\Contracts;

/** Optional synchronous authorization extension point applied after core blob access checks. */
interface BlobAccessPolicy
{
    /** @param array<string,mixed> $blob */
    public function authorizeAccess(array $blob, BlobAccessContext $context): bool;
}

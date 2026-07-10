<?php

declare(strict_types=1);

namespace Glueful\Uploader\Contracts;

/** Optional post-persistence extension point for blob ownership, indexing, or policy checks. */
interface BlobCreatedHook
{
    /** Throw to reject creation; the upload controller compensates the persisted blob and object. */
    public function onBlobCreated(string $blobUuid, ?string $uploaderUserUuid): void;
}

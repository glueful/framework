<?php

declare(strict_types=1);

namespace Glueful\Uploader\Contracts;

final class NullBlobCreatedHook implements BlobCreatedHook
{
    public function onBlobCreated(string $blobUuid, ?string $uploaderUserUuid): void
    {
    }
}

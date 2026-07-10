<?php

declare(strict_types=1);

namespace Glueful\Uploader\Contracts;

final class BlobAccessContext
{
    public function __construct(
        public readonly BlobAction $action,
        public readonly ?string $authenticatedUserUuid,
        public readonly bool $signatureValid,
    ) {
    }
}

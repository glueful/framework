<?php

declare(strict_types=1);

namespace Glueful\Uploader\Contracts;

/** Supplies an optional canonical origin for blob URLs without owning URL signing. */
interface BlobPublicUrlProvider
{
    /**
     * @param array<string, mixed> $blob
     * @return string|null Scheme and host, or null to retain the request origin.
     */
    public function publicBaseUrl(array $blob): ?string;
}

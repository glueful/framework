<?php

declare(strict_types=1);

namespace Glueful\Storage\Contracts;

interface NativeSignedUrlProviderInterface
{
    /**
     * @param array<string, mixed> $diskConfig
     */
    public function temporaryUrl(string $path, int $ttl, array $diskConfig): ?string;
}
